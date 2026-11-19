<?php
define('ROMANTIZA_APP', true);
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

/**
 * Mapa del sitio (sitemap.xml) generado dinámicamente, para que Google
 * siempre vea la lista actualizada de productos y categorías activas
 * sin que haga falta tocar este archivo a mano. Se referencia desde
 * robots.txt.
 */

$base = defined('SITE_URL') ? rtrim(SITE_URL, '/') : ('https://' . ($_SERVER['HTTP_HOST'] ?? 'www.romantiza.com.ar'));

header('Content-Type: application/xml; charset=UTF-8');

$urls = [];

// Páginas fijas del sitio público.
$urls[] = ['loc' => $base . '/index.php', 'priority' => '1.0', 'changefreq' => 'weekly'];
$urls[] = ['loc' => $base . '/tienda.php', 'priority' => '0.9', 'changefreq' => 'daily'];
$urls[] = ['loc' => $base . '/nosotros.php', 'priority' => '0.5', 'changefreq' => 'monthly'];
$urls[] = ['loc' => $base . '/contacto.php', 'priority' => '0.5', 'changefreq' => 'monthly'];

// Categorías activas.
try {
    $cats = $pdo->query('SELECT slug FROM categories ORDER BY slug')->fetchAll();
    foreach ($cats as $c) {
        if (!empty($c['slug'])) {
            $urls[] = ['loc' => $base . '/tienda.php?categoria=' . rawurlencode($c['slug']), 'priority' => '0.7', 'changefreq' => 'weekly'];
        }
    }
} catch (PDOException $e) {
    // Si la tabla no existiera todavía, seguimos con lo que ya tenemos.
}

// Productos activos.
try {
    $products = $pdo->query("SELECT slug, updated_at FROM products WHERE active = 1 ORDER BY slug")->fetchAll();
    foreach ($products as $p) {
        if (!empty($p['slug'])) {
            $entry = ['loc' => $base . '/producto.php?slug=' . rawurlencode($p['slug']), 'priority' => '0.8', 'changefreq' => 'weekly'];
            if (!empty($p['updated_at'])) {
                $entry['lastmod'] = date('Y-m-d', strtotime($p['updated_at']));
            }
            $urls[] = $entry;
        }
    }
} catch (PDOException $e) {
    // Si la columna updated_at no existiera, reintentamos sin ella.
    try {
        $products = $pdo->query("SELECT slug FROM products WHERE active = 1 ORDER BY slug")->fetchAll();
        foreach ($products as $p) {
            if (!empty($p['slug'])) {
                $urls[] = ['loc' => $base . '/producto.php?slug=' . rawurlencode($p['slug']), 'priority' => '0.8', 'changefreq' => 'weekly'];
            }
        }
    } catch (PDOException $e2) {
        // Sin productos disponibles: el sitemap queda solo con las páginas fijas.
    }
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo '  <url>' . "\n";
    echo '    <loc>' . htmlspecialchars($u['loc'], ENT_XML1, 'UTF-8') . '</loc>' . "\n";
    if (!empty($u['lastmod'])) {
        echo '    <lastmod>' . h($u['lastmod']) . '</lastmod>' . "\n";
    }
    echo '    <changefreq>' . h($u['changefreq']) . '</changefreq>' . "\n";
    echo '    <priority>' . h($u['priority']) . '</priority>' . "\n";
    echo '  </url>' . "\n";
}
echo '</urlset>' . "\n";
