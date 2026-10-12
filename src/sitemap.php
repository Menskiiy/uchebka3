<?php
/**
 * Карта сайта. Собирается на лету — отдельного файла на диске нет,
 * поэтому она не устаревает и не требует перегенерации по расписанию.
 */

function page_sitemap(): void
{
    $base = rtrim(setting('site_url', site_url()), '/');

    header('Content-Type: application/xml; charset=utf-8');

    $urls = [
        ['loc' => $base . url('/'),                'freq' => 'hourly', 'pri' => '1.0'],
        ['loc' => $base . url('/skidki'),          'freq' => 'hourly', 'pri' => '0.9'],
        ['loc' => $base . url('/promokody'),       'freq' => 'hourly', 'pri' => '0.9'],
        ['loc' => $base . url('/kategorii'),       'freq' => 'weekly', 'pri' => '0.6'],
        ['loc' => $base . url('/magaziny'),        'freq' => 'weekly', 'pri' => '0.6'],
        ['loc' => $base . url('/obsuzhdeniya'),    'freq' => 'daily',  'pri' => '0.6'],
        ['loc' => $base . url('/dokumenty'), 'freq' => 'yearly', 'pri' => '0.3'],

        // правовые документы: адреса постоянные, меняются редко,
        // но в поиске быть должны — на них ссылаются и с них ссылаются
        ...array_map(
            static fn (array $page) => ['loc' => $base . url($page['url']), 'freq' => 'yearly', 'pri' => '0.2'],
            array_values(legal_pages())
        ),
    ];

    foreach (q('SELECT slug FROM categories ORDER BY position') as $row) {
        $urls[] = ['loc' => $base . url('/kategoriya/' . rawurlencode($row['slug'])), 'freq' => 'daily', 'pri' => '0.5'];
    }

    foreach (q('SELECT slug FROM merchants ORDER BY name') as $row) {
        $urls[] = ['loc' => $base . url('/magazin/' . rawurlencode($row['slug'])), 'freq' => 'daily', 'pri' => '0.7'];
    }

    foreach (q('SELECT slug FROM groups_list ORDER BY position') as $row) {
        $urls[] = ['loc' => $base . url('/obsuzhdeniya/' . rawurlencode($row['slug'])), 'freq' => 'daily', 'pri' => '0.5'];
    }

    // истёкшие предложения не отдаём: поисковику незачем вести людей на мёртвую акцию
    $threads = q("SELECT t.slug, t.published_at, t.type, m.slug AS merchant_slug
                  FROM threads t
                  LEFT JOIN merchants m ON m.id = t.merchant_id
                  WHERE t.status = 'published' AND (t.expires_at IS NULL OR t.expires_at > ?)
                  ORDER BY t.published_at DESC LIMIT 5000", [now()]);

    foreach ($threads as $row) {
        $urls[] = [
            'loc'  => $base . thread_url($row),
            'freq' => $row['type'] === 'blog' ? 'monthly' : 'daily',
            'pri'  => $row['type'] === 'blog' ? '0.7' : '0.8',
            'date' => $row['published_at'],
        ];
    }

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    foreach ($urls as $u) {
        echo '  <url><loc>' . htmlspecialchars($u['loc'], ENT_XML1) . '</loc>';

        if (! empty($u['date'])) {
            echo '<lastmod>' . date('Y-m-d', strtotime($u['date'])) . '</lastmod>';
        }

        echo '<changefreq>' . $u['freq'] . '</changefreq>';
        echo '<priority>' . $u['pri'] . '</priority></url>' . "\n";
    }

    echo '</urlset>';
    exit;
}
