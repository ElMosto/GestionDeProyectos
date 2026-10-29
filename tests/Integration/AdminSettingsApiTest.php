<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Policies\Role;
use App\Tests\Support\FinanceFixtures as H;
use App\Tests\Support\InProcessApi as Api;
use App\Tests\Support\SalesFixtures as F;

/** §5/§16/§19.A/H — allowlisted settings and privacy-preserving audit API. */
final class AdminSettingsApiTest extends DatabaseTestCase
{
    private array $originalSettings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalSettings = $this->pdo->query('SELECT * FROM settings ORDER BY id')->fetchAll();
    }

    protected function tearDown(): void
    {
        // Settings are preserved by DatabaseTestCase; restore this test's initial state.
        foreach ($this->originalSettings as $row) {
            $id = $row['id'];
            unset($row['id']);
            $columns = array_map(static fn (string $key): string => '`' . $key . '`=?', array_keys($row));
            $this->pdo->prepare('UPDATE settings SET ' . implode(',', $columns) . ' WHERE id=?')->execute([...array_values($row), $id]);
        }
        parent::tearDown();
    }

    public function test_H_settings_digest_if_match_and_body_version_are_not_numeric_versions(): void
    {
        [, $admin] = (new F($this->fixtures))->actor();
        $old = F::ok(Api::get('/api/v1/settings', bearer: $admin));
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $old['version']);
        self::assertSame('MXN', $old['currency']);
        $updated = F::ok(Api::patch('/api/v1/settings', ['studio_name' => 'Estudio QA nuevo'], $admin,
            ['If-Match' => '"' . $old['version'] . '"']));
        self::assertSame('Estudio QA nuevo', $updated['studio_name']);
        self::assertNotSame($old['version'], $updated['version']);
        $stale = Api::patch('/api/v1/settings', ['studio_name' => 'Obsoleto', 'expected_version' => $old['version']], $admin);
        self::assertSame(409, $stale->status);
        self::assertSame('VERSION_CONFLICT', $stale->errorCode());
        $next = F::ok(Api::patch('/api/v1/settings', ['studio_name' => 'Estudio QA siguiente', 'expected_version' => $updated['version']], $admin));
        self::assertSame('Estudio QA siguiente', $next['studio_name']);
        self::assertSame($next, F::ok(Api::get('/api/v1/settings', bearer: $admin)));
    }

    public function test_H_settings_reject_readonly_currency_paths_unknown_keys_and_conflicting_digests(): void
    {
        [, $admin] = (new F($this->fixtures))->actor();
        $old = F::ok(Api::get('/api/v1/settings', bearer: $admin));
        foreach ([['currency' => 'USD'], ['storage_path' => '../../public'], ['db_password' => 'secret'],
            ['timezone' => 'Not/AZone'], ['timezone' => "' OR 1=1 --"], ['studio_name' => '']] as $invalid) {
            self::assertSame(422, Api::patch('/api/v1/settings', $invalid + ['expected_version' => $old['version']], $admin)->status);
        }
        self::assertSame(422, Api::patch('/api/v1/settings', ['studio_name' => 'No version'], $admin)->status);
        self::assertSame(422, Api::patch('/api/v1/settings', ['studio_name' => 'Numeric', 'expected_version' => '1'], $admin)->status);
        self::assertSame(422, Api::patch('/api/v1/settings', ['studio_name' => 'Conflict', 'expected_version' => $old['version']],
            $admin, ['If-Match' => '"' . str_repeat('0', 64) . '"'])->status);
        self::assertSame($old, F::ok(Api::get('/api/v1/settings', bearer: $admin)));
    }

    public function test_H_timezone_change_with_future_occupancy_rejects_without_partial_settings(): void
    {
        [$actor, $admin] = (new F($this->fixtures))->actor();
        $band = $this->fixtures->createClient($actor['id'], ['type' => 'BANDA']);
        $this->fixtures->createFutureReservation($band['id'], $actor['id']);
        $old = F::ok(Api::get('/api/v1/settings', bearer: $admin));
        $newZone = $old['timezone'] === 'UTC' ? 'America/Mexico_City' : 'UTC';
        $denied = Api::patch('/api/v1/settings', ['studio_name' => 'Not applied', 'timezone' => $newZone,
            'expected_version' => $old['version']], $admin);
        self::assertSame(409, $denied->status);
        self::assertSame('SETTINGS_TIMEZONE_IN_USE', $denied->errorCode());
        self::assertSame($old, F::ok(Api::get('/api/v1/settings', bearer: $admin)));
    }

    public function test_A_H_settings_and_audit_admin_only(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        $old = F::ok(Api::get('/api/v1/settings', bearer: $admin));
        foreach ([Role::LIDER_PROYECTO, Role::COLABORADOR] as $role) {
            [, $denied] = $f->actor($role);
            self::assertSame(403, Api::get('/api/v1/settings', bearer: $denied)->status);
            self::assertSame(403, Api::patch('/api/v1/settings', ['studio_name' => 'Forbidden', 'expected_version' => $old['version']], $denied)->status);
            self::assertSame(403, Api::get('/api/v1/audit-logs', H::range(), $denied)->status);
        }
        self::assertSame(401, Api::get('/api/v1/settings')->status);
    }

    public function test_A_F_H_audit_dto_never_discloses_private_agenda_even_to_admin(): void
    {
        [$actor, $admin] = (new F($this->fixtures))->actor();
        $h = new H($this->pdo);
        $secrets = ['Lugar privado ZX', 'Notas privadas ZX', '<script>alert(1)</script>', 'raw-token-QA', 'private-title-QA'];
        $id = $h->audit($actor['id'], 'MEETING_UPDATE', 'meeting', '123', '2026-10-05 12:00:00', [
            'location' => $secrets[0], 'notes' => $secrets[1], 'title' => $secrets[4],
            'password_hash' => $secrets[2], 'token' => $secrets[3],
        ]);
        $result = Api::get('/api/v1/audit-logs', H::range(['action' => 'MEETING_UPDATE', 'actor_id' => (string) $actor['id'],
            'entity_type' => 'meeting', 'limit' => '1']), $admin);
        $rows = F::ok($result);
        self::assertCount(1, $rows);
        self::assertSame((string) $id, $rows[0]['id']);
        self::assertSame(1, $result->meta()['total']);
        $this->assertNoSecrets($result->json(), $secrets, 'audit endpoint');
        self::assertArrayNotHasKey('after_json', $rows[0]);
        self::assertArrayNotHasKey('before_json', $rows[0]);
        $keys = array_keys($rows[0]);
        sort($keys);
        self::assertSame(['action', 'actor_id', 'created_at', 'entity_id', 'entity_type', 'id', 'ip_address', 'request_id'], $keys);
        self::assertSame(422, Api::get('/api/v1/audit-logs', H::range(['action' => "' OR 1=1 --"]), $admin)->status);
    }
}
