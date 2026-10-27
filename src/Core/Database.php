<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use Throwable;

/**
 * Conexión PDO única. Fija el modo estricto y UTC: sin esto MariaDB acepta
 * cadenas truncadas, fechas imposibles y enums inválidos sin error.
 */
final class Database
{
    private ?PDO $pdo = null;

    /** @var callable|null */
    private $errorLogger = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function setErrorLogger(callable $logger): void
    {
        $this->errorLogger = $logger;
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) $this->config->require('db.host'),
            (int) $this->config->get('db.port', 3306),
            (string) $this->config->require('db.database'),
            (string) $this->config->get('db.charset', 'utf8mb4'),
        );

        $this->pdo = new PDO(
            $dsn,
            (string) $this->config->require('db.username'),
            (string) $this->config->get('db.password', ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, "
                    . 'time_zone = "+00:00", '
                    . "sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,"
                    . "ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
            ]
        );

        return $this->pdo;
    }

    /**
     * Ejecuta un bloque en transacción. Reintenta solo ante deadlock (1213) o
     * lock wait timeout (1205), que son fallos transitorios.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function transaction(callable $work, int $attempts = 3): mixed
    {
        $pdo = $this->pdo();
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $pdo->beginTransaction();

            try {
                $result = $work($pdo);
                $pdo->commit();

                return $result;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $lastError = $e;
                if ($attempt === $attempts || !$this->isRetryable($e)) {
                    throw $e;
                }
            }
        }

        throw $lastError ?? new PDOException('Transacción fallida.');
    }

    private function isRetryable(Throwable $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }

        // driver error code viene en el mensaje de errorInfo[1] o en el código SQLSTATE.
        $driverCode = $e->errorInfo[1] ?? null;

        return in_array($driverCode, [1205, 1213], true);
    }
}
