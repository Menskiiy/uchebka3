<?php
/**
 * Очистка HTML из редактора.
 *
 * Пользовательский HTML нельзя выводить как есть: через <script>, обработчики
 * onclick и javascript:-ссылки чужой пост угоняет сессию читателя.
 * Поэтому всё, что не входит в белый список, вырезается.
 *
 * Список намеренно короткий — это ещё и защита вёрстки: без произвольных
 * стилей и таблиц публикация не сможет развалить страницу на телефоне.
 */

const ALLOWED_TAGS = [
    'b'          => [],
    'strong'     => [],
    'i'          => [],
    'em'         => [],
    'u'          => [],
    's'          => [],
    'strike'     => [],
    'br'         => [],
    'p'          => [],
    'div'        => [],
    'h2'         => [],
    'h3'         => [],
    'blockquote' => [],
    'cite'       => [],
    'ul'         => [],
    'ol'         => ['start'],
    'li'         => [],
    'hr'         => [],
    'figure'     => [],
    'figcaption' => [],
    'a'          => ['href'],
    'img'        => ['src', 'alt'],
];

/**
 * Строчная разметка внутри блока редактора: абзаца, заголовка, пункта списка.
 * Блочных тегов здесь нет вовсе — структуру задают сами блоки, а не HTML.
 */
const INLINE_TAGS = [
    'b'      => [],
    'strong' => [],
    'i'      => [],
    'em'     => [],
    'u'      => [],
    's'      => [],
    'strike' => [],
    'br'     => [],
    'a'      => ['href'],
];

function clean_html(?string $html, array $allowed = ALLOWED_TAGS): string
{
    $html = trim((string) $html);

    if ($html === '') {
        return '';
    }

    $doc = new DOMDocument('1.0', 'UTF-8');

    libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<?xml encoding="UTF-8"><div id="root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();

    $root = $doc->getElementById('root');

    if (! $root) {
        return '';
    }

    clean_node($root, $allowed);

    $out = '';

    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }

    // пустые обёртки после чистки только раздувают разметку
    $out = preg_replace('#<(p|div|h2|h3|blockquote|li)>\s*(<br\s*/?>)*\s*</\1>#i', '', $out);

    return trim((string) $out);
}

/** Рекурсивный обход: неразрешённые теги разворачиваются, атрибуты вычищаются. */
function clean_node(DOMNode $node, array $allowedTags = ALLOWED_TAGS): void
{
    // с конца — потому что список детей меняется по ходу удаления
    for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
        $child = $node->childNodes->item($i);

        if (! $child instanceof DOMElement) {
            continue;
        }

        clean_node($child, $allowedTags);

        $tag = strtolower($child->nodeName);

        // скрипты и стили вырезаем вместе с содержимым
        if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form'], true)) {
            $child->parentNode->removeChild($child);
            continue;
        }

        if (! array_key_exists($tag, $allowedTags)) {
            unwrap_node($child);
            continue;
        }

        $allowed = $allowedTags[$tag];

        for ($a = $child->attributes->length - 1; $a >= 0; $a--) {
            $attr = $child->attributes->item($a);

            if (! in_array(strtolower($attr->nodeName), $allowed, true)) {
                $child->removeAttribute($attr->nodeName);
            }
        }

        if ($tag === 'a') {
            clean_link($child);
        }

        if ($tag === 'img') {
            clean_image($child);
        }

        // номер первого пункта — только число, остальное браузеру не нужно
        if ($tag === 'ol' && $child->hasAttribute('start')) {
            $start = (int) $child->getAttribute('start');

            $start > 1
                ? $child->setAttribute('start', (string) min($start, 100000))
                : $child->removeAttribute('start');
        }
    }
}

/** Оставить детей на месте родителя, а сам тег убрать. */
function unwrap_node(DOMElement $node): void
{
    while ($node->firstChild) {
        $node->parentNode->insertBefore($node->firstChild, $node);
    }

    $node->parentNode->removeChild($node);
}

function clean_link(DOMElement $node): void
{
    $href = trim($node->getAttribute('href'));

    // javascript: и data: — самый простой способ выполнить чужой код по клику
    if ($href === '' || ! preg_match('#^(https?://|/|mailto:)#i', $href)) {
        unwrap_node($node);

        return;
    }

    $node->setAttribute('href', $href);
    $node->setAttribute('rel', 'nofollow noopener');
    $node->setAttribute('target', '_blank');
}

function clean_image(DOMElement $node): void
{
    $src = trim($node->getAttribute('src'));

    // только свои загрузки: чужие адреса выдают IP читателей владельцу картинки
    // и ломают вёрстку, когда исчезают
    if (! preg_match('#^/?storage/#', ltrim($src, '/')) && ! str_starts_with($src, url('/storage/'))) {
        $node->parentNode->removeChild($node);

        return;
    }

    $node->setAttribute('loading', 'lazy');
    $node->removeAttribute('alt');
}

/**
 * Вывод текста публикации или комментария.
 * Старые записи хранятся простым текстом — их переводим в разметку переносами.
 */
function render_body(?string $body): string
{
    $body = (string) $body;

    if ($body === '') {
        return '';
    }

    if (strip_tags($body) === $body) {
        return nl2br(e($body));
    }

    return clean_html($body);
}
