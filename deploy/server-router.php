<?php
// Dev-router voor `php -S`: serveer bestaande statische bestanden direct,
// stuur al het andere naar de Sulu front controller (admin + frontend).
$root = __DIR__ . '/../public';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ('/' !== $path && is_file($root . $path)) {
    return false;
}
require $root . '/index.php';
