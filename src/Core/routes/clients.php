<?php

declare(strict_types=1);

use App\Controllers\ClientsController;
use App\Core\Container;
use App\Core\Router;

/**
 * Clientes (§7, §15). Todas las rutas exigen identidad y capacidad global.
 */
return static function (Router $router, Container $container): void {
    $router->get('/api/v1/clients', [ClientsController::class, 'index']);
    $router->post('/api/v1/clients', [ClientsController::class, 'store']);
    $router->get('/api/v1/clients/{id}', [ClientsController::class, 'show']);
    $router->patch('/api/v1/clients/{id}', [ClientsController::class, 'update']);
    $router->post('/api/v1/clients/{id}/deactivate', [ClientsController::class, 'deactivate']);
};
