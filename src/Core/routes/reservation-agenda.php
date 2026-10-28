<?php

declare(strict_types=1);

use App\Controllers\ReservationAgendaController;
use App\Core\Container;
use App\Core\Router;

return static function (Router $router, Container $container): void {
    $router->get('/api/v1/agenda/reservations', [ReservationAgendaController::class, 'index']);
};
