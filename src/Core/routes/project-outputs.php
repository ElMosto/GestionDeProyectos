<?php

declare(strict_types=1);

use App\Controllers\ProjectOutputsController;
use App\Core\Container;
use App\Core\Router;

return static function (Router $router, Container $container): void {
    $router->get('/api/v1/projects/{id}/gantt.xlsx', [ProjectOutputsController::class, 'gantt']);
    $router->get('/api/v1/projects/{id}/ticket', [ProjectOutputsController::class, 'ticket']);
};
