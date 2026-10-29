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
use App\Tests\Support\ProjectFixtures as Projects;
use App\Tests\Support\ReservationFixtures as Reservations;
use App\Tests\Support\SalesFixtures as F;
use App\Tests\Support\SchemaTool;
use App\Tests\Support\TestDatabase;

SchemaTool::rebuild(TestDatabase::pdo());
$pdo = TestDatabase::pdo();
$fixtures = new Fixtures($pdo);
$admin = $fixtures->createUser(Role::ADMINISTRADOR, 'admin-finance@test.local');
$leader = $fixtures->createUser(Role::LIDER_PROYECTO, 'leader-finance@test.local');
$member = $fixtures->createUser(Role::COLABORADOR, 'member-finance@test.local');
$pdo->exec("UPDATE settings SET value_json='\"UTC\"' WHERE `key`='timezone'");
$pdo->exec("UPDATE users SET timezone='UTC'");
$adminToken = $fixtures->createToken($admin['id'])['token'];
$leaderToken = $fixtures->createToken($leader['id'])['token'];
$client = $fixtures->createClient($leader['id']);
$projects = new Projects($fixtures);
$internal = $projects->project($leaderToken, $leader['id'], [
    'name' => 'Proyecto interno de exportación', 'description' => '=HYPERLINK("https://example.invalid")',
    'notes' => 'NOTAS_FINANCIERAS_NO_MIEMBRO', 'base_amount' => '1000.00',
]);
F::ok(Api::post('/api/v1/projects/' . $internal['id'] . '/charges', [
    'concept' => 'CARGO_PRIVADO_NO_MIEMBRO', 'quantity' => '1.000', 'unit_price' => '500.00', 'charge_date' => gmdate('Y-m-d'),
], $leaderToken, F::key()), 201);
$projects->members($leaderToken, $internal['id'], [$member['id']]);
$stage = $projects->stage($leaderToken, $internal['id']);
$task = $projects->task($leaderToken, $stage['id'], $member['id'], ['title' => '=TAREA_LITERAL_NO_FORMULA']);
$billable = $projects->project($leaderToken, $leader['id'], [
    'name' => 'Proyecto cobrable de finanzas', 'type' => 'EXTERNO', 'billable' => true,
    'client_id' => (string) $client['id'], 'base_amount' => '1000.00',
]);
F::ok(Api::post('/api/v1/projects/' . $billable['id'] . '/payments', F::paymentBody('300.00', ['effective_at' => gmdate('Y-m-d\TH:i:s\Z')]), $leaderToken, F::key()), 201);
$sales = new F($fixtures);
$ordinary = $sales->issued($adminToken);
F::ok(Api::post('/api/v1/sales/' . $ordinary['id'] . '/payments', F::paymentBody('200.00', ['effective_at' => gmdate('Y-m-d\TH:i:s\Z')]), $adminToken, F::key()), 201);
$reservations = new Reservations($fixtures);
$space = $reservations->space($adminToken);
$band = $reservations->band($admin['id']);
$reservation = Reservations::reserve($adminToken, $band, $space, [
    'start_at' => '2030-11-05T14:00:00Z', 'end_at' => '2030-11-05T15:00:00Z',
]);
F::ok(Api::post('/api/v1/sales/' . $reservation['sale_id'] . '/payments', F::paymentBody('120.00', ['effective_at' => gmdate('Y-m-d\TH:i:s\Z')]), $adminToken, F::key()), 201);
$expense = F::ok(Api::post('/api/v1/expenses', [
    'concept' => 'Gasto pagado de navegador', 'project_id' => $internal['id'], 'amount' => '200.00',
    'expense_date' => gmdate('Y-m-d'), 'responsible_id' => (string) $admin['id'],
    'status' => 'PAGADO', 'paid_at' => gmdate('Y-m-d\TH:i:s\Z'),
], $adminToken, F::key()), 201);
$period = ['from' => gmdate('Y-m-01'), 'to' => gmdate('Y-m-t')];
echo json_encode(compact('admin', 'leader', 'member', 'internal', 'billable', 'ordinary', 'reservation', 'expense', 'period'), JSON_THROW_ON_ERROR);
