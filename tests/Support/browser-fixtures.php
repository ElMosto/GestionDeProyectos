<?php

declare(strict_types=1);

// CLI exclusivamente. Reinicia SOLO gestion_test para el smoke de navegador.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/bootstrap.php';

use App\Policies\Role;
use App\Tests\Support\Fixtures;
use App\Tests\Support\SchemaTool;
use App\Tests\Support\TestDatabase;

SchemaTool::rebuild(TestDatabase::pdo());
$fixtures = new Fixtures(TestDatabase::pdo());
$admin = $fixtures->createUser(Role::ADMINISTRADOR, 'admin@test.local', mustChangePassword: true);
$collaborator = $fixtures->createUser(Role::COLABORADOR, 'collaborator@test.local');
$fixtures->createClient($admin['id'], ['name' => 'Cliente privado admin', 'notes' => 'SECRETO_ADMIN']);
echo json_encode(['admin' => $admin, 'collaborator' => $collaborator], JSON_THROW_ON_ERROR);
