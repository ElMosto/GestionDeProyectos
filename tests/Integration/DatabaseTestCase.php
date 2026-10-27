<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\ApiException;
use App\Core\Container;
use App\Tests\Support\Fixtures;
use App\Tests\Support\InProcessApi;
use App\Tests\Support\SchemaTool;
use App\Tests\Support\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Base de las pruebas de integración contra MariaDB real (`gestion_test`).
 *
 * Aislamiento: el esquema se recrea una vez por proceso desde `database/migrations`
 * y, antes de **cada** prueba, se truncan todas las tablas de datos. Se conservan
 * `schema_migrations`, `roles`, `settings` y `sequences`.
 */
abstract class DatabaseTestCase extends TestCase
{
    private static bool $schemaBuilt = false;

    protected PDO $pdo;
    protected Container $container;
    protected Fixtures $fixtures;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        TestDatabase::databaseName();

        if (!self::$schemaBuilt) {
            SchemaTool::rebuild(TestDatabase::pdo());
            self::$schemaBuilt = true;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Ninguna prueba hereda la sesión PHP de la anterior.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }

        $_SESSION = [];
        $_COOKIE = [];
        $_FILES = [];

        $this->resetSessionDirectory();

        $this->pdo = TestDatabase::pdo();
        SchemaTool::truncateDataTables($this->pdo);

        $this->container = InProcessApi::container();
        $this->fixtures = new Fixtures($this->pdo);
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }

        parent::tearDown();
    }

    /**
     * @template T of object
     * @param class-string<T> $service
     * @return T
     */
    protected function service(string $service): object
    {
        /** @var T $instance */
        $instance = $this->container->get($service);

        return $instance;
    }

    /**
     * @param callable():mixed $action
     */
    protected function assertApiException(callable $action, int $expectedStatus, string $expectedCode): ApiException
    {
        try {
            $action();
        } catch (ApiException $exception) {
            $this->assertSame($expectedStatus, $exception->status, 'Código HTTP inesperado: ' . $exception->getMessage());
            $this->assertSame($expectedCode, $exception->errorCode);

            return $exception;
        } catch (Throwable $throwable) {
            $this->fail(sprintf(
                'Se esperaba ApiException %d %s y llegó %s: %s',
                $expectedStatus,
                $expectedCode,
                $throwable::class,
                $throwable->getMessage()
            ));
        }

        $this->fail(sprintf('Se esperaba ApiException %d %s y no se lanzó ninguna.', $expectedStatus, $expectedCode));
    }

    /**
     * Falla si el valor serializado contiene una contraseña en claro o un hash.
     *
     * @param list<string> $secrets
     */
    protected function assertNoSecrets(mixed $payload, array $secrets, string $context = ''): void
    {
        $json = is_string($payload) ? $payload : (string) json_encode($payload, JSON_UNESCAPED_UNICODE);

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $json, 'Se filtró un secreto en ' . ($context !== '' ? $context : 'la respuesta.'));
        }

        $this->assertStringNotContainsString('password_hash', $json, 'El hash nunca debe serializarse.');
        $this->assertDoesNotMatchRegularExpression('/\$2[aby]\$/', $json, 'Se filtró un hash bcrypt.');
    }

    private function resetSessionDirectory(): void
    {
        $directory = (string) ini_get('session.save_path');

        if ($directory === '' || !is_dir($directory)) {
            return;
        }

        // `session.save_path` apunta a un directorio dedicado a PHPUnit (bootstrap),
        // así que borrar los archivos de sesión no afecta al entorno de desarrollo.
        foreach (glob($directory . DIRECTORY_SEPARATOR . 'sess_*') ?: [] as $file) {
            @unlink($file);
        }
    }
}
