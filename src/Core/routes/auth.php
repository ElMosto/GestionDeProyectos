<?php

declare(strict_types=1);

use App\Auth\AuthController;
use App\Auth\MeController;
use App\Auth\TokenController;
use App\Core\Container;
use App\Core\Router;

/**
 * Rutas de autenticación y perfil (§15).
 *
 * `GET /auth/csrf` y `POST /auth/login` son públicas; todo lo demás exige identidad
 * y pasa por la cadena de middleware de src/Core/middleware.php.
 */
return static function (Router $router, Container $container): void {
    // Sesión
    $router->get('/api/v1/auth/csrf', [AuthController::class, 'csrf']);
    $router->post('/api/v1/auth/login', [AuthController::class, 'login']);
    $router->post('/api/v1/auth/logout', [AuthController::class, 'logout']);
    $router->get('/api/v1/auth/me', [AuthController::class, 'me']);

    // Mi usuario
    $router->get('/api/v1/me', [MeController::class, 'show']);
    $router->patch('/api/v1/me', [MeController::class, 'update']);
    $router->post('/api/v1/me/password', [MeController::class, 'changePassword']);
    $router->post('/api/v1/me/avatar', [MeController::class, 'uploadAvatar']);
    $router->get('/api/v1/me/avatar', [MeController::class, 'avatar']);

    // Dispositivos / tokens móviles (D-08)
    $router->get('/api/v1/me/tokens', [TokenController::class, 'index']);
    $router->post('/api/v1/me/tokens', [TokenController::class, 'store']);
    $router->delete('/api/v1/me/tokens/{id}', [TokenController::class, 'destroy']);
};
