<?php

declare(strict_types=1);

use App\Controllers\UsersController;
use App\Core\Container;
use App\Core\Router;

/**
 * Administración de cuentas y directorio mínimo (§5, §15).
 * Todas las rutas exigen identidad; la capacidad se comprueba en el controlador.
 */
return static function (Router $router, Container $container): void {
    $router->get('/api/v1/users', [UsersController::class, 'index']);
    $router->get('/api/v1/users/directory', [UsersController::class, 'directory']);
    $router->post('/api/v1/users', [UsersController::class, 'store']);
    $router->get('/api/v1/users/{id}', [UsersController::class, 'show']);
    $router->patch('/api/v1/users/{id}', [UsersController::class, 'update']);
    $router->post('/api/v1/users/{id}/deactivate', [UsersController::class, 'deactivate']);
    $router->post('/api/v1/users/{id}/reactivate', [UsersController::class, 'reactivate']);
    $router->post('/api/v1/users/{id}/reset-password', [UsersController::class, 'resetPassword']);
};
