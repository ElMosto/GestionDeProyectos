<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\SchemaTool;
use PDOException;

/**
 * Integridad del esquema MariaDB (§14, §19.H).
 *
 * Comprueba `information_schema` sobre la base real: motor, claves foráneas,
 * restricciones CHECK, codificación y que los CHECK se apliquen de verdad.
 */
final class SchemaIntegrityTest extends DatabaseTestCase
{
    public function test_H_all_35_tables_exist_and_use_innodb(): void
    {
        // §14 — todas las tablas son InnoDB: sin transacciones no hay reservas ni
        // pagos seguros (fase 3-4), así que esto es un requisito de la base.
        $statement = $this->pdo->query(
            "SELECT table_name, engine FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name"
        );
        $rows = $statement === false ? [] : $statement->fetchAll(\PDO::FETCH_NUM);

        $this->assertCount(35, $rows, 'Las fases 1–6 incluyen 35 tablas, con historia comercial.');
        // information_schema ordena con la colación del servidor (ignora algunos
        // signos); comparar conjuntos en orden PHP evita depender de esa colación.
        $names = array_column($rows, 0);
        sort($names);
        $expected = SchemaTool::EXPECTED_TABLES;
        sort($expected);
        $this->assertSame($expected, $names);

        foreach ($rows as [$table, $engine]) {
            $this->assertSame('InnoDB', $engine, 'La tabla ' . $table . ' no es InnoDB.');
        }
    }

    public function test_H_no_table_outside_innodb_and_all_utf8mb4(): void
    {
        // §14 — utf8mb4 en todas las tablas y ninguna tabla con otro motor.
        $statement = $this->pdo->query(
            "SELECT table_name, engine, table_collation FROM information_schema.tables"
            . " WHERE table_schema = DATABASE()"
        );
        $rows = $statement === false ? [] : $statement->fetchAll(\PDO::FETCH_NUM);

        foreach ($rows as [$table, $engine, $collation]) {
            $this->assertSame('InnoDB', $engine, 'Motor inesperado en ' . $table);
            $this->assertSame('utf8mb4_unicode_ci', $collation, 'Codificación inesperada en ' . $table);
        }

        $otherEngines = $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables"
            . " WHERE table_schema = DATABASE() AND engine <> 'InnoDB'"
        );

        $this->assertSame(0, (int) $otherEngines->fetchColumn());
    }

    public function test_H_the_schema_declares_foreign_keys(): void
    {
        // §14 — integridad referencial declarada en la base, no solo en PHP.
        $count = $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.table_constraints"
            . " WHERE table_schema = DATABASE() AND constraint_type = 'FOREIGN KEY'"
        );

        $this->assertGreaterThanOrEqual(63, (int) $count->fetchColumn());

        $expected = [
            ['users', 'fk_users_role'],
            ['clients', 'fk_clients_created_by'],
            ['audit_logs', 'fk_audit_logs_actor'],
            ['api_tokens', 'fk_api_tokens_user'],
            ['projects', 'fk_projects_leader'],
            ['reservations', 'fk_reservations_sale'],
            ['payments', 'fk_payments_sale'],
        ];

        foreach ($expected as [$table, $constraint]) {
            $this->assertTrue(
                $this->hasConstraint($table, $constraint, 'FOREIGN KEY'),
                sprintf('Falta la clave foránea %s en %s.', $constraint, $table)
            );
        }
    }

    public function test_H_the_schema_declares_check_constraints(): void
    {
        // §14 — los CHECK son la última defensa de las reglas de negocio.
        $count = $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.table_constraints"
            . " WHERE table_schema = DATABASE() AND constraint_type = 'CHECK'"
        );

        $this->assertGreaterThanOrEqual(46, (int) $count->fetchColumn());

        $expected = [
            ['clients', 'chk_clients_band_data'],
            ['projects', 'chk_projects_external_document'],
            ['tasks', 'chk_tasks_progress'],
            ['tasks', 'chk_tasks_completed_progress'],
            ['space_occupancies', 'chk_space_occupancies_range'],
            ['sales', 'chk_sales_issued_at'],
            ['payments', 'chk_payments_amount'],
        ];

        foreach ($expected as [$table, $constraint]) {
            $this->assertTrue(
                $this->hasConstraint($table, $constraint, 'CHECK'),
                sprintf('Falta la restricción %s en %s.', $constraint, $table)
            );
        }
    }

    public function test_H_check_constraint_rejects_a_band_without_representative(): void
    {
        // §7 — la regla BANDA vive también en la base: sin representante no hay fila.
        $user = $this->fixtures->createUser('ADMINISTRADOR');

        $statement = $this->pdo->prepare(
            "INSERT INTO clients (type, name, phone, active, created_by, version) VALUES ('BANDA', ?, '555', 1, ?, 1)"
        );

        try {
            $statement->execute(['Banda sin representante', $user['id']]);
            $this->fail('La base aceptó una BANDA sin representante: el CHECK no se aplica.');
        } catch (PDOException $exception) {
            $this->assertStringContainsString('chk_clients_band_data', $exception->getMessage());
        }

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn());
    }

    public function test_H_check_constraint_rejects_an_external_project_without_client_or_amount(): void
    {
        // §8 / §19.B — proyecto externo sin cliente y sin importe es inválido también en la base.
        $user = $this->fixtures->createUser('ADMINISTRADOR');

        $statement = $this->pdo->prepare(
            "INSERT INTO projects (folio, type, leader_id, name, description, base_amount)"
            . " VALUES ('PRY-TEST-1', 'EXTERNO', ?, 'Externo inválido', 'Sin cliente', '0.00')"
        );

        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/chk_projects_external_document/');

        $statement->execute([$user['id']]);
    }

    public function test_H_unique_email_is_enforced_by_the_database(): void
    {
        // §5 — el correo es único también a nivel de motor, no solo en el servicio.
        $this->fixtures->createUser('COLABORADOR', 'unico@test.local');

        $statement = $this->pdo->prepare(
            'INSERT INTO users (role_id, name, email, password_hash, timezone, active, must_change_password, version)'
            . ' VALUES ((SELECT id FROM roles WHERE code = ?), ?, ?, ?, ?, 1, 1, 1)'
        );

        try {
            $statement->execute(['COLABORADOR', 'Duplicado', 'unico@test.local', password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]), 'UTC']);
            $this->fail('La base aceptó dos usuarios con el mismo correo.');
        } catch (PDOException $exception) {
            $this->assertStringContainsString('uq_users_email', $exception->getMessage());
        }

        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE email = 'unico@test.local'")->fetchColumn());
    }

    public function test_H_migrations_are_recorded_and_seeded_catalog_is_present(): void
    {
        // §14 / §19.H — migraciones versionadas y seeds mínimos (roles, settings, sequences).
        $versions = $this->pdo->query('SELECT version FROM schema_migrations ORDER BY version')
            ->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertSame(['001', '002', '003', '004', '005', '006', '007', '008', '009'], $versions);

        $roles = $this->pdo->query('SELECT code FROM roles ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['ADMINISTRADOR', 'LIDER_PROYECTO', 'COLABORADOR'], $roles);

        $this->assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn());
        $names = $this->pdo->query('SELECT name FROM sequences')->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['project', 'sale', 'payment', 'refund', 'expense'] as $seeded) {
            $this->assertContains($seeded, $names);
        }
        // La suite conserva secuencias para no reutilizar folios. Puede haber
        // contadores anuales creados por las pruebas previas, sin perder los seeds.
        $this->assertGreaterThanOrEqual(5, count($names));
    }

    public function test_H_audit_logs_table_is_append_only_by_design(): void
    {
        // §16 — la bitácora no tiene updated_at: editar una fila no está modelado.
        $columns = $this->pdo->query("SHOW COLUMNS FROM audit_logs")->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertContains('created_at', $columns);
        $this->assertNotContains('updated_at', $columns);
    }

    private function hasConstraint(string $table, string $constraint, string $type): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.table_constraints'
            . ' WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ? AND constraint_type = ?'
        );
        $statement->execute([$table, $constraint, $type]);

        return (int) $statement->fetchColumn() > 0;
    }
}
