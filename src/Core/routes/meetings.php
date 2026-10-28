<?php

declare(strict_types=1);

use App\Controllers\MeetingsController;
use App\Controllers\NotificationsController;
use App\Core\Container;
use App\Core\Router;

return static function (Router $router, Container $container): void {
    $router->get('/api/v1/meetings', [MeetingsController::class, 'index']);
    $router->post('/api/v1/meetings', [MeetingsController::class, 'store']);
    $router->get('/api/v1/meetings/{id}', [MeetingsController::class, 'show']);
    $router->patch('/api/v1/meetings/{id}', [MeetingsController::class, 'update']);
    $router->post('/api/v1/meetings/{id}/cancel', [MeetingsController::class, 'cancel']);
    $router->post('/api/v1/meetings/{id}/response', [MeetingsController::class, 'respond']);
    $router->get('/api/v1/agenda/meetings', [MeetingsController::class, 'agenda']);
    $router->get('/api/v1/notifications', [NotificationsController::class, 'index']);
    $router->post('/api/v1/notifications/{id}/read', [NotificationsController::class, 'read']);
};
