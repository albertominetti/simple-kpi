<?php
/**
 * Router per il PHP built-in server (solo sviluppo/test locale).
 *
 * Serve su una SINGOLA origin, come in produzione:
 *   - /api/config           -> api/metriche.php   (POST invia/aggiorna setup, GET recupera setup)
 *   - /api/metriche*        -> api/metriche.php   (backend, POST/GET)
 *   - tutto il resto        -> frontend/dist/     (build Vite del dashboard)
 *
 * Uso:
 *   php -S 0.0.0.0:8888 -t deploy deploy/router.php
 *
 * NOTA: il built-in server NON processa i .htaccess (niente mod_rewrite né
 * Basic Auth): in locale le GET risultano aperte. In produzione su Apache
 * valgono i .htaccess di deploy/ (rewrite + Basic Auth + deny su data/).
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = $uri === false ? '/' : $uri;
$norm = rtrim($uri, '/');
if ($norm === '') {
    $norm = '/';
}

// Protezione: mai servire data/ (config.php, kpi.sqlite) né percorsi "nascosti"
if ($norm === '/data' || strpos($norm, '/data/') === 0 || strpos($norm, '/.') !== false || strpos($norm, '..') !== false) {
    http_response_code(404);
    exit('Not found');
}

/* ------------------------------------------------------------------ *
 *  1) API: /api/metriche, /api/metriche/latest, /api/config
 * ------------------------------------------------------------------ */
if (preg_match('#^/api/(metriche(/latest)?|config)$#', $norm, $m)) {
    if ($m[1] === 'config') {
        $_SERVER['REQUEST_URI'] = '/api/config';
    } else {
        $_SERVER['REQUEST_URI'] = !empty($m[2]) ? '/api/metriche/latest' : '/api/metriche';
    }
    require __DIR__ . '/api/metriche.php';
    return true;
}

/* ------------------------------------------------------------------ *
 *  2) Frontend: build Vite (frontend/dist/)
 * ------------------------------------------------------------------ */
$dist = dirname(__DIR__) . '/frontend/dist';
$file = $dist . ($norm === '/' ? '/index.html' : $norm);

if (!is_file($file) || !is_readable($file)) {
    // fallback SPA -> index.html
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
