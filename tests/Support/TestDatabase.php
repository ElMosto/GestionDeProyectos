<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Core\Database;
use LogicException;
use PDO;

/**
 * Conexión a la base de pruebas dedicada (`gestion_test`).
 *
 * Usa `App\Core\Database` para heredar exactamente el mismo modo estricto y la
 * zona UTC que la aplicación. Nunca apunta a la base `gestion`.
 */
final class TestDatabase
{
    private static ?Database $instance = null;

    public static function instance(): Database
    {
        return self::$instance ??= new Database(TestConfig::config());
    }

    public static function pdo(): PDO
    {
        return self::instance()->pdo();
    }

    /**
     * Conexión independiente para pruebas que necesitan dos sesiones reales
     * (bloqueos `FOR UPDATE`, carreras de versión).
     */
    public static function newConnection(): PDO
    {
        return (new Database(TestConfig::config()))->pdo();
    }

    public static function databaseName(): string
    {
        $name = (string) self::pdo()->query('SELECT DATABASE()')->fetchColumn();

        if ($name !== TEST_DATABASE) {
            throw new LogicException(sprintf(
                'Las pruebas deben ejecutarse contra "%s"; la conexión apunta a "%s".',
                TEST_DATABASE,
                $name
            ));
        }

        return $name;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
