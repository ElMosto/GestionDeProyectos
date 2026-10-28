<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\SalesFixtures as F;
use App\Tests\Support\TestDatabase;
use PDO;
use RuntimeException;

/** §19.D / §11: actual overlapping requests; never a sequential approximation. */
final class FinancialConcurrencyTest extends DatabaseTestCase
{
    public function test_D_dos_cobros_simultaneos_600_sobre_1000_no_sobrepagan(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        $sale = $f->issued($admin);
        $request = $this->request('/api/v1/sales/' . $sale['id'] . '/payments', F::paymentBody('600.00'), $admin);
        $other = array_replace($request, ['headers' => F::key()]);
        $results = $this->overlap($sale['id'], [$request, $other]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        self::assertSame([201, 409], $statuses, json_encode($results));
        $failed = array_values(array_filter($results, fn (array $result): bool => $result['status'] === 409))[0];
        self::assertSame('OVERPAYMENT', $failed['payload']['error']['code']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn());
        self::assertSame('600.00', $this->pdo->query('SELECT SUM(amount) FROM payments')->fetchColumn());
    }

    public function test_D_misma_clave_en_dos_cobros_simultaneos_devuelve_mismo_201_una_fila(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        $sale = $f->issued($admin);
        $request = $this->request('/api/v1/sales/' . $sale['id'] . '/payments', F::paymentBody('600.00'), $admin);
        $results = $this->overlap($sale['id'], [$request, $request]);
        self::assertSame([201, 201], array_column($results, 'status'), json_encode($results));
        self::assertSame($results[0]['payload']['data'], $results[1]['payload']['data']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn());
    }

    public function test_D_reembolsos_simultaneos_no_exceden_pago_original(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        $sale = $f->issued($admin);
        $payment = F::pay($admin, $sale['id'], '1000.00')['payment'];
        $request = $this->request('/api/v1/payments/' . $payment['id'] . '/refunds', F::refundBody('600.00'), $admin);
        $results = $this->overlap($sale['id'], [$request, array_replace($request, ['headers' => F::key()])]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        self::assertSame([201, 409], $statuses, json_encode($results));
        $failed = array_values(array_filter($results, fn (array $result): bool => $result['status'] === 409))[0];
        self::assertSame('REFUND_EXCEEDS_PAYMENT', $failed['payload']['error']['code']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM refunds')->fetchColumn());
        self::assertSame('600.00', $this->pdo->query('SELECT SUM(amount) FROM refunds')->fetchColumn());
    }

    private function request(string $path, array $body, string $token): array
    {
        return ['database' => 'gestion_test', 'method' => 'POST', 'path' => $path,
            'body' => $body, 'headers' => F::key(), 'bearer' => $token, 'await_ack' => true];
    }

    /**
     * Parent holds document lock until MariaDB reports TWO executing lock statements.
     * This proves overlap, including an idempotency wait behind the first worker.
     * PROCESSLIST exposes the test account's own sessions without global PROCESS.
     * At least one child is executing SELECT sales FOR UPDATE against our held row;
     * the other is doing the same or waiting on that child's idempotency key.
     * Socket-backed output descriptors allow genuinely nonblocking reads on Windows;
     * Windows anonymous pipes ignore stream_set_blocking(false).
     */
    private function overlap(string $saleId, array $requests): array
    {
        $children = [];
        $monitor = TestDatabase::newConnection();
        $this->pdo->beginTransaction();
        $lock = $this->pdo->prepare('SELECT id FROM sales WHERE id = ? FOR UPDATE');
        $lock->execute([$saleId]);
        try {
            foreach ($requests as $request) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, APP_ROOT . '/tests/Support/financial-worker.php'], [
                    0 => ['pipe', 'r'], 1 => ['socket'], 2 => ['socket'],
                ], $pipes, APP_ROOT, null, ['bypass_shell' => true]);
                if (!is_resource($process)) {
                    throw new RuntimeException('No se pudo iniciar worker PHP de concurrencia real.');
                }
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $children[] = ['process' => $process, 'pipes' => $pipes, 'buffer' => '', 'stderr' => ''];
                fwrite($pipes[0], json_encode($request, JSON_THROW_ON_ERROR) . "\n");
                fflush($pipes[0]);
            }
            foreach ($children as &$child) {
                self::assertSame('READY', $this->line($child));
            }
            unset($child);
            foreach ($children as $child) {
                fwrite($child['pipes'][0], "GO\n");
                fflush($child['pipes'][0]);
            }
            foreach ($children as &$child) {
                self::assertSame('DISPATCH', $this->line($child));
            }
            unset($child);

            $this->awaitTwoDatabaseWaiters($monitor);
            $this->pdo->commit();
            $results = [];
            foreach ($children as &$child) {
                $results[] = json_decode($this->line($child), true, flags: JSON_THROW_ON_ERROR);
                fwrite($child['pipes'][0], "ACK\n");
                fflush($child['pipes'][0]);
            }
            unset($child);
            return $results;
        } finally {
            // Una excepción dentro del foreach por referencia deja el alias activo.
            // Liberarlo evita sobrescribir el último worker durante la limpieza.
            unset($child);
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            foreach ($children as $child) {
                foreach ($child['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($child['process'])) {
                    $status = proc_get_status($child['process']);
                    if ($status['running']) {
                        proc_terminate($child['process']);
                    }
                    proc_close($child['process']);
                }
            }
        }
    }

    private function awaitTwoDatabaseWaiters(PDO $monitor): void
    {
        $deadline = hrtime(true) + 15_000_000_000;
        $query = "SELECT INFO FROM information_schema.PROCESSLIST WHERE DB = 'gestion_test'"
            . " AND COMMAND IN ('Query', 'Execute') AND TIME_MS >= 10 AND ("
            . " INFO LIKE 'SELECT%FROM sales%FOR UPDATE%'"
            . " OR INFO LIKE 'INSERT INTO idempotency_requests%'"
            . " OR INFO LIKE 'SELECT%FROM idempotency_requests%FOR UPDATE%')";
        do {
            $activeLocks = $monitor->query($query)->fetchAll(PDO::FETCH_COLUMN);
            $saleLocks = array_filter($activeLocks, static fn (string $sql): bool => str_contains($sql, 'FROM sales'));
            if (count($activeLocks) >= 2 && count($saleLocks) >= 1) {
                self::assertTrue($this->pdo->inTransaction(), 'El lock padre debe seguir tomado al verificar concurrencia.');
                return;
            }
        } while (hrtime(true) < $deadline);
        self::fail('No se observaron dos transacciones simultáneas esperando locks en gestion_test en 15 s;'
            . ' la prueba NO se considera evidencia de concurrencia. Revisar orden de locks y workers Windows. '
            . json_encode($monitor->query("SELECT ID, DB, COMMAND, STATE, INFO FROM information_schema.PROCESSLIST WHERE DB='gestion_test'")->fetchAll(PDO::FETCH_ASSOC)));
    }

    private function line(array &$child): string
    {
        $deadline = hrtime(true) + 20_000_000_000;
        do {
            $child['buffer'] .= (string) stream_get_contents($child['pipes'][1]);
            $child['stderr'] .= (string) stream_get_contents($child['pipes'][2]);
            $newline = strpos($child['buffer'], "\n");
            if ($newline !== false) {
                $line = substr($child['buffer'], 0, $newline);
                $child['buffer'] = substr($child['buffer'], $newline + 1);
                return rtrim($line, "\r");
            }
            if (feof($child['pipes'][1])) {
                throw new RuntimeException('Worker terminó sin respuesta: ' . $child['stderr'] . $child['buffer']);
            }
            $read = [$child['pipes'][1], $child['pipes'][2]];
            $write = $except = null;
            stream_select($read, $write, $except, 0, 100_000);
        } while (hrtime(true) < $deadline);
        throw new RuntimeException('Timeout de 20 s en barrera/respuesta del worker: ' . $child['stderr']);
    }
}
