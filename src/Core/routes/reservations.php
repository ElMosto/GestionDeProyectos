<?php

declare(strict_types=1);

use App\Controllers\ReservationsController;
use App\Controllers\SpacesController;
use App\Controllers\SpaceBlocksController;
use App\Core\Container;
use App\Core\Router;

return static function (Router $router, Container $container): void {
    $router->get('/api/v1/spaces', [SpacesController::class, 'index']);
    $router->post('/api/v1/spaces', [SpacesController::class, 'store']);
    $router->get('/api/v1/spaces/{id}', [SpacesController::class, 'show']);
    $router->patch('/api/v1/spaces/{id}', [SpacesController::class, 'update']);
    $router->put('/api/v1/spaces/{id}/hours', [SpacesController::class, 'hours']);
    $router->get('/api/v1/spaces/{id}/availability', [SpacesController::class, 'availability']);
    $router->get('/api/v1/reservations', [ReservationsController::class, 'index']);
    $router->post('/api/v1/reservations', [ReservationsController::class, 'store']);
    $router->get('/api/v1/reservations/{id}', [ReservationsController::class, 'show']);
    $router->patch('/api/v1/reservations/{id}', [ReservationsController::class, 'update']);
    $router->post('/api/v1/reservations/{id}/reschedule', [ReservationsController::class, 'reschedule']);
    $router->post('/api/v1/reservations/{id}/transitions', [ReservationsController::class, 'transition']);
    $router->post('/api/v1/reservations/{id}/cancel', [ReservationsController::class, 'cancel']);
    $router->get('/api/v1/space-blocks', [SpaceBlocksController::class, 'index']);
    $router->post('/api/v1/space-blocks', [SpaceBlocksController::class, 'store']);
    $router->post('/api/v1/space-blocks/{id}/cancel', [SpaceBlocksController::class, 'cancel']);
};
