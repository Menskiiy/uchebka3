<?php
/**
 * ПРОМИКИ.РУ — единая точка входа.
 * Все запросы приходят сюда через .htaccess.
 */

declare(strict_types=1);


$src = __DIR__ . '/../src';

require $src . '/security.php';

register_error_handlers();
secure_session();

require $src . '/helpers.php';
require $src . '/sanitize.php';
require $src . '/blocks.php';
require $src . '/icons.php';
require $src . '/db.php';
require $src . '/seed.php';
require $src . '/mail.php';
require $src . '/auth.php';
require $src . '/queries.php';
require $src . '/temperature.php';
require $src . '/components.php';
require $src . '/pages.php';
require $src . '/actions.php';
require $src . '/legal.php';
require $src . '/sitemap.php';
require $src . '/social.php';
require $src . '/oauth.php';
require $src . '/api.php';
require $src . '/bot.php';
require $src . '/captcha.php';
require $src . '/digest.php';
require $src . '/meta.php';
require $src . '/admin.php';

// Первое обращение создаёт базу и справочники
db();

// Догоняющие изменения схемы для сайтов, установленных раньше
db_migrate();

// Название сайта, описание и коэффициенты из админки перекрывают config.php
apply_settings();

/*
 * Письма уходят после того, как ответ отдан браузеру. Регистрируем здесь,
 * а не в конце файла: все обработчики завершаются через exit(), и обычный
 * код после маршрутизации просто не выполнился бы.
 */
register_shutdown_function('mail_queue_flush');

// Остывание температуры и снятие истёкших скидок — не чаще раза в час
run_maintenance();

/* ------------------------------------------------------------- маршруты */

$base = cfg('base_url');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}

$path   = '/' . trim(rawurldecode($path), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/**
 * Маршрут: шаблон с {параметрами} → обработчик.
 * Проверяются по порядку, первый совпавший выигрывает.
 */
$routes = [
    'GET' => [
        '/'                              => fn () => page_feed(),
        '/skidki'                        => fn () => page_feed('deal'),
        '/promokody'                     => fn () => page_feed('voucher'),
        '/kategorii'                     => fn () => page_categories(),
        '/kategoriya/{slug}'             => fn ($s) => page_category($s),
        '/magaziny'                      => fn () => page_merchants(),
        '/magazin/{slug}'                => fn ($s) => page_merchant($s),
        '/blogi'                         => fn () => page_blogs(),
        '/obsuzhdeniya'                  => fn () => page_groups(),
        '/obsuzhdeniya/{slug}'           => fn ($s) => page_group($s),
        '/poisk'                         => fn () => page_search(),
        '/proverka-promokoda'            => fn () => page_check_code(),
        '/api/lenta'                     => fn () => api_feed(),
        '/api/lenta/novoe'               => fn () => api_feed_updates(),
        '/api/kommentarii/{slug}'        => fn ($s) => api_comments($s),
        '/api/uvedomleniya'              => fn () => api_notifications(),
        '/promokod/{slug}'               => fn ($s) => page_coupon($s),
        // публикация внутри магазина: /magazin/ozon/skidka-na-noutbuk
        '/magazin/{merchant}/{slug}'     => fn ($m, $s) => page_thread($s, $m),

        // прежний адрес остаётся рабочим и уводит на новый постоянным редиректом
        '/post/{slug}'                   => fn ($s) => page_thread($s),
        '/go/{slug}'                     => fn ($s) => page_go($s),
        '/profile/{name}'                => fn ($n) => page_profile($n),
        '/profile/{name}/{tab}'          => fn ($n, $t) => page_profile($n, $t),
        '/vhod'                          => fn () => page_login(),
        '/auth/yandex'                   => fn () => page_yandex_start(),
        '/auth/yandex/callback'          => fn () => page_yandex_callback(),
        '/vosstanovlenie'                => fn () => page_forgot(),
        '/vosstanovlenie/{token}'        => fn ($t) => page_reset($t),
        '/sitemap.xml'                   => fn () => page_sitemap(),
        '/kak-eto-rabotaet'              => fn () => page_help(),
        '/podtverzhdenie/{token}'        => fn ($t) => act_verify($t),
        '/otpiska/{token}'               => fn ($t) => page_unsubscribe($t),
        '/otpiska-reklama/{token}'       => fn ($t) => page_unsubscribe_ads($t),
        '/health'                        => fn () => page_health(),
        '/dokumenty'                     => fn () => page_documents(),

        // Правовые документы: адреса постоянные, на них ссылаются сами
        // документы, подвал и формы. Собираются из реестра в legal.php,
        // чтобы добавить документ можно было в одном месте.
        ...array_reduce(
            array_keys(legal_pages()),
            static function (array $routes, string $key): array {
                $routes[legal_pages()[$key]['url']] = static fn () => page_legal($key);

                return $routes;
            },
            []
        ),

        // прежние адреса двух страниц уже в индексе — отвечаем редиректом
        ...array_map(
            static fn (string $key) => static fn () => page_legal_legacy($key),
            legal_legacy_urls()
        ),
        '/registraciya'                  => fn () => page_register(),
        '/dobavit'                       => fn () => page_publish_index(),
        '/dobavit/{type}'                => fn ($t) => page_publish_form($t),
        '/zakladki'                      => fn () => page_bookmarks(),
        '/balans'                        => fn () => page_balance(),
        '/uvedomleniya'                  => fn () => page_notifications(),
        '/post/{slug}/redaktirovat'      => fn ($s) => page_thread_edit($s),
        '/nastroyki'                     => fn () => page_settings(),
        '/admin'                         => fn () => admin_dashboard(),
        '/admin/vhod'                    => fn () => admin_login_page(),
        '/admin/publikacii'              => fn () => admin_threads(),
        '/admin/publikacii/{id}'         => fn ($i) => admin_thread_edit((int) $i),
        '/admin/polzovateli'             => fn () => admin_users(),
        '/admin/polzovateli/{id}/bot'    => fn ($i) => admin_bot_account((int) $i),
        '/admin/razdely'                 => fn () => admin_taxonomy('razdely'),
        '/admin/kategorii'               => fn () => admin_taxonomy('kategorii'),
        '/admin/magaziny'                => fn () => admin_taxonomy('magaziny'),
        '/admin/zhaloby'                 => fn () => admin_reports(),
        '/admin/zhurnal'                 => fn () => admin_log(),
        '/admin/stranicy'                => fn () => admin_pages(),
        '/admin/stranicy/{key}'          => fn ($k) => admin_page_edit((string) $k),
        '/admin/bot'                     => fn () => admin_bot(),
        '/admin/kanaly'                  => fn () => admin_channels(),
        '/admin/vyplaty'                 => fn () => admin_withdrawals(),
        '/admin/pochta'                  => fn () => admin_mail(),
        '/admin/nastroyki'               => fn () => admin_settings(),
    ],

    'POST' => [
        '/vhod'                          => fn () => act_login(),
        '/vosstanovlenie'                => fn () => act_forgot(),
        '/vosstanovlenie/{token}'        => fn ($t) => act_reset($t),
        '/registraciya'                  => fn () => act_register(),
        '/vyhod'                         => function () { csrf_check(); logout_user(); redirect('/'); },
        '/nastroyki'                     => fn () => act_settings(),
        '/nastroyki/udalit-akkaunt'      => fn () => act_account_delete(),
        '/dobavit/{type}'                => fn ($t) => act_publish($t),
        '/post/{slug}/redaktirovat'      => fn ($s) => act_thread_update($s),
        '/post/{slug}/na-proverku'       => fn ($s) => act_thread_resubmit($s),
        '/api/soglasie-cookie'           => fn () => api_cookie_consent(),
        '/uvedomleniya/prochitano'       => fn () => act_notifications_read(),
        '/post/{slug}/golos'             => fn ($s) => act_vote($s),
        '/post/{slug}/zakladka'          => fn ($s) => act_bookmark($s),
        '/magazin/{slug}/like'           => fn ($s) => act_merchant_like($s),
        '/post/{slug}/kommentarii'       => fn ($s) => act_comment_store($s),
        '/kommentarii/{id}/like'         => fn ($i) => act_comment_like((int) $i),
        '/kommentarii/{id}/udalit'       => fn ($i) => act_comment_delete((int) $i),
        '/kommentarii/{id}/redaktirovat' => fn ($i) => act_comment_update((int) $i),
        '/zhaloba'                       => fn () => act_report(),
        '/zagruzka'                      => fn () => act_upload(),
        '/podpiska'                      => fn () => act_follow(),
        '/balans'                        => fn () => act_withdraw(),

        '/admin/vhod'                               => fn () => admin_login(),
        '/admin/publikacii/{id}'                    => fn ($i) => admin_thread_save((int) $i),
        '/admin/publikacii/{id}/snyat'              => fn ($i) => admin_thread_status((int) $i, 'rejected'),
        '/admin/publikacii/{id}/odobrit'            => fn ($i) => admin_thread_status((int) $i, 'published'),
        '/admin/publikacii/{id}/vernut'             => fn ($i) => admin_thread_status((int) $i, 'published'),
        '/admin/publikacii/{id}/udalit'             => fn ($i) => admin_thread_delete((int) $i),
        '/admin/publikacii/{id}/proverit'           => fn ($i) => admin_thread_verify((int) $i),
        '/admin/polzovateli/{id}/blokirovat'        => fn ($i) => admin_user_ban((int) $i),
        '/admin/polzovateli/{id}/razblokirovat'     => fn ($i) => admin_user_unban((int) $i),
        '/admin/polzovateli/{id}/prava'             => fn ($i) => admin_user_role((int) $i),
        '/admin/polzovateli/{id}/bot'               => fn ($i) => admin_bot_account_save((int) $i),
        '/admin/polzovateli/{id}/tehnicheskiy'      => fn ($i) => admin_user_bot_toggle((int) $i),
        '/admin/polzovateli/{id}/redaktor'          => fn ($i) => admin_user_role((int) $i, 'editor'),
        '/admin/vyplaty/{id}/vyplacheno'            => fn ($i) => admin_withdrawal_resolve((int) $i, 'paid'),
        '/admin/vyplaty/{id}/otklonit'              => fn ($i) => admin_withdrawal_resolve((int) $i, 'rejected'),
        '/admin/razdely'                            => fn () => admin_taxonomy_save('razdely'),
        '/admin/razdely/{id}'                       => fn ($i) => admin_taxonomy_save('razdely', (int) $i),
        '/admin/razdely/{id}/udalit'                => fn ($i) => admin_taxonomy_delete('razdely', (int) $i),
        '/admin/kategorii'                          => fn () => admin_taxonomy_save('kategorii'),
        '/admin/kategorii/{id}'                     => fn ($i) => admin_taxonomy_save('kategorii', (int) $i),
        '/admin/kategorii/{id}/udalit'              => fn ($i) => admin_taxonomy_delete('kategorii', (int) $i),
        '/admin/magaziny'                           => fn () => admin_taxonomy_save('magaziny'),
        '/admin/magaziny/{id}'                      => fn ($i) => admin_taxonomy_save('magaziny', (int) $i),
        '/admin/magaziny/{id}/udalit'               => fn ($i) => admin_taxonomy_delete('magaziny', (int) $i),
        '/admin/zhaloby/{id}/prinyat'               => fn ($i) => admin_report_resolve((int) $i, true),
        '/admin/zhaloby/{id}/otklonit'              => fn ($i) => admin_report_resolve((int) $i, false),
        '/admin/stranicy'                           => fn () => admin_pages_save(),
        '/admin/bot'                                => fn () => admin_bot_save(),
        '/admin/bot/zapustit'                       => fn () => admin_bot_run(),
        '/admin/kanaly'                             => fn () => admin_channels_save(),
        '/admin/kanaly/otpravit'                    => fn () => admin_channels_run(),
        '/admin/pochta'                             => fn () => admin_mail_test(),
        '/admin/pochta/smtp'                        => fn () => admin_mail_smtp(),
        '/admin/pochta/ochistit'                    => fn () => admin_mail_clear(),
        '/admin/nastroyki'                          => fn () => admin_settings_save(),
        '/admin/nastroyki/obsluzhivanie'            => fn () => admin_maintenance(),
    ],
];

/**
 * Шаблон в регулярное выражение.
 * Экранируем КАЖДЫЙ кусок отдельно, а на месте {param} ставим группу:
 * если сначала применить preg_quote ко всей строке, скобки {} тоже
 * экранируются и подстановка параметров уже не сработает.
 */
function route_regex(string $pattern): string
{
    $parts = preg_split('/(\{[a-z_]+\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);
    $regex = '';

    foreach ($parts as $part) {
        $regex .= str_starts_with($part, '{')
            ? '([^/]+)'
            : preg_quote($part, '#');
    }

    return '#^' . $regex . '$#u';
}

foreach ($routes[$method] ?? [] as $pattern => $handler) {
    if (preg_match(route_regex($pattern), $path, $matches)) {
        array_shift($matches);
        $handler(...$matches);
        exit;
    }
}

page_404();
