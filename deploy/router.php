<?php
/**
 * Router for the PHP built-in server (local development/testing only).
 *
 * Serves everything from a SINGLE origin, like production:
 *   - /api/config           -> api/metrics.php   (POST save/update setup, GET read setup)
 *   - /api/metrics*         -> api/metrics.php   (backend, GET/POST/DELETE)
 *   - everything else       -> frontend/dist/    (Vite build of the dashboard)
 *
 * Usage:
 *   php -S 0.0.0.0:8888 -t deploy deploy/router.php
 *
 * NOTE: the built-in server does NOT process .htaccess files (no mod_rewrite
 * nor Basic Auth): locally the GETs are open. In production on Apache the
 * deploy/ .htaccess files apply (rewrite + Basic Auth + deny on data/).
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = $uri === false ? '/' : $uri;
$norm = rtrim($uri, '/');
if ($norm === '') {
    $norm = '/';
}

// Protection: never serve data/ (config.php, kpi.sqlite) nor "hidden" paths
if ($norm === '/data' || strpos($norm, '/data/') === 0 || strpos($norm, '/.') !== false || strpos($norm, '..') !== false) {
    http_response_code(404);
    exit('Not found');
}

/* ------------------------------------------------------------------ *
 *  1) API: /api/metrics, /api/metrics/latest, /api/config,
 *          /api/config/metrics, /api/config/metrics/{key}
 * ------------------------------------------------------------------ */
if (preg_match('#^/api/(config/metrics/[^/]+|config/metrics|metrics/latest|metrics|config)$#', $norm)) {
    // metrics.php routes on the REQUEST_URI suffix; keep the original path.
    $_SERVER['REQUEST_URI'] = $norm;
    require __DIR__ . '/api/metrics.php';
    return true;
}

/* ------------------------------------------------------------------ *
 *  2) Frontend: Vite build (frontend/dist/)
 * ------------------------------------------------------------------ */
$dist = dirname(__DIR__) . '/frontend/dist';
$file = $dist . ($norm === '/' ? '/index.html' : $norm);

if (!is_file($file) || !is_readable($file)) {
    // SPA fallback -> index.html
    $file = $dist . '/index.html';
}

if (is_file($file)) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = [
        'html'  => 'text/html; charset=utf-8',
        'js'    => 'application/javascript; charset=utf-8',
        'css'   => 'text/css; charset=utf-8',
        'json'  => 'application/json',
        'svg'   => 'image/svg+xml',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'map'   => 'application/json',
        'txt'   => 'text/plain; charset=utf-8',
    ];
    header('Content-Type: ' . ($mime[$ext] ?? 'application/octet-stream'));
    readfile($file);
    return true;
}

http_response_code(404);
exit('Not found');
