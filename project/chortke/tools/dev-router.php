<?php
// Development router for PHP built-in server with APP_BASE_PATH=/chortke.
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = $uri;
if (str_starts_with($path, '/chortke/')) {
    $path = substr($path, strlen('/chortke'));
} elseif ($path === '/chortke') {
    $path = '/';
}
$public = realpath(__DIR__ . '/../public') ?: (__DIR__ . '/../public');
$file = $public . $path;
if ($path !== '/' && is_file($file)) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
    readfile($file);
    return true;
}
require $public . '/index.php';
