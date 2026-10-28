<?php

declare(strict_types=1);

use App\Controllers\PaymentsController;
use App\Core\Container;
use App\Core\Router;

return static function (Router $router, Container $container): void {
    $router->get('/api/v1/sales/{id}/payments', [PaymentsController::class, 'saleHistory']);
    $router->post('/api/v1/sales/{id}/payments', [PaymentsController::class, 'salePayment']);
    $router->post('/api/v1/projects/{id}/payments', [PaymentsController::class, 'projectPayment']);
    $router->post('/api/v1/sales/{id}/mark-paid', [PaymentsController::class, 'markPaid']);
    $router->post('/api/v1/payments/{id}/void', [PaymentsController::class, 'voidPayment']);
    $router->post('/api/v1/payments/{id}/refunds', [PaymentsController::class, 'refund']);
    $router->get('/api/v1/payments/{id}/refunds', [PaymentsController::class, 'refundHistory']);
};
