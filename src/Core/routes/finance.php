<?php

declare(strict_types=1);

use App\Controllers\AuditLogsController;
use App\Controllers\ExpensesController;
use App\Controllers\FinanceController;
use App\Controllers\SettingsController;
use App\Core\Container;
use App\Core\Router;

return static function (Router $router, Container $container): void {
    $router->get('/api/v1/finance/summary', [FinanceController::class, 'summary']);
    $router->get('/api/v1/finance/cashflow', [FinanceController::class, 'cashflow']);
    $router->get('/api/v1/finance/receivables', [FinanceController::class, 'receivables']);
    $router->get('/api/v1/finance/transactions', [FinanceController::class, 'transactions']);
    $router->get('/api/v1/finance/documents', [FinanceController::class, 'documents']);
    $router->get('/api/v1/expenses', [ExpensesController::class, 'index']);
    $router->post('/api/v1/expenses', [ExpensesController::class, 'create']);
    $router->get('/api/v1/expenses/{id}', [ExpensesController::class, 'show']);
    $router->patch('/api/v1/expenses/{id}', [ExpensesController::class, 'update']);
    $router->get('/api/v1/audit-logs', [AuditLogsController::class, 'index']);
    $router->get('/api/v1/settings', [SettingsController::class, 'show']);
    $router->patch('/api/v1/settings', [SettingsController::class, 'update']);
};
