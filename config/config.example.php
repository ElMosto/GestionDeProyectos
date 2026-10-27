<?php

declare(strict_types=1);

/**
 * Configuración de EJEMPLO. Copiar a config/config.php y rellenar.
 * config/config.php NO se versiona y contiene credenciales reales.
 */

return [
    'app' => [
        'env' => 'local',
        'debug' => false,
        'name' => 'Estudio Musical',
        'timezone' => 'UTC',
        'version' => '0.1.0',
    ],

    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'gestion',
        'username' => 'gestion',
        'password' => '',
        'charset' => 'utf8mb4',
        // Usuario con privilegios mínimos sobre esta base. Nunca root.
    ],

    'session' => [
        'name' => 'gestion_session',
        'idle_timeout' => 1800,      // 30 min de inactividad
        'absolute_timeout' => 43200, // 12 h desde el inicio de sesión
        'cookie_secure' => true,     // false solo en desarrollo local sin HTTPS
        'cookie_samesite' => 'Lax',
    ],

    'security' => [
        'password_min_length' => 12,
        'login_max_attempts' => 5,
        'login_window' => 900,       // segundos
        'token_ttl_days' => 90,      // tokens móviles (D-08)
    ],

    'uploads' => [
        'avatar_max_bytes' => 2097152, // 2 MB
        'avatar_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    ],

    'payments' => [
        'methods' => ['CASH', 'TRANSFER', 'CARD', 'OTHER'],
        'idempotency_ttl_hours' => 24,
    ],

    'reservations' => [
        'block_minutes' => 30,
    ],

    'paths' => [
        'storage' => __DIR__ . '/../storage',
        'templates' => __DIR__ . '/../templates',
    ],
];
