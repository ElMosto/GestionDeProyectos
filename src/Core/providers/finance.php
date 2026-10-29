<?php

declare(strict_types=1);

use App\Controllers\AuditLogsController;
use App\Controllers\ExpensesController;
use App\Controllers\FinanceController;
use App\Controllers\SettingsController;
use App\Core\Container;
use App\Core\Database;
use App\Policies\Authorizer;
use App\Repositories\AuditLogsRepository;
use App\Repositories\CommercialHistoryRepository;
use App\Repositories\ExpenseRepository;
use App\Repositories\FinanceRepository;
use App\Repositories\SequenceRepository;
use App\Repositories\SettingsRepository;
use App\Services\AuditLogsService;
use App\Services\ExpenseService;
use App\Services\FinanceService;
use App\Services\ProjectAudit;
use App\Services\ProjectIdempotency;
use App\Services\SettingsService;

return static function (Container $container, string $root): void {
    foreach ([AuditLogsRepository::class,CommercialHistoryRepository::class,ExpenseRepository::class,
        FinanceRepository::class,SettingsRepository::class] as $class) {
        $container->singleton($class, static fn (Container $c): object => new $class($c->get(Database::class)));
    }
    $container->singleton(FinanceService::class, static fn (Container $c): FinanceService => new FinanceService(
        $c->get(FinanceRepository::class),$c->get(Authorizer::class),$c->get(Database::class),
    ));
    $container->singleton(ExpenseService::class, static fn (Container $c): ExpenseService => new ExpenseService(
        $c->get(ExpenseRepository::class),$c->get(SequenceRepository::class),$c->get(Authorizer::class),
        $c->get(ProjectIdempotency::class),$c->get(ProjectAudit::class),
    ));
    $container->singleton(SettingsService::class, static fn (Container $c): SettingsService => new SettingsService(
        $c->get(SettingsRepository::class),$c->get(Authorizer::class),$c->get(Database::class),$c->get(ProjectAudit::class),
    ));
    $container->singleton(AuditLogsService::class, static fn (Container $c): AuditLogsService => new AuditLogsService(
        $c->get(AuditLogsRepository::class),$c->get(Authorizer::class),
    ));
    $container->singleton(FinanceController::class, static fn (Container $c): FinanceController => new FinanceController($c->get(FinanceService::class)));
    $container->singleton(AuditLogsController::class, static fn (Container $c): AuditLogsController => new AuditLogsController($c->get(AuditLogsService::class)));
    foreach ([ExpensesController::class => ExpenseService::class,SettingsController::class => SettingsService::class] as $controller => $service) {
        $container->singleton($controller, static fn (Container $c): object => new $controller($c->get($service),$c->get(ProjectAudit::class)));
    }
};
