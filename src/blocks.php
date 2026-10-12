<?php
/**
 * Текст публикации блоками — формат Editor.js, того же редактора, на котором
 * работает vc.ru.
 *
 * Зачем блоки. Старый редактор на contenteditable сохранял то, что нарисовал
 * браузер: заголовок с двумя <br> внутри, цитату внутри <div>, каждый пункт
 * списка в отдельном <ul>. Отступы в такой разметке задаёт число переносов,
 * и один пост от другого отличался «на глаз». В блоках структура жёсткая:
 * абзац, заголовок, список, цитата, фото, разделитель — и у каждого свой
 * фиксированный отступ.
 *
 * Хранится два слоя:
 *   threads.body_json — блоки; из них редактор восстанавливает текст;
 *   threads.body      — HTML, собранный из блоков здесь, на сервере. Его читают
 *                       анонсы в ленте, описания для поисковиков, дайджест.
 *
 * Старые публикации body_json не имеют — их HTML раскладывается на блоки при
 * чтении (blocks_from_html). Базу при этом никто не переписывает.
 *
 * Блоки приходят из браузера, поэтому каждый проходит белый список: известный
 * тип, известные поля, чистый HTML внутри строк. Остальное выбрасывается.
 */

/** Больше блоков в одной публикации не бывает — это уже не пост, а мусор. */
const BLOCKS_MAX = 400;

/** Предел для JSON из редактора: колонка MEDIUMTEXT, но гигабайт нам не нужен. */
const BLOCKS_MAX_BYTES = 400000;

/** Глубина вложенности списков — как в редакторе (maxLevel). */
const BLOCKS_LIST_DEPTH = 2;

/* ============================================================ разбор и чистка */

/**
 * Блоки из JSON редактора. Принимает и полный ответ editor.save()
 * ({"time":…,"blocks":[…]}), и голый массив блоков.
 *
 * null — строка не похожа на данные редактора вообще; пустой массив —
 * похожа, но в ней ничего нет (человек стёр весь текст).
 */
function blocks_decode(?string $json): ?array
{
    $json = trim((string) $json);

    if ($json === '' || strlen($json) > BLOCKS_MAX_BYTES) {
        return null;
    }

    $data = json_decode($json, true);

    if (! is_array($data)) {
        return null;
    }

    $list = array_key_exists('blocks', $data) ? $data['blocks'] : $data;

    if (! is_array($list) || ($list && ! array_is_list($list))) {
        return null;
    }

    return blocks_sanitize($list);
}

function blocks_encode(array $blocks): string
{
    return json_encode(['blocks' => array_values($blocks)],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Белый список блоков. На выходе — только известные типы с проверенными
 * полями; строки внутри уже очищены и готовы к выводу.
 */
function blocks_sanitize(array $raw): array
{
    $out = [];

    foreach (array_slice($raw, 0, BLOCKS_MAX) as $block) {
        if (! is_array($block)) {
            continue;
        }

        $type = (string) ($block['type'] ?? '');
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];

        switch ($type) {
            case 'paragraph':
                $text = blocks_inline($data['text'] ?? '');

                if (! blocks_blank($text)) {
                    $out[] = ['type' => 'paragraph', 'data' => ['text' => $text]];
                }
                break;

            case 'header':
                $text  = blocks_inline($data['text'] ?? '', false);
                $level = (int) ($data['level'] ?? 2) >= 3 ? 3 : 2;

                if (! blocks_blank($text)) {
                    $out[] = ['type' => 'header', 'data' => ['text' => $text, 'level' => $level]];
                }
                break;

            case 'list':
                $style = ($data['style'] ?? '') === 'ordered' ? 'ordered' : 'unordered';
                $items = blocks_list_items(is_array($data['items'] ?? null) ? $data['items'] : [], 1);

                if (! $items) {
                    break;
                }

                // пустой объект, а не массив: в JSON он должен остаться {},
                // иначе редактор теряет номер первого пункта при сохранении
                $meta = new stdClass();

                if ($style === 'ordered') {
                    $start = (int) ($data['meta']['start'] ?? 1);
                    $meta  = ['start' => max(1, min($start, 100000)), 'counterType' => 'numeric'];
                }

                $out[] = ['type' => 'list', 'data' => ['style' => $style, 'meta' => $meta, 'items' => $items]];
                break;

            case 'quote':
                $text    = blocks_inline($data['text'] ?? '');
                $caption = blocks_inline($data['caption'] ?? '', false);

                if (! blocks_blank($text)) {
                    $out[] = ['type' => 'quote', 'data' => [
                        'text' => $text, 'caption' => blocks_blank($caption) ? '' : $caption, 'alignment' => 'left',
                    ]];
                }
                break;

            case 'image':
                $url = blocks_image_path($data['file']['url'] ?? ($data['url'] ?? ''));

                if ($url !== null) {
                    $caption = blocks_inline($data['caption'] ?? '', false);

                    $out[] = ['type' => 'image', 'data' => [
                        'file'           => ['url' => $url],
                        'caption'        => blocks_blank($caption) ? '' : $caption,
                        'withBorder'     => false,
                        'withBackground' => false,
                        'stretched'      => false,
                    ]];
                }
                break;

            case 'delimiter':
                // два разделителя подряд или разделитель в самом начале —
                // пустое место, а не смысловая граница
                if ($out && end($out)['type'] !== 'delimiter') {
                    $out[] = ['type' => 'delimiter', 'data' => new stdClass()];
                }
                break;
        }
    }

    while ($out && end($out)['type'] === 'delimiter') {
        array_pop($out);
    }

    return $out;
}

/**
 * Пункты списка. Формат редактора — [{content, meta, items}], но старые
 * версии присылали просто строки; принимаем оба.
 */
function blocks_list_items(array $items, int $depth): array
{
    $out = [];

    foreach (array_slice($items, 0, 200) as $item) {
        $content = is_array($item) ? ($item['content'] ?? '') : $item;
        $content = blocks_inline(is_string($content) ? $content : '');
        $nested  = is_array($item) && is_array($item['items'] ?? null) ? $item['items'] : [];

        $children = $nested ? blocks_list_items($nested, $depth + 1) : [];

        // глубже двух уровней не рисуем: вложенные пункты встают рядом
        if ($depth >= BLOCKS_LIST_DEPTH && $children) {
            $flat     = $children;
            $children = [];
        } else {
            $flat = [];
        }

        if (! blocks_blank($content) || $children) {
            $out[] = ['content' => $content, 'meta' => new stdClass(), 'items' => $children];
        }

        foreach ($flat as $child) {
            $child['items'] = [];
            $out[]          = $child;
        }
    }

    return $out;
}

/**
 * Строчная разметка блока: жирный, курсив, подчёркивание, зачёркивание,
 * ссылки и переносы. Переносы по краям срезаются — в старом редакторе
 * ими добивали отступы, а отступы теперь задают сами блоки.
 */
function blocks_inline(?string $html, bool $breaks = true): string
{
    $html = trim((string) $html);

    if ($html === '') {
        return '';
    }

    $tags = INLINE_TAGS;

    if (! $breaks) {
        // в заголовке и подписи перенос строки ломает вид — меняем на пробел
        $html = preg_replace('#<br\s*/?>#i', ' ', $html);
        unset($tags['br']);
    }

    $html = clean_html($html, $tags);

    // «&nbsp;» между словами редактор вставляет на каждый двойной пробел
    $html = str_replace(["\u{00A0}", '&nbsp;'], ' ', $html);

    return blocks_trim_breaks($html);
}

/** Срезать переносы и пробелы по краям, не ломая теги вокруг них. */
function blocks_trim_breaks(string $html): string
{
    $edge = '(?:<br\s*/?>|\s)';

    // <i>…текст<br></i> → <i>…текст</i>
    $html = preg_replace('#' . $edge . '+((?:</[a-z]+>)*)\s*$#iu', '$1', $html) ?? $html;
    // <b><br>текст → <b>текст
    $html = preg_replace('#^\s*((?:<[a-z]+(?:\s[^>]*)?>)*)' . $edge . '+#iu', '$1', $html) ?? $html;

    // больше двух переносов подряд — это уже граница абзацев, а внутри
    // одного абзаца хватает одного
    $html = preg_replace('#(?:<br\s*/?>\s*){2,}#i', '<br>', $html) ?? $html;

    return trim($html);
}

/** В строке нет ни одного видимого символа. */
function blocks_blank(string $html): bool
{
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return trim(str_replace("\u{00A0}", ' ', $text)) === '';
}

/**
 * Адрес картинки → путь вида /storage/…, либо null.
 *
 * Принимаем только свои загрузки: чужой адрес выдаёт владельцу картинки
 * IP читателей и ломает вёрстку, когда файл пропадает. Путь храним без
 * домена и подпапки — при переезде сайта ничего не отвалится.
 */
function blocks_image_path(mixed $url): ?string
{
    if (! is_string($url)) {
        return null;
    }

    $url  = trim($url);
    $base = (string) cfg('base_url');

    // свой полный адрес: https://hothunt.ru/storage/… → /storage/…
    $url = preg_replace('#^https?://' . preg_quote((string) ($_SERVER['HTTP_HOST'] ?? ''), '#') . '(?=/)#i', '', $url) ?? $url;

    if ($base !== '' && str_starts_with($url, $base . '/')) {
        $url = substr($url, strlen($base));
    }

    if (! preg_match('#^/?storage/[A-Za-z0-9/_.-]+\.(?:jpe?g|png|gif|webp)$#i', $url) || str_contains($url, '..')) {
        return null;
    }

    return '/' . ltrim($url, '/');
}

/* ================================================================== вывод */

/**
 * HTML из блоков. Классов нет намеренно: оформление в .prose--blocks
 * повешено на сами теги, а белый список clean_html классы всё равно срежет.
 */
function blocks_render(array $blocks): string
{
    $html = '';

    foreach ($blocks as $block) {
        $data = (array) $block['data'];

        switch ($block['type']) {
            case 'paragraph':
                $html .= '<p>' . $data['text'] . '</p>';
                break;

            case 'header':
                $tag   = 'h' . ((int) $data['level'] === 3 ? 3 : 2);
                $html .= '<' . $tag . '>' . $data['text'] . '</' . $tag . '>';
                break;

            case 'list':
                $start = (int) (((array) $data['meta'])['start'] ?? 1);
                $html .= blocks_render_list($data['style'], $data['items'], $start);
                break;

            case 'quote':
                $html .= '<blockquote><p>' . $data['text'] . '</p>'
                    . ($data['caption'] !== '' ? '<cite>' . $data['caption'] . '</cite>' : '')
                    . '</blockquote>';
                break;

            case 'image':
                $html .= '<figure><img src="' . e(url($data['file']['url'])) . '" alt="" loading="lazy">'
                    . ($data['caption'] !== '' ? '<figcaption>' . $data['caption'] . '</figcaption>' : '')
                    . '</figure>';
                break;

            case 'delimiter':
                $html .= '<hr>';
                break;
        }
    }

    return $html;
}

function blocks_render_list(string $style, array $items, int $start = 1): string
{
    $tag  = $style === 'ordered' ? 'ol' : 'ul';
    $html = '<' . $tag . ($tag === 'ol' && $start > 1 ? ' start="' . $start . '"' : '') . '>';

    foreach ($items as $item) {
        $html .= '<li>' . $item['content']
            . ($item['items'] ? blocks_render_list($style, $item['items']) : '')
            . '</li>';
    }

    return $html . '</' . $tag . '>';
}

/**
 * Данные для редактора в браузере. Отличие от хранимых одно: картинкам
 * нужен адрес с подпапкой сайта, иначе в редакторе они не откроются.
 */
function blocks_for_editor(array $blocks): array
{
    foreach ($blocks as &$block) {
        if ($block['type'] === 'image') {
            $block['data']['file']['url'] = url($block['data']['file']['url']);
        }
    }

    return $blocks;
}

/* ====================================================== публикация целиком */

/** Блоки публикации: из body_json, а у старых записей — из HTML. */
function thread_blocks(array $t): array
{
    static $cache = [];

    $key = md5(($t['body_json'] ?? '') . "\0" . ($t['body'] ?? ''));

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $blocks = ! empty($t['body_json']) ? blocks_decode($t['body_json']) : null;

    if ($blocks === null) {
        $blocks = blocks_from_html($t['body'] ?? '');
    }

    if (count($cache) > 100) {
        $cache = [];
    }

    return $cache[$key] = $blocks;
}

/** Текст публикации для страницы: всегда через блоки — единые отступы у всех. */
function thread_body_html(array $t): string
{
    return blocks_render(thread_blocks($t));
}

/**
 * Текст публикации из формы.
 *
 * Редактор присылает body_json. Если его нет (старая форма, выключенный
 * JavaScript) — берём обычное поле body, как раньше. HTML для колонки body
 * собираем сами из блоков, браузерному HTML не доверяем.
 *
 * @return array{html: string, json: ?string, error: ?string}
 */
function blocks_input(): array
{
    $raw = (string) ($_POST['body_json'] ?? '');

    if (trim($raw) === '') {
        return ['html' => clean_html((string) input('body')), 'json' => null, 'error' => null];
    }

    if (strlen($raw) > BLOCKS_MAX_BYTES) {
        return ['html' => '', 'json' => null,
            'error' => 'Текст слишком длинный. Разбейте его на несколько публикаций.'];
    }

    $blocks = blocks_decode($raw);

    if ($blocks === null) {
        return ['html' => '', 'json' => null,
            'error' => 'Не удалось прочитать текст из редактора. Обновите страницу и попробуйте ещё раз.'];
    }

    return [
        'html'  => blocks_render($blocks),
        'json'  => $blocks ? blocks_encode($blocks) : null,
        'error' => null,
    ];
}

/**
 * JSON для редактора на странице формы: из того, что человек только что
 * отправил (после ошибки), из сохранённой публикации или пустой.
 */
function blocks_editor_json(?array $t = null, ?string $posted = null): string
{
    $blocks = null;

    if ($posted !== null && trim($posted) !== '') {
        $blocks = blocks_decode($posted);
    }

    if ($blocks === null && $t !== null) {
        $blocks = thread_blocks($t);
    }

    return blocks_encode(blocks_for_editor($blocks ?? []));
}

/* ============================================== анонс для ленты блогов */

/**
 * Первые абзацы записи — так, как их показывает лента vc.ru.
 *
 * Раньше в анонс шёл весь текст одной строкой: заголовок сливался с
 * абзацем, пункты списка — друг с другом. Теперь берём первые один-два
 * абзаца или цитаты, каждый отдельной строкой. Заголовки пропускаем:
 * первый из них обычно повторяет название записи.
 *
 * @return string[] простой текст абзацев
 */
function blocks_lead(array $blocks, int $limit = 300, int $max = 2): array
{
    $out  = [];
    $left = $limit;

    foreach ($blocks as $block) {
        $type = $block['type'];
        $text = in_array($type, ['paragraph', 'quote'], true) ? $block['data']['text'] : null;

        // строка целиком жирным — это подзаголовок, набранный вручную
        $pseudoHeading = $text !== null && preg_match('#^<(b|strong)>[^<]*</\1>$#u', trim($text))
            && mb_strlen(blocks_plain($text)) < 90;

        if ($text === null || $pseudoHeading) {
            // до первого абзаца заголовки и картинки пропускаем,
            // после — анонс заканчивается
            if ($out && $type !== 'image') {
                break;
            }

            continue;
        }

        $plain = blocks_plain($text);

        if ($plain === '') {
            continue;
        }

        if (mb_strlen($plain) > $left) {
            // короткий хвост не нужен: лучше закончить предыдущим абзацем
            if ($out && $left < 70) {
                break;
            }

            $out[] = blocks_clip($plain, $left);
            break;
        }

        $out[] = $plain;
        $left -= mb_strlen($plain);

        if (count($out) >= $max) {
            break;
        }
    }

    // запись без абзацев (только список или только заголовки) —
    // тогда плоский текст с расставленной пунктуацией
    if (! $out) {
        $flat = html_to_text(blocks_render($blocks));

        return $flat !== '' ? [blocks_clip($flat, $limit)] : [];
    }

    return $out;
}

/** HTML блока → простой текст одной строкой. */
function blocks_plain(string $html): string
{
    $text = html_entity_decode(strip_tags(preg_replace('#<br\s*/?>#i', ' ', $html) ?? $html),
        ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

/** Обрезать по границе слова: обрубок посреди слова выглядит мусором. */
function blocks_clip(string $text, int $limit): string
{
    if (mb_strlen($text) <= $limit) {
        return $text;
    }

    $cut   = mb_substr($text, 0, $limit);
    $space = mb_strrpos($cut, ' ');

    if ($space !== false && $space > $limit * 0.6) {
        $cut = mb_substr($cut, 0, $space);
    }

    return rtrim($cut, " ,.;:—-–") . '…';
}

/* ================================================ старый HTML → блоки */

/** Теги, которые начинают новый блок, а не продолжают строку. */
const BLOCKS_BLOCK_TAGS = [
    'p', 'div', 'section', 'article', 'header', 'footer', 'main', 'aside', 'nav', 'center',
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'ul', 'ol', 'li', 'img', 'figure',
    'figcaption', 'hr', 'pre', 'table', 'thead', 'tbody', 'tr', 'td', 'th', 'dl', 'dt', 'dd',
];

const BLOCKS_DROP_TAGS = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'noscript', 'template'];

/**
 * Разложить HTML старого редактора на блоки.
 *
 * Правила простые и повторяют то, как текст выглядел на странице:
 *   — заголовок, цитата, список, картинка — свои блоки;
 *   — <p>/<div> и двойной перенос строки — граница абзаца;
 *   — одиночный <br> остаётся переносом внутри абзаца;
 *   — соседние списки одного вида склеиваются: старый редактор клал
 *     каждый пункт в отдельный <ul>, и между ними появлялись дыры.
 */
function blocks_from_html(?string $html): array
{
    $html = trim((string) $html);

    if ($html === '') {
        return [];
    }

    // самые старые записи хранятся простым текстом
    if (strip_tags($html) === $html) {
        $html = nl2br(e($html), false);
    }

    $doc = new DOMDocument('1.0', 'UTF-8');

    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $root = $doc->getElementById('root');

    if (! $root) {
        return [];
    }

    $state = (object) ['blocks' => [], 'buf' => '', 'doc' => $doc];

    blocks_walk($root, $state);
    blocks_flush($state);

    return blocks_sanitize(blocks_merge_lists($state->blocks));
}

function blocks_walk(DOMNode $node, object $s): void
{
    foreach (iterator_to_array($node->childNodes) as $child) {
        if ($child instanceof DOMText) {
            $s->buf .= htmlspecialchars($child->nodeValue ?? '', ENT_NOQUOTES, 'UTF-8');
            continue;
        }

        if (! $child instanceof DOMElement) {
            continue;
        }

        $tag = strtolower($child->nodeName);

        if (in_array($tag, BLOCKS_DROP_TAGS, true)) {
            continue;
        }

        if ($tag === 'br') {
            $s->buf .= '<br>';
            continue;
        }

        if ($tag === 'img') {
            blocks_flush($s);
            $s->blocks[] = blocks_image_block($child->getAttribute('src'));
            continue;
        }

        if ($tag === 'hr') {
            blocks_flush($s);
            $s->blocks[] = ['type' => 'delimiter', 'data' => []];
            continue;
        }

        if (preg_match('/^h([1-6])$/', $tag, $m)) {
            blocks_flush($s);
            $images      = [];
            $s->blocks[] = ['type' => 'header', 'data' => [
                'text'  => blocks_inline_of($child, $s, $images),
                'level' => (int) $m[1] <= 2 ? 2 : 3,
            ]];
            blocks_push_images($s, $images);
            continue;
        }

        if ($tag === 'blockquote') {
            blocks_flush($s);
            $images      = [];
            $s->blocks[] = ['type' => 'quote', 'data' => ['text' => blocks_inline_of($child, $s, $images), 'caption' => '']];
            blocks_push_images($s, $images);
            continue;
        }

        if ($tag === 'ul' || $tag === 'ol') {
            blocks_flush($s);
            $images      = [];
            $s->blocks[] = ['type' => 'list', 'data' => [
                'style' => $tag === 'ol' ? 'ordered' : 'unordered',
                'items' => blocks_list_of($child, $s, $images),
            ]];
            blocks_push_images($s, $images);
            continue;
        }

        if ($tag === 'figure') {
            blocks_flush($s);
            $img     = $child->getElementsByTagName('img')->item(0);
            $caption = $child->getElementsByTagName('figcaption')->item(0);

            if ($img) {
                $block = blocks_image_block($img->getAttribute('src'));
                $block['data']['caption'] = $caption ? blocks_inner_html($caption, $s) : '';
                $s->blocks[] = $block;
            }
            continue;
        }

        if (in_array($tag, BLOCKS_BLOCK_TAGS, true)) {
            blocks_flush($s);
            blocks_walk($child, $s);
            blocks_flush($s);
            continue;
        }

        // строчный тег: если внутри нет блоков и картинок — берём целиком,
        // иначе раскрываем (форматирование вокруг блока теряется, но текст — нет)
        if (blocks_has_block_inside($child)) {
            blocks_walk($child, $s);
        } else {
            $s->buf .= $s->doc->saveHTML($child);
        }
    }
}

/** Накопленную строку — в абзацы, деля по двойному переносу. */
function blocks_flush(object $s): void
{
    $buf    = $s->buf;
    $s->buf = '';

    if (trim($buf) === '') {
        return;
    }

    foreach (preg_split('#(?:<br\s*/?>\s*){2,}#i', $buf) as $part) {
        $s->blocks[] = ['type' => 'paragraph', 'data' => ['text' => $part]];
    }
}

/**
 * Содержимое элемента одной строкой: вложенные блоки превращаются в
 * переносы, картинки выносятся наружу — их вставят отдельными блоками.
 */
function blocks_inline_of(DOMElement $el, object $s, array &$images): string
{
    $html = '';

    foreach (iterator_to_array($el->childNodes) as $child) {
        if ($child instanceof DOMText) {
            $html .= htmlspecialchars($child->nodeValue ?? '', ENT_NOQUOTES, 'UTF-8');
            continue;
        }

        if (! $child instanceof DOMElement) {
            continue;
        }

        $tag = strtolower($child->nodeName);

        if (in_array($tag, BLOCKS_DROP_TAGS, true)) {
            continue;
        }

        if ($tag === 'br') {
            $html .= '<br>';
        } elseif ($tag === 'img') {
            $images[] = $child->getAttribute('src');
        } elseif (in_array($tag, BLOCKS_BLOCK_TAGS, true)) {
            $html .= '<br>' . blocks_inline_of($child, $s, $images) . '<br>';
        } elseif (blocks_has_block_inside($child)) {
            $html .= blocks_inline_of($child, $s, $images);
        } else {
            $html .= $s->doc->saveHTML($child);
        }
    }

    return $html;
}

/** Пункты списка с одним уровнем вложенности. */
function blocks_list_of(DOMElement $list, object $s, array &$images): array
{
    $items = [];

    foreach (iterator_to_array($list->childNodes) as $li) {
        if (! $li instanceof DOMElement) {
            continue;
        }

        $tag = strtolower($li->nodeName);

        // список прямо внутри списка, без <li> — так тоже бывает
        if ($tag === 'ul' || $tag === 'ol') {
            foreach (blocks_list_of($li, $s, $images) as $item) {
                $items[] = $item;
            }
            continue;
        }

        $nested  = [];
        $content = '';

        foreach (iterator_to_array($li->childNodes) as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->nodeName), ['ul', 'ol'], true)) {
                $nested = array_merge($nested, blocks_list_of($child, $s, $images));
                continue;
            }

            if ($child instanceof DOMText) {
                $content .= htmlspecialchars($child->nodeValue ?? '', ENT_NOQUOTES, 'UTF-8');
            } elseif ($child instanceof DOMElement) {
                $wrap = $s->doc->createElement('span');
                $wrap->appendChild($child->cloneNode(true));
                $content .= blocks_inline_of($wrap, $s, $images);
            }
        }

        $items[] = ['content' => $content, 'items' => $nested];
    }

    return $items;
}

function blocks_inner_html(DOMElement $el, object $s): string
{
    $html = '';

    foreach ($el->childNodes as $child) {
        $html .= $s->doc->saveHTML($child);
    }

    return $html;
}

function blocks_has_block_inside(DOMElement $el): bool
{
    foreach (array_merge(BLOCKS_BLOCK_TAGS, ['br']) as $tag) {
        // перенос внутри <b> не делает его блоком, но двойной — делает:
        // по нему текст режется на абзацы
        if ($tag === 'br') {
            if (preg_match('#(?:<br\s*/?>\s*){2,}#i', $el->ownerDocument->saveHTML($el))) {
                return true;
            }
            continue;
        }

        if ($el->getElementsByTagName($tag)->length) {
            return true;
        }
    }

    return false;
}

function blocks_image_block(string $src): array
{
    return ['type' => 'image', 'data' => ['file' => ['url' => $src], 'caption' => '']];
}

function blocks_push_images(object $s, array $images): void
{
    foreach ($images as $src) {
        $s->blocks[] = blocks_image_block($src);
    }
}

/** Соседние списки одного вида — один список. */
function blocks_merge_lists(array $blocks): array
{
    $out = [];

    foreach ($blocks as $block) {
        $prev = $out ? $out[array_key_last($out)] : null;

        if ($block['type'] === 'list' && $prev && $prev['type'] === 'list'
            && $prev['data']['style'] === $block['data']['style']) {
            $out[array_key_last($out)]['data']['items'] = array_merge($prev['data']['items'], $block['data']['items']);
            continue;
        }

        // пустые абзацы между списками склейке не мешают
        if ($block['type'] === 'paragraph' && blocks_blank(strip_tags($block['data']['text']))
            && $prev && $prev['type'] === 'list') {
            continue;
        }

        $out[] = $block;
    }

    return $out;
}
