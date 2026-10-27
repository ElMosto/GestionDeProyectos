<?php

declare(strict_types=1);

/**
 * Aplica los seeds iniciales (idempotentes). Uso:
 *   php bin/seed.php [--database=nombre]
 */

use App\Core\Config;
use App\Core\Database;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$options = getopt('', ['database::']);

$configFile = $root . '/config/config.php';

if (!is_file($configFile)) {
    fwrite(STDERR, "Falta config/config.php.\n");
    exit(1);
}

$values = require $configFile;

if (isset($options['database']) && is_string($options['database']) && $options['database'] !== '') {
    $values['db']['database'] = $options['database'];
}

$pdo = (new Database(new Config($values)))->pdo();

$files = glob($root . '/database/seeds/*.sql') ?: [];
sort($files);

foreach ($files as $file) {
    $sql = file_get_contents($file);

    if ($sql === false) {
        fwrite(STDERR, 'No se pudo leer ' . basename($file) . "\n");
        exit(1);
    }

    try {
        $pdo->exec($sql);
        fwrite(STDOUT, 'OK ' . basename($file) . "\n");
    } catch (Throwable $e) {
        fwrite(STDERR, 'FALLÓ ' . basename($file) . ': ' . $e->getMessage() . "\n");
        exit(1);
    }
}

fwrite(STDOUT, "Seeds aplicados.\n");
