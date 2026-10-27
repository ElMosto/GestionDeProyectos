<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Policies\Role;
use App\Tests\Support\HttpClient;
use App\Tests\Support\InProcessApi as Api;
use RuntimeException;

/** §19.A/H — HTTP real: cookies, rotación, CSRF, roles, tokens y acceso. */
final class AuthHttpTest extends DatabaseTestCase
{
    private static mixed $server = null;
    private static string $url;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // Puerto efímero evita colisionar con servidores del desarrollador.
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new RuntimeException($error);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::$url = 'http://' . $address;
        $log = sys_get_temp_dir() . '/gestion-http-test.log';
        self::$server = proc_open([
            PHP_BINARY, '-S', $address, '-t', APP_ROOT . '/public', TESTS_ROOT . '/Support/http-router.php',
        ], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, APP_ROOT);
        if (!is_resource(self::$server)) {
            throw new RuntimeException('No se pudo iniciar el servidor HTTP de pruebas.');
        }
        fclose($pipes[0]);
        $ready = false;
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
            if ($connection !== false) {
                fclose($connection);
                $ready = true;
                break;
            }
            usleep(20000);
        }
        if (!$ready) {
            self::tearDownAfterClass();
            throw new RuntimeException('El servidor HTTP no respondió; revisar ' . $log);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        parent::tearDownAfterClass();
    }

    private function login(HttpClient $client, array $user): void
    {
        $before = $client->call('GET', '/api/v1/auth/csrf');
        $token = $before->payload['data']['token'];
        $result = $client->call('POST', '/api/v1/auth/login', [
            'email' => $user['email'], 'password' => $user['password'],
        ], ['X-CSRF-Token: ' . $token]);
        $this->assertSame(200, $result->status, $result->rawBody);
        $this->assertStringContainsString('HttpOnly', $result->headers['set-cookie']);
        $this->assertStringContainsString('SameSite=Lax', $result->headers['set-cookie']);
        $this->assertNotSame($before->headers['set-cookie'], $result->headers['set-cookie']);
    }

    public function test_A_cookie_login_csrf_and_deactivation_of_open_session(): void
    {
        $user = $this->fixtures->createUser(Role::COLABORADOR);
        $client = new HttpClient(self::$url);
        $this->assertSame(401, $client->call('GET', '/api/v1/auth/me')->status);
        $this->assertSame(403, $client->call('POST', '/api/v1/auth/login', [
            'email' => $user['email'], 'password' => $user['password'],
        ])->status);
        $this->login($client, $user);
        $this->assertSame(200, $client->call('GET', '/api/v1/auth/me')->status);
        $this->assertSame(403, $client->call('GET', '/api/v1/users')->status);
        $this->assertSame(200, $client->call('GET', '/api/v1/users/directory')->status);
        foreach (['role_id' => 1, 'active' => false] as $field => $value) {
            $result = $client->call('PATCH', '/api/v1/me', [$field => $value], ['X-CSRF-Token: ' . $client->csrf()]);
            $this->assertSame(422, $result->status);
            $this->assertArrayHasKey($field, $result->payload['error']['fields']);
        }
        $this->pdo->prepare('UPDATE users SET active = 0 WHERE id = ?')->execute([$user['id']]);
        $this->assertSame(401, $client->call('GET', '/api/v1/auth/me')->status);
    }

    public function test_A_forced_password_change_unlocks_routes_and_revokes_existing_tokens(): void
    {
        $user = $this->fixtures->createUser(Role::COLABORADOR, mustChangePassword: true);
        $device = $this->fixtures->createToken($user['id']);
        $client = new HttpClient(self::$url);
        $this->login($client, $user);
        $blocked = $client->call('GET', '/api/v1/clients');
        $this->assertSame(403, $blocked->status);
        $this->assertSame('PASSWORD_CHANGE_REQUIRED', $blocked->payload['error']['code']);
        $changed = $client->call('POST', '/api/v1/me/password', [
            'current_password' => $user['password'], 'new_password' => 'OtraClaveSegura2026!',
        ], ['X-CSRF-Token: ' . $client->csrf()]);
        $this->assertSame(200, $changed->status, $changed->rawBody);
        $this->assertFalse($changed->payload['data']['user']['must_change_password']);
        $this->assertSame(200, $client->call('GET', '/api/v1/clients')->status);
        $this->assertSame(401, Api::get('/api/v1/auth/me', bearer: $device['token'])->status);
        $this->assertNoSecrets($this->fixtures->auditLogs(), [$user['password'], 'OtraClaveSegura2026!']);
    }

    public function test_A_token_is_returned_once_works_without_cookie_and_can_be_revoked(): void
    {
        $user = $this->fixtures->createUser(Role::COLABORADOR);
        $client = new HttpClient(self::$url);
        $this->login($client, $user);
        $result = $client->call('POST', '/api/v1/me/tokens', ['name' => 'Teléfono'], ['X-CSRF-Token: ' . $client->csrf()]);
        $this->assertSame(201, $result->status);
        $device = $result->payload['data'];
        $plain = $device['token'];
        $row = $this->fixtures->tokenRow((int) $device['id']);
        $this->assertSame(hash('sha256', $plain), $row['token_hash']);
        $mobile = new HttpClient(self::$url);
        $headers = ['Authorization: Bearer ' . $plain];
        $me = $mobile->call('GET', '/api/v1/auth/me', headers: $headers);
        $this->assertSame(200, $me->status);
        $this->assertArrayNotHasKey('set-cookie', $me->headers);
        $list = $mobile->call('GET', '/api/v1/me/tokens', headers: $headers);
        $this->assertStringNotContainsString($plain, $list->rawBody);
        $this->assertStringNotContainsString('token_hash', $list->rawBody);
        $revoked = $mobile->call('DELETE', '/api/v1/me/tokens/' . $device['id'], headers: $headers);
        $this->assertSame(204, $revoked->status);
        $this->assertSame(401, $mobile->call('GET', '/api/v1/auth/me', headers: $headers)->status);
    }

    public function test_A_client_cannot_login_and_failed_logins_are_rate_limited(): void
    {
        $owner = $this->fixtures->createUser(Role::ADMINISTRADOR);
        $band = $this->fixtures->createClient($owner['id'], ['type' => 'BANDA']);
        $client = new HttpClient(self::$url);
        $headers = ['X-CSRF-Token: ' . $client->csrf()];
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $result = $client->call('POST', '/api/v1/auth/login', [
                'email' => $band['email'], 'password' => 'NuncaTieneCredenciales!',
            ], array_merge($headers, ['X-Forwarded-For: 192.0.2.' . $attempt]));
            $this->assertSame($attempt <= 5 ? 401 : 429, $result->status);
            $this->assertSame($attempt <= 5 ? 'INVALID_CREDENTIALS' : 'RATE_LIMITED', $result->payload['error']['code']);
        }
        $this->assertSame(401, $client->call('GET', '/api/v1/auth/register')->status);
    }

    public function test_H_spoofed_forwarded_ip_does_not_bypass_ip_rate_limit(): void
    {
        $client = new HttpClient(self::$url);
        $csrf = $client->csrf();
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $result = $client->call('POST', '/api/v1/auth/login', [
                'email' => 'different-' . $attempt . '@test.local', 'password' => 'ClaveIncorrecta123!',
            ], ['X-CSRF-Token: ' . $csrf, 'X-Forwarded-For: 192.0.2.' . $attempt]);
            $this->assertSame($attempt <= 5 ? 401 : 429, $result->status);
        }
    }

    public function test_H_cookie_login_rejects_foreign_origin_even_with_valid_csrf(): void
    {
        $user = $this->fixtures->createUser(Role::COLABORADOR);
        $client = new HttpClient(self::$url);
        $csrf = $client->csrf();
        $body = ['email' => $user['email'], 'password' => $user['password']];
        $foreign = $client->call('POST', '/api/v1/auth/login', $body, [
            'X-CSRF-Token: ' . $csrf, 'Origin: https://other.invalid',
        ]);
        $this->assertSame(403, $foreign->status);
        $this->assertSame('CSRF_INVALID', $foreign->payload['error']['code']);
        $same = $client->call('POST', '/api/v1/auth/login', $body, [
            'X-CSRF-Token: ' . $csrf, 'Origin: ' . self::$url,
        ]);
        $this->assertSame(200, $same->status, $same->rawBody);
    }

    public function test_A_admin_creates_all_three_roles_without_exposing_secrets(): void
    {
        $admin = $this->fixtures->createUser(Role::ADMINISTRADOR);
        $token = $this->fixtures->createToken($admin['id'])['token'];
        foreach (Role::all() as $role) {
            $created = Api::post('/api/v1/users', [
                'name' => 'Creado ' . $role, 'email' => strtolower($role) . '@test.local',
                'role' => $role, 'password' => 'ClaveTemporal2026!',
            ], $token);
            $this->assertSame(201, $created->status, $created->rawBody);
            $this->assertSame($role, $created->payload['data']['role']['code']);
            $this->assertTrue($created->payload['data']['must_change_password']);
            $this->assertNoSecrets($created->payload, ['ClaveTemporal2026!']);
        }
    }

    public function test_H_avatar_is_reprocessed_and_rejects_active_or_oversized_content(): void
    {
        $user = $this->fixtures->createUser(Role::COLABORADOR);
        $client = new HttpClient(self::$url);
        $this->login($client, $user);
        $headers = ['X-CSRF-Token: ' . $client->csrf()];
        $file = tempnam(sys_get_temp_dir(), 'gestion-avatar-');
        $stored = null;
        try {
            $image = imagecreatetruecolor(20, 10);
            imagepng($image, $file);
            $uploaded = $client->call('POST', '/api/v1/me/avatar', [
                'avatar' => new \CURLFile($file, 'image/png', 'real.png'),
            ], $headers, multipart: true);
            $this->assertSame(200, $uploaded->status, $uploaded->rawBody);
            $stored = $this->fixtures->userRow($user['id'])['avatar_path'];
            $served = $client->call('GET', '/api/v1/me/avatar');
            $this->assertSame(200, $served->status);
            $this->assertSame('image/png', $served->headers['content-type']);
            $this->assertStringContainsString('private', $served->headers['cache-control']);
            $info = getimagesizefromstring($served->rawBody);
            $this->assertSame([512, 512], [$info[0], $info[1]]);
            foreach ([
                ['evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'],
                ['fake.png', '<?php echo "bad";'],
                ['large.png', str_repeat('a', 2097153)],
            ] as [$name, $content]) {
                file_put_contents($file, $content);
                $rejected = $client->call('POST', '/api/v1/me/avatar', [
                    'avatar' => new \CURLFile($file, 'image/png', $name),
                ], $headers, multipart: true);
                $this->assertSame(422, $rejected->status, $rejected->rawBody);
            }
        } finally {
            unlink($file);
            if ($stored !== null) {
                $this->service(\App\Auth\AvatarStorage::class)->delete($stored);
            }
        }
    }
}
