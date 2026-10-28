<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Policies\Role;
use App\Tests\Support\Fixtures;
use App\Tests\Support\ProjectFixtures;
use App\Tests\Support\SchemaTool;
use App\Tests\Support\TestDatabase;

SchemaTool::rebuild(TestDatabase::pdo());
$fixtures = new Fixtures(TestDatabase::pdo());
$projects = new ProjectFixtures($fixtures);
$admin = $fixtures->createUser(Role::ADMINISTRADOR, 'admin-projects@test.local');
$leader = $fixtures->createUser(Role::LIDER_PROYECTO, 'leader-projects@test.local');
$member = $fixtures->createUser(Role::COLABORADOR, 'member-projects@test.local');
$viewer = $fixtures->createUser(Role::COLABORADOR, 'viewer-projects@test.local');
$outsider = $fixtures->createUser(Role::COLABORADOR, 'outsider-projects@test.local');
$token = $fixtures->createToken($leader['id'])['token'];
$project = $projects->project($token, $leader['id'], [
    'name' => 'Proyecto de navegador', 'base_amount' => '12345.67', 'notes' => 'NOTA_FINANCIERA_PRIVADA',
]);
$projects->members($token, $project['id'], [$member['id'], $viewer['id']]);
$stage = $projects->stage($token, $project['id'], ['name' => 'Etapa de navegador']);
$task = $projects->task($token, $stage['id'], $member['id'], ['title' => 'Tarea de navegador']);
echo json_encode(compact('admin', 'leader', 'member', 'viewer', 'outsider', 'project', 'stage', 'task'), JSON_THROW_ON_ERROR);
