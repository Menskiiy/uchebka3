<?php
/**
 * Окно «скоро сгорит» — двое суток. Им пользуются вкладка ленты и дайджест
 * в каналы, поэтому значение одно на всех: разъехавшись, они показывали бы
 * разные наборы предложений под одним и тем же словом «сгорает».
 */
const EXPIRING_WINDOW = 172800;

/**
 * ПРОМИКИ.РУ — базовые настройки.
 *
 * Параметры подключения к базе задаются при установке и сохраняются
 * в data/config.local.php — этот файл правится мастером, а не руками.
 * Название сайта, описание и коэффициенты редактируются в админке.
 */

$config = [
    'app_name'        => 'ПРОМИКИ.РУ',
    'app_description' => 'Сообщество охотников за скидками: промокоды, акции и обсуждения.',

    // Пустая строка, если сайт лежит в корне домена. Для подпапки: '/promiki'
    'base_url' => '',

    'db' => [
        'driver'  => 'sqlite',              // sqlite | mysql
        'path'    => __DIR__ . '/../data/promiki.sqlite',
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => '',
        'user'    => '',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    'uploads' => __DIR__ . '/../public/storage',

    'per_page' => 15,

    'temperature' => [
        'hot_threshold'            => 100,
        'vote_base'                => 5,
        'vote_reputation_divisor'  => 200,
        'vote_max'                 => 15,
    ],

    'reputation' => [
        'per_publication'  => 1,
        'per_hot_vote'     => 2,
        'per_cold_vote'    => -1,
        'per_comment_like' => 1,
    ],

    'ranks' => [
        ['key' => 'observer', 'title' => 'Наблюдатель', 'min' => 0],
        ['key' => 'novice',   'title' => 'Новичок',     'min' => 50],
        ['key' => 'hunter',   'title' => 'Охотник',     'min' => 250],
        ['key' => 'expert',   'title' => 'Знаток',      'min' => 1000],
        ['key' => 'legend',   'title' => 'Легенда',     'min' => 5000],
    ],

    'thread_types' => [
        'deal'       => ['label' => 'Скидка',         'slug' => 'skidku',          'commercial' => true],
        'voucher'    => ['label' => 'Промокод',       'slug' => 'promokod',        'commercial' => true],
        'blog'       => ['label' => 'Блог',           'slug' => 'blog',            'commercial' => false],
        'discussion' => ['label' => 'Обсуждение',     'slug' => 'obsuzhdenie',     'commercial' => false],
        'feedback'   => ['label' => 'Обратная связь', 'slug' => 'obratnaya-svyaz', 'commercial' => false],
    ],

    'report_reasons' => [
        'spam'        => 'Спам или реклама',
        'duplicate'   => 'Дубликат существующей публикации',
        'fraud'       => 'Мошенничество или фишинг',
        'wrong_price' => 'Неверная цена или условия',
        'expired'     => 'Предложение больше не действует',
        'abuse'       => 'Оскорбления или враждебность',
        'other'       => 'Другое',
    ],

    'lifetime_days' => 30,
];

// Настройки подключения, записанные мастером установки
$local = __DIR__ . '/../data/config.local.php';

if (file_exists($local)) {
    $overrides = require $local;

    if (is_array($overrides)) {
        $config['db'] = array_merge($config['db'], $overrides['db'] ?? []);
    }
}

return $config;
