<?php
/**
 * Правовые страницы сайта.
 *
 * Тексты подготовил юрист, в коде они лежат заготовками (legal_texts.php),
 * а действующая редакция — в настройках: так их правят в панели, не трогая
 * файлы. Адреса страниц постоянные: на них ссылаются документы друг у друга,
 * подвал, формы регистрации и публикации, и менять их без нужды нельзя.
 */

require_once __DIR__ . '/legal_texts.php';

/**
 * Реестр документов: ключ, постоянный адрес, заголовок и короткая подпись
 * для подвала. Порядок — тот, в котором они показываются в списке.
 *
 * `group` раскладывает ссылки по колонкам подвала и раздела «Документы».
 */
function legal_pages(): array
{
    return [
        'terms' => [
            'url'   => '/terms',
            'title' => 'Пользовательское соглашение',
            'short' => 'Пользовательское соглашение',
            'group' => 'main',
        ],
        'privacy' => [
            'url'   => '/privacy-policy',
            'title' => 'Политика обработки персональных данных',
            'short' => 'Обработка персональных данных',
            'group' => 'main',
        ],
        'consent' => [
            'url'   => '/personal-data-consent',
            'title' => 'Согласие на обработку персональных данных',
            'short' => 'Согласие на обработку данных',
            'group' => 'main',
        ],
        'cookie' => [
            'url'   => '/cookie-policy',
            'title' => 'Политика использования файлов cookie',
            'short' => 'Файлы cookie',
            'group' => 'main',
        ],
        'ads' => [
            'url'   => '/advertising-consent',
            'title' => 'Согласие на получение рекламных и маркетинговых рассылок',
            'short' => 'Рекламные рассылки',
            'group' => 'main',
        ],
        'registration' => [
            'url'   => '/registration-rules',
            'title' => 'Правила регистрации и авторизации пользователей',
            'short' => 'Регистрация и авторизация',
            'group' => 'rules',
        ],
        'content' => [
            'url'   => '/content-rules',
            'title' => 'Правила размещения скидок, промокодов и публикаций',
            'short' => 'Размещение публикаций',
            'group' => 'rules',
        ],
        'loyalty' => [
            'url'   => '/loyalty-rules',
            'title' => 'Правила программы лояльности и начисления «Огоньков»',
            'short' => 'Программа лояльности',
            'group' => 'rules',
        ],
        'blocking' => [
            'url'   => '/account-blocking-rules',
            'title' => 'Правила блокировки аккаунтов и борьбы с накрутками',
            'short' => 'Блокировки и накрутки',
            'group' => 'rules',
        ],
        'copyright' => [
            'url'   => '/copyright-claims',
            'title' => 'Процедура рассмотрения претензий правообладателей',
            'short' => 'Правообладателям',
            'group' => 'copyright',
        ],
        'copyright_template' => [
            'url'   => '/copyright-claim-template',
            'title' => 'Шаблон претензии правообладателя',
            'short' => 'Шаблон претензии',
            'group' => 'copyright',
        ],
    ];
}

/**
 * Старые адреса двух первых страниц. Их уже раздавали и индексировали,
 * поэтому они отвечают постоянным редиректом, а не 404.
 */
function legal_legacy_urls(): array
{
    return [
        '/politika-konfidencialnosti' => 'privacy',
        '/polzovatelskoe-soglashenie' => 'terms',
    ];
}

function legal_url(string $key): string
{
    return url(legal_pages()[$key]['url'] ?? '/dokumenty');
}

/** Ссылка на документ — то, что чаще всего нужно в формах и подвале. */
function legal_link(string $key, ?string $text = null, bool $blank = true): string
{
    $page = legal_pages()[$key] ?? null;

    if (! $page) {
        return '';
    }

    return '<a href="' . e(legal_url($key)) . '"'
        . ($blank ? ' target="_blank" rel="noopener"' : '')
        . '>' . e($text ?? mb_strtolower($page['title'])) . '</a>';
}

function legal_page(string $key): array
{
    $pages = legal_pages();

    if (! isset($pages[$key])) {
        page_404();
    }

    $defaults = legal_defaults();

    return [
        'key'     => $key,
        'url'     => $pages[$key]['url'],
        'title'   => setting('page_' . $key . '_title', $pages[$key]['title']),
        'body'    => setting('page_' . $key, $defaults[$key] ?? ''),
        'updated' => setting('page_' . $key . '_updated', date('d.m.Y')),
    ];
}

/**
 * Текст страницы бывает двух видов: обычный текст с переносами строк и
 * разметка из визуального редактора. Определяем по наличию тегов, иначе
 * текст без разметки потерял бы деление на абзацы.
 */
function legal_body_html(string $body): string
{
    return strip_tags($body) === $body ? nl2br(e($body)) : clean_html($body);
}

function page_legal(string $key): void
{
    $page = legal_page($key);

    render('legal', [
        'pageTitle' => $page['title'],
        'body'      => $page['body'],
        'updated'   => $page['updated'],
        'docKey'    => $key,
        'meta'      => meta_simple(
            $page['title'] . ' | ' . setting('site_name', cfg('app_name')),
            excerpt($page['body'], 170) ?: $page['title'] . ' сайта ' . setting('site_name', cfg('app_name'))
                . '. Редакция от ' . $page['updated'] . '.'
        ),
    ]);
}

/** Старый адрес документа — постоянный редирект на новый. */
function page_legal_legacy(string $key): void
{
    http_response_code(301);
    header('Location: ' . legal_url($key));
    exit;
}

/**
 * Раздел «Документы» — одна страница, с которой видно все остальные.
 * На неё ссылаются подвал, личный кабинет и формы: так человеку не нужно
 * помнить одиннадцать адресов.
 */
function page_documents(): void
{
    $items = [];

    foreach (legal_pages() as $key => $info) {
        $page = legal_page($key);

        $items[$key] = $info + ['updated' => $page['updated'], 'title' => $page['title']];
    }

    render('documents', [
        'items' => $items,
        'meta'  => meta_simple(
            'Документы и правовая информация | ' . setting('site_name', cfg('app_name')),
            'Пользовательское соглашение, обработка персональных данных, файлы cookie, '
            . 'правила размещения публикаций, программы лояльности и блокировок, раздел для правообладателей.'
        ),
    ]);
}

/**
 * Записать согласие пользователя.
 *
 * Хранится не «галочка стояла», а дата, время, ключ документа и дата его
 * редакции: если документ потом изменится, будет видно, на какую именно
 * версию человек соглашался. IP пишем в бинарном виде — он нужен как
 * техническое подтверждение, а не как способ узнать человека.
 *
 * @param string             $kind   что именно принимали: registration, cookie, publication
 * @param array<int,string>  $docs   ключи документов из реестра
 */
function record_consent(string $kind, array $docs = [], string $choice = 'accepted', ?int $userId = null): void
{
    $userId = $userId ?? auth_id() ?: null;
    $ip     = $_SERVER['REMOTE_ADDR'] ?? '';
    $ip     = $ip !== '' ? @inet_pton($ip) : null;
    $agent  = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    // документов может не быть вовсе — например, у выбора в cookie-баннере
    foreach ($docs ?: [null] as $key) {
        insert('consents', [
            'user_id'     => $userId,
            'kind'        => $kind,
            'doc_key'     => $key,
            'doc_version' => $key ? legal_page($key)['updated'] : null,
            'choice'      => $choice,
            'ip'          => $ip ?: null,
            'user_agent'  => $agent ?: null,
            'created_at'  => now(),
        ]);
    }
}

/**
 * Согласия конкретного человека — для личного кабинета.
 *
 * Оставляем по одной записи на документ: выбор в cookie-баннере человек
 * меняет каждый раз, когда чистит браузер, и десяток одинаковых строк
 * превращает раздел в мусор. Актуально последнее решение.
 */
function user_consents(int $userId): array
{
    $rows   = q('SELECT kind, doc_key, doc_version, choice, created_at
                 FROM consents WHERE user_id = ? ORDER BY created_at DESC LIMIT 200', [$userId]);
    $latest = [];

    foreach ($rows as $row) {
        $key = $row['kind'] . ':' . ($row['doc_key'] ?? '');

        if (! isset($latest[$key])) {
            $latest[$key] = $row;
        }
    }

    return array_values($latest);
}

/** Человеческое название записи в журнале согласий. */
function consent_label(string $kind, ?string $docKey): string
{
    if ($docKey && isset(legal_pages()[$docKey])) {
        return legal_pages()[$docKey]['title'];
    }

    return match ($kind) {
        'cookie'      => 'Выбор в баннере cookie',
        'advertising' => 'Рекламные рассылки',
        default       => 'Согласие при регистрации',
    };
}
