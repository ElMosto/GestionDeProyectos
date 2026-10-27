<?php

declare(strict_types=1);

/**
 * Bootstrap de PHPUnit.
 *
 * La suite NUNCA toca la base de desarrollo: `TestConfig` sobrescribe
 * `db.database` con `gestion_test` en memoria. `config/config.php` no se modifica.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

if (!defined('TESTS_ROOT')) {
    define('TESTS_ROOT', __DIR__);
}

if (!defined('TEST_DATABASE')) {
    define('TEST_DATABASE', 'gestion_test');
}

// Sesiones aisladas de las del entorno de desarrollo (no comparten save_path).
$sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gestion-phpunit-sessions';

if (!is_dir($sessionDir) && !mkdir($sessionDir, 0777, true) && !is_dir($sessionDir)) {
    fwrite(STDERR, "No se pudo crear el directorio de sesiones de prueba: {$sessionDir}\n");
}

ini_set('session.save_path', $sessionDir);
ini_set('session.gc_probability', '0');
