<?php

declare(strict_types=1);

/**
 * Aplica las migraciones pendientes en orden. Uso:
 *   php bin/migrate.php [--database=nombre] [--dry-run]
 */

use App\Core\Config;
use App\Core\Database;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$options = getopt('', ['database::', 'dry-run', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, "Uso: php bin/migrate.php [--database=nombre] [--dry-run]\n");
    exit(0);
}

$configFile = $root . '/config/config.php';

if (!is_file($configFile)) {
    fwrite(STDERR, "Falta config/config.php. Copia config/config.example.php.\n");
    exit(1);
}

$values = require $configFile;

if (isset($options['database']) && is_string($options['database']) && $options['database'] !== '') {
    $values['db']['database'] = $options['database'];
}

$database = new Database(new Config($values));
$pdo = $database->pdo();
$dryRun = isset($options['dry-run']);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        version VARCHAR(10) NOT NULL,
        filename VARCHAR(120) NOT NULL,
        checksum CHAR(64) NOT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_schema_migrations_version (version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$applied = [];
foreach ($pdo->query('SELECT version, filename, checksum FROM schema_migrations') ?: [] as $row) {
    $applied[$row['version']] = $row;
}

$files = glob($root . '/database/migrations/*.sql') ?: [];
sort($files);

$count = 0;

foreach ($files as $file) {
    $filename = basename($file);
    $version = substr($filename, 0, 3);
    $sql = file_get_contents($file);

    if ($sql === false) {
        fwrite(STDERR, "No se pudo leer {$filename}\n");
        exit(1);
    }

    $checksum = hash('sha256', $sql);

    if (isset($applied[$version])) {
        if (!hash_equals($applied[$version]['checksum'], $checksum)) {
            fwrite(STDERR, "ERROR: {$filename} cambió después de aplicarse (checksum distinto).\n");
            fwrite(STDERR, "Crea una migración nueva en lugar de editar una aplicada.\n");
            exit(1);
        }

        continue;
    }

    if ($dryRun) {
        fwrite(STDOUT, "[dry-run] aplicaría {$filename}\n");
        continue;
    }

    try {
        $pdo->exec($sql);
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (version, filename, checksum) VALUES (?, ?, ?)');
        $stmt->execute([$version, $filename, $checksum]);
        fwrite(STDOUT, "OK {$filename}\n");
        $count++;
    } catch (Throwable $e) {
        fwrite(STDERR, "FALLÓ {$filename}: {$e->getMessage()}\n");
        exit(1);
    }
}

fwrite(STDOUT, $count === 0 ? "Sin migraciones pendientes.\n" : "{$count} migración(es) aplicada(s).\n");
