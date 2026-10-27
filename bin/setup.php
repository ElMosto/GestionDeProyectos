<?php

declare(strict_types=1);

/**
 * Instalación de un solo uso: crea el primer administrador.
 * Se niega a ejecutarse si ya existe un administrador activo.
 *
 *   php bin/setup.php --name="Ana" --email="ana@estudio.mx" [--password="..."]
 *
 * Si no se pasa contraseña, se genera una y se muestra una sola vez.
 * En ambos casos la cuenta nace con must_change_password = 1.
 */

use App\Core\Config;
use App\Core\Database;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$options = getopt('', ['name:', 'email:', 'password::', 'help']);

if (isset($options['help']) || !isset($options['name'], $options['email'])) {
    fwrite(STDOUT, "Uso: php bin/setup.php --name=\"Nombre\" --email=\"correo@dominio\" [--password=\"...\"]\n");
    exit(isset($options['help']) ? 0 : 1);
}

$name = trim((string) $options['name']);
$email = strtolower(trim((string) $options['email']));
$configValues = require $root . '/config/config.php';
$config = new Config($configValues);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Correo inválido.\n");
    exit(1);
}

$minLength = (int) $config->get('security.password_min_length', 12);
$password = isset($options['password']) && is_string($options['password']) && $options['password'] !== ''
    ? (string) $options['password']
    : bin2hex(random_bytes(9));

if (mb_strlen($password) < $minLength) {
    fwrite(STDERR, "La contraseña debe tener al menos {$minLength} caracteres.\n");
    exit(1);
}

$pdo = (new Database($config))->pdo();

$adminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code = 'ADMINISTRADOR'")->fetchColumn();

if ($adminRoleId === 0) {
    fwrite(STDERR, "Faltan los seeds: ejecuta php bin/seed.php primero.\n");
    exit(1);
}

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
      WHERE r.code = 'ADMINISTRADOR' AND u.active = 1"
);
$stmt->execute();

if ((int) $stmt->fetchColumn() > 0) {
    fwrite(STDERR, "Ya existe un administrador activo. Este instalador solo se usa una vez.\n");
    exit(1);
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
$stmt->execute([$email]);

if ((int) $stmt->fetchColumn() > 0) {
    fwrite(STDERR, "Ese correo ya está registrado.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$pdo->beginTransaction();

try {
    $insert = $pdo->prepare(
        'INSERT INTO users (role_id, name, email, password_hash, timezone, active, must_change_password)
         VALUES (?, ?, ?, ?, ?, 1, 1)'
    );
    $insert->execute([$adminRoleId, $name, $email, $hash, 'America/Mexico_City']);
    $userId = (int) $pdo->lastInsertId();

    $audit = $pdo->prepare(
        "INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, after_json, request_id)
         VALUES (NULL, 'ADMIN_BOOTSTRAP', 'user', ?, ?, 'cli-setup')"
    );
    $audit->execute([$userId, json_encode(['email' => $email], JSON_UNESCAPED_UNICODE)]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'No se pudo crear el administrador: ' . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "Administrador creado (id {$userId}).\n");
fwrite(STDOUT, "Correo: {$email}\n");
fwrite(STDOUT, "Contraseña temporal: {$password}\n");
fwrite(STDOUT, "Debe cambiarla en el primer acceso.\n");
fwrite(STDOUT, "Borra o restringe bin/setup.php en producción después de usarlo.\n");
