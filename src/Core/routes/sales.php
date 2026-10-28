<?php

declare(strict_types=1);

use App\Controllers\CatalogController;
use App\Controllers\SalesController;
use App\Core\Container;
use App\Core\Router;

return static function (Router $router, Container $container): void {
    // Rutas literales para poder auditar la superficie y contrastarla con OpenAPI.
    $router->get('/api/v1/products', [CatalogController::class, 'index']);
    $router->post('/api/v1/products', [CatalogController::class, 'store']);
    $router->get('/api/v1/products/{id}', [CatalogController::class, 'show']);
    $router->patch('/api/v1/products/{id}', [CatalogController::class, 'update']);
    $router->get('/api/v1/extras', [CatalogController::class, 'index']);
    $router->post('/api/v1/extras', [CatalogController::class, 'store']);
    $router->get('/api/v1/extras/{id}', [CatalogController::class, 'show']);
    $router->patch('/api/v1/extras/{id}', [CatalogController::class, 'update']);
    $router->put('/api/v1/products/{id}/extras', [CatalogController::class, 'replaceExtras']);
    $router->get('/api/v1/sales', [SalesController::class, 'index']);
    $router->post('/api/v1/sales', [SalesController::class, 'store']);
    $router->get('/api/v1/sales/{id}', [SalesController::class, 'show']);
    $router->patch('/api/v1/sales/{id}', [SalesController::class, 'update']);
    $router->post('/api/v1/sales/{id}/issue', [SalesController::class, 'issue']);
    $router->post('/api/v1/sales/{id}/cancel', [SalesController::class, 'cancel']);
};
