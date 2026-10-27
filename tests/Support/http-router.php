<?php

declare(strict_types=1);

// Solo servidor local de pruebas; jamás se publica bajo /public.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (is_string($path) && $path !== '/' && realpath(APP_ROOT . '/public' . $path) !== false) {
    $file = realpath(APP_ROOT . '/public' . $path);
    if (str_starts_with($file, realpath(APP_ROOT . '/public') . DIRECTORY_SEPARATOR)
        && is_file($file) && strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
        return false;
    }
}

ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
(new App\Core\Application(APP_ROOT, App\Tests\Support\TestConfig::values(), bin2hex(random_bytes(8))))->run();
