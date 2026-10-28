<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Policies\Role;
use App\Tests\Support\MeetingFixtures as F;
use App\Tests\Support\ReservationFixtures;
use App\Tests\Support\TestDatabase;
use PDO;
use RuntimeException;

/** §13 / §19.E/F: simultaneous independent processes; held real MariaDB space mutex. */
final class MeetingConcurrencyTest extends DatabaseTestCase
{
    public function test_F_dos_reuniones_simultaneas_mismo_espacio_201_409_sin_venta(): void
    {
        [$space, $body, $token] = $this->scenario();
        $request = $this->request('/api/v1/meetings', $body, $token);
        $results = $this->overlap([$space['id']], [$request, array_replace($request, ['headers' => F::key()])]);
        $this->assertWinnerAndConflict($results);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM meetings')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM space_occupancies WHERE kind='MEETING' AND status='CONFIRMED'")->fetchColumn());
        foreach (['sales', 'payments', 'expenses', 'reservations'] as $table) {
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(), $table);
        }
    }

    public function test_F_misma_key_simultanea_replay_unica_reunion_ocupacion_e_invitacion(): void
    {
        [$space, $body, $token] = $this->scenario();
        $request = $this->request('/api/v1/meetings', $body, $token);
        $results = $this->overlap([$space['id']], [$request, $request]);
        self::assertSame([201, 201], array_column($results, 'status'), json_encode($results));
        self::assertSame($results[0]['payload']['data'], $results[1]['payload']['data']);
        foreach (['meetings', 'space_occupancies', 'meeting_invitations', 'notifications'] as $table) {
            self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(), $table);
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn());
    }

    public function test_E_F_reunion_vs_reserva_comparten_mutex_una_ocupacion_y_solo_venta_real(): void
    {
        [$space, $body, $token, $user] = $this->scenario();
        $band = (new ReservationFixtures($this->fixtures))->band((int) $user['id']);
        $results = $this->overlap([$space['id']], [
            $this->request('/api/v1/meetings', $body, $token),
            $this->request('/api/v1/reservations', ReservationFixtures::body($band, $space), $token),
        ]);
        $this->assertWinnerAndConflict($results);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM space_occupancies')->fetchColumn());
        $meetings = (int) $this->pdo->query('SELECT COUNT(*) FROM meetings')->fetchColumn();
        $reservations = (int) $this->pdo->query('SELECT COUNT(*) FROM reservations')->fetchColumn();
        self::assertSame(1, $meetings + $reservations);
        self::assertSame($reservations, (int) $this->pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn());
    }

    public function test_F_reunion_vs_mantenimiento_simultaneos_no_duplican_espacio(): void
    {
        [$space, $body, $token] = $this->scenario();
        $results = $this->overlap([$space['id']], [
            $this->request('/api/v1/meetings', $body, $token),
            $this->request('/api/v1/space-blocks', ReservationFixtures::blockBody($space), $token),
        ]);
        $this->assertWinnerAndConflict($results);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM space_occupancies')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT (SELECT COUNT(*) FROM meetings)+(SELECT COUNT(*) FROM space_blocks)')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn());
    }

    public function test_F_reprogramaciones_cruzadas_bloquean_ambos_espacios_ordenados_sin_deadlock(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor(Role::ADMINISTRADOR);
        [, $ownerA] = $f->actor();
        [, $ownerB] = $f->actor();
        $rf = new ReservationFixtures($this->fixtures);
        $a = $rf->space($admin);
        $b = $rf->space($admin);
        $m1 = F::create($ownerA, [], ['space_id' => $a['id']]);
        $m2 = F::create($ownerB, [], ['space_id' => $b['id'],
            'start_at' => '2030-01-07T12:00:00Z', 'end_at' => '2030-01-07T13:00:00Z']);
        $before = $this->pdo->query('SELECT id,occupancy_id FROM meetings ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $results = $this->overlap([$a['id'], $b['id']], [
            $this->request('/api/v1/meetings/' . $m1['id'], ['version' => $m1['version'], 'space_id' => $b['id']], $ownerA, 'PATCH'),
            $this->request('/api/v1/meetings/' . $m2['id'], ['version' => $m2['version'], 'space_id' => $a['id']], $ownerB, 'PATCH'),
        ]);
        self::assertSame([200, 200], array_column($results, 'status'), json_encode($results));
        self::assertSame($before, $this->pdo->query('SELECT id,occupancy_id FROM meetings ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame($b['id'], F::detail($ownerA, $m1)['space_id']);
        self::assertSame($a['id'], F::detail($ownerB, $m2)['space_id']);
        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM space_occupancies WHERE kind='MEETING' AND status='CONFIRMED'")->fetchColumn());
    }

    private function scenario(): array
    {
        $f = new F($this->fixtures);
        [$user, $token] = $f->actor(Role::ADMINISTRADOR);
        [$invitee] = $f->actor();
        $space = (new ReservationFixtures($this->fixtures))->space($token);
        return [$space, F::body([$invitee], ['space_id' => $space['id']]), $token, $user];
    }

    private function request(string $path, array $body, string $token, string $method = 'POST'): array
    {
        return ['database' => 'gestion_test', 'method' => $method, 'path' => $path,
            'body' => $body, 'headers' => F::key(), 'bearer' => $token, 'await_ack' => true];
    }

    private function assertWinnerAndConflict(array $results): void
    {
        $statuses = array_column($results, 'status');
        sort($statuses);
        self::assertSame([201, 409], $statuses, json_encode($results));
        foreach ($results as $result) {
            if ($result['status'] === 409) {
                self::assertSame('RESERVATION_CONFLICT', $result['payload']['error']['code']);
            }
        }
    }

    /** Financial worker accepts arbitrary request paths; no new worker or shared DB rebuild. */
    private function overlap(array $spaceIds, array $requests): array
    {
        $children = [];
        $monitor = TestDatabase::newConnection();
        $baseline = array_map('intval', $monitor->query('SELECT ID FROM information_schema.PROCESSLIST')->fetchAll(PDO::FETCH_COLUMN));
        sort($spaceIds, SORT_NUMERIC);
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT id FROM spaces WHERE id IN (' . implode(',', array_fill(0, count($spaceIds), '?')) . ') ORDER BY id FOR UPDATE');
            $lock->execute($spaceIds);
            foreach ($requests as $request) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, APP_ROOT . '/tests/Support/financial-worker.php'], [
                    0 => ['pipe', 'r'], 1 => ['socket'], 2 => ['socket'],
                ], $pipes, APP_ROOT, null, ['bypass_shell' => true]);
                if (!is_resource($process)) {
                    throw new RuntimeException('No se pudo iniciar worker PHP para reunión.');
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
            $last = $monitor->query('SELECT ID, DB, COMMAND, TIME_MS, STATE, INFO FROM information_schema.PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC);
            $waiters = $spaces = 0;
            foreach ($last as $row) {
                if ($row['DB'] !== 'gestion_test' || in_array((int) $row['ID'], $baseline, true)
                    || !in_array($row['COMMAND'], ['Query', 'Execute'], true) || (float) $row['TIME_MS'] < 10) {
                    continue;
                }
                $sql = (string) $row['INFO'];
                $spaceLock = preg_match('/^SELECT\b.*\bFROM\s+`?spaces`?\b.*\bFOR\s+UPDATE\b/is', $sql) === 1;
                $keyLock = preg_match('/^(?:INSERT\s+INTO\s+`?idempotency_requests`?|SELECT\b.*\bFROM\s+`?idempotency_requests`?\b.*\bFOR\s+UPDATE\b)/is', $sql) === 1;
                if ($spaceLock || $keyLock) {
                    $waiters++;
                    $spaces += $spaceLock ? 1 : 0;
                }
            }
            if ($waiters >= 2 && $spaces >= 1) {
                self::assertTrue($this->pdo->inTransaction(), 'Ambos requests se observaron antes de liberar el mutex.');
                return;
            }
            foreach ($children as &$child) {
                $this->drain($child);
                if (feof($child['pipes'][1]) || str_contains($child['buffer'], "\n")) {
                    self::fail('Worker terminó ANTES de liberar espacio; concurrencia no demostrada: '
                        . json_encode(['stdout' => $child['buffer'], 'stderr' => $child['stderr'], 'processlist' => $last]));
                }
            }
            unset($child);
            // Socket readiness yields between DB observations; no sleep/busy polling.
            $read = [];
            foreach ($children as $child) {
                $read[] = $child['pipes'][1];
                $read[] = $child['pipes'][2];
            }
            $write = $except = null;
            stream_select($read, $write, $except, 0, 20_000);
        } while (hrtime(true) < $deadline);
        self::fail('No se demostraron DOS waiters simultáneos en 15 s: ' . json_encode([
            'processlist' => $last, 'workers' => array_map(static fn (array $c): array => [
                'stdout' => $c['buffer'], 'stderr' => $c['stderr'],
            ], $children),
        ]));
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
