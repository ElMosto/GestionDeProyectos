<?php

declare(strict_types=1);

/**
 * Front controller. Único punto de entrada HTTP.
 * Todo lo demás (config, src, vendor, storage) vive fuera de la raíz pública.
 */

use App\Core\Application;

$root = dirname(__DIR__);
$requestId = bin2hex(random_bytes(8));

// Una API nunca devuelve trazas al cliente; los errores van al log privado.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', $root . '/storage/logs/php-error.log');

header('X-Request-Id: ' . $requestId);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store');

$fail = static function (int $status, string $code, string $message) use ($requestId): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        ['error' => ['code' => $code, 'message' => $message], 'request_id' => $requestId],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
};

if (!is_file($root . '/vendor/autoload.php')) {
    $fail(503, 'NOT_INSTALLED', 'Faltan las dependencias. Ejecuta composer install.');
}

if (!is_file($root . '/config/config.php')) {
    $fail(
        503,
        'NOT_CONFIGURED',
        'Falta config/config.php. Copia config/config.example.php y rellena los datos.'
    );
}

require $root . '/vendor/autoload.php';

$config = require $root . '/config/config.php';

(new Application($root, $config, $requestId))->run();
