<?php /** @var string $content */ ?>
<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#f5661e">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="base-url" content="<?= e(cfg('base_url')) ?>">
<?php
    $meta = array_merge(meta_defaults(), $meta ?? []);

    if (isset($title))       { $meta['title'] = $title; }
    if (isset($description)) { $meta['description'] = $description; }

    // Канонический адрес собирает meta.php: там решают, какие параметры
    // оставить (page, vid), а какие отбросить. Страницы без собственных
    // мета-данных получают адрес без параметров — так было и раньше.
    $canonical = $meta['canonical'] ?? strtok((string) $meta['url'], '?');
?>
    <title><?= e($meta['title']) ?></title>
    <meta name="description" content="<?= e($meta['description']) ?>">
    <link rel="canonical" href="<?= e($canonical) ?>">
    <meta name="robots" content="<?= e($meta['robots'] ?? 'index, follow') ?>">
<?php if (! empty($meta['prev'])): ?>
    <link rel="prev" href="<?= e($meta['prev']) ?>">
<?php endif; ?>
<?php if (! empty($meta['next'])): ?>
    <link rel="next" href="<?= e($meta['next']) ?>">
<?php endif; ?>

    <meta property="og:site_name" content="<?= e(setting('site_name', cfg('app_name'))) ?>">
    <meta property="og:title" content="<?= e($meta['title']) ?>">
    <meta property="og:description" content="<?= e($meta['description']) ?>">
    <meta property="og:type" content="<?= e($meta['type']) ?>">
    <meta property="og:url" content="<?= e($canonical) ?>">
    <meta property="og:image" content="<?= e($meta['image']) ?>">
    <meta property="og:locale" content="ru_RU">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e($meta['title']) ?>">
    <meta name="twitter:description" content="<?= e($meta['description']) ?>">
    <meta name="twitter:image" content="<?= e($meta['image']) ?>">

    <?php if (! empty($meta['thread'])) { echo meta_offer_json($meta['thread']); } ?>
    <link rel="icon" href="<?= e(asset('/favicon.ico')) ?>" sizes="48x48">
    <link rel="icon" href="<?= e(asset('/favicon-32x32.png')) ?>" type="image/png" sizes="32x32">
    <link rel="icon" href="<?= e(asset('/favicon-16x16.png')) ?>" type="image/png" sizes="16x16">
    <link rel="apple-touch-icon" href="<?= e(asset('/apple-touch-icon.png')) ?>" sizes="180x180">
    <link rel="manifest" href="<?= e(asset('/site.webmanifest')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/css/app.css')) ?>">
    <script>
        window.PROMIKI_UPLOAD_LIMIT = <?= upload_limit_bytes() ?>;

        (function () {
            var saved = localStorage.getItem('promiki-theme');
            if (saved) { document.documentElement.dataset.theme = saved; }
        })();
    </script>
</head>
<body>

<?php require __DIR__ . '/partials/header.php'; ?>

<main class="container">
    <?php foreach (take_flash() as $item): ?>
        <div class="alert <?= $item['type'] === 'error' ? 'alert--error' : '' ?>" style="margin-top:16px"><?= e($item['message']) ?></div>
    <?php endforeach; ?>

    <?= $content ?>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>
<?php require __DIR__ . '/partials/mobile-nav.php'; ?>
<?php require __DIR__ . '/partials/cookie.php'; ?>

<script src="<?= e(asset('/js/app.js')) ?>"></script>

<?php if (setting('metrika_id')): ?>
    <?php /* счётчик подключается только после согласия — этим управляет app.js */ ?>
    <script>
        window.PROMIKI_METRIKA = <?= (int) setting('metrika_id') ?>;
    </script>
    <noscript><div><img src="https://mc.yandex.ru/watch/<?= (int) setting('metrika_id') ?>" style="position:absolute;left:-9999px" alt=""></div></noscript>
<?php endif; ?>
</body>
</html>
