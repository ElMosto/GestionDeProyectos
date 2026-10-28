<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/bootstrap.php';

use App\Policies\Role;
use App\Tests\Support\Fixtures;
use App\Tests\Support\InProcessApi as Api;
use App\Tests\Support\SalesFixtures as Financial;
use App\Tests\Support\SchemaTool;
use App\Tests\Support\TestDatabase;

SchemaTool::rebuild(TestDatabase::pdo());
$pdo = TestDatabase::pdo();
$fixtures = new Fixtures($pdo);
$admin = $fixtures->createUser(Role::ADMINISTRADOR, 'admin-reservations@test.local');
$owner = $fixtures->createUser(Role::COLABORADOR, 'owner-reservations@test.local');
$other = $fixtures->createUser(Role::COLABORADOR, 'other-reservations@test.local');
// Instantes y ventanas explícitos para que la prueba sea independiente de DST y del equipo.
$pdo->exec("UPDATE settings SET value_json='\"UTC\"' WHERE `key`='timezone'");
$pdo->exec("UPDATE users SET timezone='UTC'");
$adminToken = $fixtures->createToken($admin['id'])['token'];
$ownerToken = $fixtures->createToken($owner['id'])['token'];
$band = $fixtures->createClient($owner['id'], ['type' => 'BANDA', 'name' => 'Banda navegador privada']);
$normal = $fixtures->createClient($owner['id'], ['type' => 'NORMAL', 'name' => 'Cliente normal navegador']);
$space = Financial::ok(Api::post('/api/v1/spaces', [
    'name' => 'Sala de navegador', 'hourly_rate' => '120.00', 'block_minutes' => 30,
], $adminToken), 201);
$hours = [];
for ($weekday = 1; $weekday <= 7; $weekday++) {
    $hours[] = ['weekday' => $weekday, 'local_start_time' => '00:00:00', 'local_end_time' => '24:00:00'];
}
Financial::ok(Api::call('PUT', '/api/v1/spaces/' . $space['id'] . '/hours', [
    'version' => $space['version'], 'hours' => $hours,
], [], $adminToken));
$space = Financial::ok(Api::get('/api/v1/spaces/' . $space['id'], bearer: $adminToken));
$reservation = Financial::ok(Api::post('/api/v1/reservations', [
    'space_id' => $space['id'], 'band_client_id' => (string) $band['id'],
    'start_at' => '2030-11-05T10:00:00Z', 'end_at' => '2030-11-05T11:30:00Z',
    'notes' => 'NOTA_PRIVADA_RESERVA_NAVEGADOR',
], $ownerToken, Financial::key()), 201);
$maintenance = Financial::ok(Api::post('/api/v1/space-blocks', [
    'space_id' => $space['id'], 'start_at' => '2030-11-05T14:00:00Z', 'end_at' => '2030-11-05T15:00:00Z',
    'reason' => 'MOTIVO_PRIVADO_MANTENIMIENTO',
], $adminToken, Financial::key()), 201);
echo json_encode(compact('admin', 'owner', 'other', 'band', 'normal', 'space', 'reservation', 'maintenance'), JSON_THROW_ON_ERROR);
