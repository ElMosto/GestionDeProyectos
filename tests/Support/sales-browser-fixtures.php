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
use App\Tests\Support\ProjectFixtures;
use App\Tests\Support\SalesFixtures as Sales;
use App\Tests\Support\SchemaTool;
use App\Tests\Support\TestDatabase;

SchemaTool::rebuild(TestDatabase::pdo());
$fixtures = new Fixtures(TestDatabase::pdo());
$sales = new Sales($fixtures);
$projects = new ProjectFixtures($fixtures);
$admin = $fixtures->createUser(Role::ADMINISTRADOR, 'admin-sales@test.local');
$seller = $fixtures->createUser(Role::COLABORADOR, 'seller-sales@test.local');
$other = $fixtures->createUser(Role::COLABORADOR, 'other-sales@test.local');
$leader = $fixtures->createUser(Role::LIDER_PROYECTO, 'leader-sales@test.local');
$adminToken = $fixtures->createToken($admin['id'])['token'];
$sellerToken = $fixtures->createToken($seller['id'])['token'];
$leaderToken = $fixtures->createToken($leader['id'])['token'];
$client = $fixtures->createClient($seller['id'], ['name' => 'Cliente de venta navegador']);
$product = $sales->product($adminToken, '50.00', ['name' => 'Licuado navegador']);
$extra = Sales::ok(Api::post('/api/v1/extras', ['name' => 'Chocolate navegador', 'price' => '10.00'], $adminToken), 201);
$sales->link($adminToken, $product, [$extra]);
$items = [Sales::item($product, '2.000', [['extra_id' => $extra['id'], 'quantity_per_unit' => '1.000']])];
$draft = $sales->draft($sellerToken, $items, ['client_id' => (string) $client['id']]);
$issued = $sales->issue($sellerToken, $sales->draft($sellerToken, $items));
$project = $projects->project($leaderToken, $leader['id'], [
    'name' => 'Proyecto cobrable navegador', 'client_id' => (string) $client['id'],
    'billable' => true, 'base_amount' => '250.00',
]);
foreach (['PLANIFICADO', 'EN_CURSO', 'FINALIZADO'] as $status) {
    $project = Sales::ok(Api::post('/api/v1/projects/' . $project['id'] . '/transitions', [
        'status' => $status, 'version' => $project['version'],
    ], $leaderToken));
}
$longItems = [];
for ($index = 1; $index <= 40; $index++) {
    $name = $index === 40 ? 'ULTIMA_PARTIDA ' : 'Partida ' . $index . ' ';
    $name .= mb_substr(str_repeat('Descripción extensa sin recortar áéíóú ', 5), 0, 130);
    $longItems[] = ['product_id' => null, 'name' => $name, 'unit_price' => '10.00', 'quantity' => '1.000', 'extras' => []];
}
$longTicket = $sales->issue($adminToken, $sales->draft($adminToken, $longItems));
echo json_encode(compact('admin', 'seller', 'other', 'leader', 'product', 'extra', 'client', 'draft', 'issued', 'project', 'longTicket'), JSON_THROW_ON_ERROR);
