<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PDO;
use RuntimeException;

/**
 * Recrea y limpia el esquema de `gestion_test`.
 *
 * - `rebuild()` borra todas las tablas y aplica `database/migrations/*.sql` en
 *   orden más los seeds iniciales: demuestra que el esquema se puede crear
 *   desde cero (§19.H, «migraciones desde cero»).
 * - `truncateDataTables()` deja la base utilizable antes de cada prueba y
 *   conserva `schema_migrations`, `roles`, `settings` y `sequences`.
 */
final class SchemaTool
{
    /** Tablas de catálogo/configuración que no se truncan entre pruebas. */
    public const PRESERVED = ['schema_migrations', 'roles', 'settings', 'sequences'];

    /** Tablas que el esquema debe contener en fase 1 (§14). */
    public const EXPECTED_TABLES = [
        'api_tokens',
        'audit_logs',
        'clients',
        'expenses',
        'extras',
        'idempotency_requests',
        'login_attempts',
        'meeting_invitations',
        'meetings',
        'notifications',
        'password_resets',
        'payments',
        'product_extras',
        'products',
        'project_charges',
        'project_members',
        'project_stages',
        'projects',
        'refunds',
        'reservations',
        'roles',
        'sale_item_extras',
        'sale_items',
        'sales',
        'schema_migrations',
        'sequences',
        'settings',
        'space_blocks',
        'space_hours',
        'space_occupancies',
        'spaces',
        'tasks',
        'task_comments',
        'users',
    ];

    public static function rebuild(PDO $pdo): void
    {
        self::dropAllTables($pdo);
        self::applyMigrations($pdo);
        self::applySeeds($pdo);
    }

    /**
     * @return list<string>
     */
    public static function tables(PDO $pdo): array
    {
        $statement = $pdo->query(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name'
        );

        /** @var list<string> $tables */
        $tables = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_COLUMN);

        return $tables;
    }

    public static function truncateDataTables(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach (self::tables($pdo) as $table) {
                if (in_array($table, self::PRESERVED, true)) {
                    continue;
                }

                $pdo->exec('TRUNCATE TABLE `' . $table . '`');
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private static function dropAllTables(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach (self::tables($pdo) as $table) {
                $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private static function applyMigrations(PDO $pdo): void
    {
        $files = glob(APP_ROOT . '/database/migrations/*.sql') ?: [];
        sort($files);

        if ($files === []) {
            throw new RuntimeException('No hay migraciones en database/migrations.');
        }

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

        $insert = $pdo->prepare(
            'INSERT INTO schema_migrations (version, filename, checksum) VALUES (?, ?, ?)'
        );

        foreach ($files as $file) {
            $sql = file_get_contents($file);

            if ($sql === false) {
                throw new RuntimeException('No se pudo leer ' . basename($file));
            }

            $pdo->exec($sql);
            $insert->execute([substr(basename($file), 0, 3), basename($file), hash('sha256', $sql)]);
        }
    }

    private static function applySeeds(PDO $pdo): void
    {
        $files = glob(APP_ROOT . '/database/seeds/*.sql') ?: [];
        sort($files);

        foreach ($files as $file) {
            $sql = file_get_contents($file);

            if ($sql === false) {
                throw new RuntimeException('No se pudo leer ' . basename($file));
            }

            $pdo->exec($sql);
        }
    }
}
