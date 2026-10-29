<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\ReservationFixtures as F;
use App\Tests\Support\TestDatabase;
use PDO;
use RuntimeException;

/** §12 / §19.E: real simultaneous independent requests behind a held space mutex. */
final class ReservationConcurrencyTest extends DatabaseTestCase
{
    public function test_E_dos_reservas_simultaneas_mismo_hueco_201_409_una_venta(): void
    {
        [$space, $body, $admin] = $this->scenario();
        $request = $this->request('/api/v1/reservations', $body, $admin);
        $results = $this->overlap($space['id'], [$request, array_replace($request, ['headers' => F::key()])]);
        $this->assertWinnerAndConflict($results);
        $rows = F::snapshot($this->pdo);
        foreach (['reservations', 'sales', 'space_occupancies', 'sale_items'] as $table) {
            self::assertCount(1, $rows[$table]);
        }
        self::assertSame([], $rows['payments']);
        self::assertSame('180.00', $rows['sales'][0]['total']);
    }

    public function test_E_reservas_simultaneas_misma_clave_replay_una_fila(): void
    {
        [$space, $body, $admin] = $this->scenario();
        $request = $this->request('/api/v1/reservations', $body, $admin);
        $results = $this->overlap($space['id'], [$request, $request]);
        self::assertSame([201, 201], array_column($results, 'status'), json_encode($results));
        self::assertSame($results[0]['payload']['data'], $results[1]['payload']['data']);
        foreach (['reservations', 'sales', 'space_occupancies', 'sale_items'] as $table) {
            self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn());
        }
    }

    public function test_E_reserva_y_mantenimiento_simultaneos_comparten_mutex_solo_uno_gana(): void
    {
        [$space, $body, $admin] = $this->scenario();
        $results = $this->overlap($space['id'], [
            $this->request('/api/v1/reservations', $body, $admin),
            $this->request('/api/v1/space-blocks', F::blockBody($space), $admin),
        ]);
        $this->assertWinnerAndConflict($results);
        $rows = F::snapshot($this->pdo);
        self::assertCount(1, $rows['space_occupancies']);
        self::assertSame(1, count($rows['reservations']) + count($rows['space_blocks']));
        self::assertCount(count($rows['reservations']), $rows['sales']);
        self::assertSame([], $rows['payments']);
    }

    private function scenario(): array
    {
        $f = new F($this->fixtures);
        [$user, $admin] = $f->actor();
        $space = $f->space($admin);
        $band = $f->band((int) $user['id']);
        return [$space, F::body($band, $space), $admin];
    }

    private function request(string $path, array $body, string $token): array
    {
        return ['database' => 'gestion_test', 'method' => 'POST', 'path' => $path,
            'body' => $body, 'headers' => F::key(), 'bearer' => $token, 'await_ack' => true];
    }

    private function assertWinnerAndConflict(array $results): void
    {
        $statuses = array_column($results, 'status');
        sort($statuses);
        self::assertSame([201, 409], $statuses, json_encode($results));
        $loser = array_values(array_filter($results, static fn (array $r): bool => $r['status'] === 409))[0];
        self::assertSame('RESERVATION_CONFLICT', $loser['payload']['error']['code']);
    }

    /**
     * Same protocol as FinancialConcurrencyTest, but parent holds spaces rather than sales.
     * Actual database waiters (Query OR native prepared Execute) must be observed before release.
     * No sleep; socket readiness paces observation instead of an unbounded busy loop.
     */
    private function overlap(string $spaceId, array $requests): array
    {
        $children = [];
        $monitor = TestDatabase::newConnection();
        $baseline = array_map('intval', $monitor->query('SELECT ID FROM information_schema.PROCESSLIST')->fetchAll(PDO::FETCH_COLUMN));
        $this->pdo->beginTransaction();
        $lock = $this->pdo->prepare('SELECT id FROM spaces WHERE id = ? FOR UPDATE');
        $lock->execute([$spaceId]);
        try {
            foreach ($requests as $request) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, APP_ROOT . '/tests/Support/reservation-worker.php'], [
                    0 => ['pipe', 'r'], 1 => ['socket'], 2 => ['socket'],
                ], $pipes, APP_ROOT, null, ['bypass_shell' => true]);
                if (!is_resource($process)) {
                    throw new RuntimeException('No se pudo iniciar worker PHP de reservas.');
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
            $this->awaitTwoWaiters($monitor, $baseline, $children);
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

    private function awaitTwoWaiters(PDO $monitor, array $baseline, array &$children): void
    {
        $deadline = hrtime(true) + 15_000_000_000;
        $last = [];
        do {
            // Own-account sessions are visible without global PROCESS privileges.
            $last = $monitor->query('SELECT ID, DB, COMMAND, TIME_MS, STATE, INFO FROM information_schema.PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC);
            $waiters = [];
            $spaces = 0;
            foreach ($last as $row) {
                if ($row['DB'] !== 'gestion_test' || in_array((int) $row['ID'], $baseline, true)
                    || !in_array($row['COMMAND'], ['Query', 'Execute'], true) || (float) $row['TIME_MS'] < 10) {
                    continue;
                }
                $sql = (string) $row['INFO'];
                $spaceLock = preg_match('/^SELECT\b.*\bFROM\s+`?spaces`?\b.*\bFOR\s+UPDATE\b/is', $sql) === 1;
                $keyLock = preg_match('/^(?:INSERT\s+INTO\s+`?idempotency_requests`?|SELECT\b.*\bFROM\s+`?idempotency_requests`?\b.*\bFOR\s+UPDATE\b)/is', $sql) === 1;
                if ($spaceLock || $keyLock) {
                    $waiters[] = $row;
                    $spaces += $spaceLock ? 1 : 0;
                }
            }
            if (count($waiters) >= 2 && $spaces >= 1) {
                self::assertTrue($this->pdo->inTransaction(), 'Mutex del espacio todavía retenido al demostrar concurrencia.');
                return;
            }
            foreach ($children as &$child) {
                $this->drain($child);
                if (feof($child['pipes'][1]) || str_contains($child['buffer'], "\n")) {
                    self::fail('Worker terminó ANTES de liberar mutex; no demuestra concurrencia: '
                        . json_encode(['stdout' => $child['buffer'], 'stderr' => $child['stderr'], 'processlist' => $last]));
                }
            }
            unset($child);
            $read = [];
            foreach ($children as $child) {
                $read[] = $child['pipes'][1];
                $read[] = $child['pipes'][2];
            }
            $write = $except = null;
            stream_select($read, $write, $except, 0, 20_000);
        } while (hrtime(true) < $deadline);
        self::fail('No se observaron DOS requests simultáneos en locks de espacio/idempotencia en 15 s. '
            . 'No es evidencia válida de concurrencia. ' . json_encode(['processlist' => $last,
                'workers' => array_map(static fn (array $child): array => [
                    'stdout' => $child['buffer'], 'stderr' => $child['stderr'],
                ], $children)]));
    }

    private function line(array &$child): string
    {
        $deadline = hrtime(true) + 20_000_000_000;
        do {
            $this->drain($child);
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
        throw new RuntimeException('Timeout barrera/respuesta: ' . $child['stderr'] . $child['buffer']);
    }

    private function drain(array &$child): void
    {
        $child['buffer'] .= (string) stream_get_contents($child['pipes'][1]);
        $child['stderr'] .= (string) stream_get_contents($child['pipes'][2]);
    }
}
