<?php

declare(strict_types=1);

use App\Controllers\SaleTicketsController;
use App\Core\Container;
use App\Core\Router;

return static function (Router $router, Container $container): void {
    $router->get('/api/v1/sales/{id}/ticket', [SaleTicketsController::class, 'show']);
};
