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
use App\Tests\Support\ReservationFixtures as Reservations;
use App\Tests\Support\SalesFixtures as F;
use App\Tests\Support\SchemaTool;
use App\Tests\Support\TestDatabase;

SchemaTool::rebuild(TestDatabase::pdo());
$pdo = TestDatabase::pdo();
$fixtures = new Fixtures($pdo);
$organizer = $fixtures->createUser(Role::LIDER_PROYECTO, 'organizer-meetings@test.local');
$first = $fixtures->createUser(Role::COLABORADOR, 'first-meetings@test.local');
$second = $fixtures->createUser(Role::COLABORADOR, 'second-meetings@test.local');
$admin = $fixtures->createUser(Role::ADMINISTRADOR, 'outsider-admin-meetings@test.local');
$first['name'] = $fixtures->userRow($first['id'])['name'];
$second['name'] = $fixtures->userRow($second['id'])['name'];
$pdo->exec("UPDATE settings SET value_json='\"UTC\"' WHERE `key`='timezone'");
$pdo->exec("UPDATE users SET timezone='UTC'");
$organizerToken = $fixtures->createToken($organizer['id'])['token'];
$adminToken = $fixtures->createToken($admin['id'])['token'];
$meeting = F::ok(Api::post('/api/v1/meetings', [
    'title' => 'Reunión de navegador', 'description' => 'DESCRIPCION_PRIVADA_REUNION',
    'location' => 'UBICACION_PRIVADA_REUNION', 'modality' => 'VIRTUAL',
    'start_at' => '2030-11-05T10:00:00Z', 'end_at' => '2030-11-05T11:00:00Z',
    'invited_user_ids' => [(string) $first['id'], (string) $second['id']],
], $organizerToken, F::key()), 201);
F::ok(Api::post('/api/v1/standalone-tasks', [
    'title' => 'TITULO_PRIVADO_TAREA_AVISO', 'assignee_id' => (string) $first['id'],
], $adminToken), 201);
$reservations = new Reservations($fixtures);
$space = $reservations->space($adminToken);
$band = $reservations->band($organizer['id'], ['name' => 'BANDA_PRIVADA_OCUPACION_REUNION']);
$reservation = Reservations::reserve($organizerToken, $band, $space, [
    'start_at' => '2030-11-05T14:00:00Z', 'end_at' => '2030-11-05T15:00:00Z',
]);
echo json_encode(compact('organizer', 'first', 'second', 'admin', 'meeting', 'space', 'reservation'), JSON_THROW_ON_ERROR);
