<?php

declare(strict_types=1);

use App\Controllers\CatalogController;
use App\Controllers\SalesController;
use App\Core\Container;
use App\Core\Database;
use App\Policies\Authorizer;
use App\Repositories\CatalogRepository;
use App\Repositories\SaleRepository;
use App\Repositories\SequenceRepository;
use App\Services\CatalogService;
use App\Services\PaymentLedger;
use App\Services\ProjectAudit;
use App\Services\ProjectIdempotency;
use App\Services\SaleCalculatorService;
use App\Services\SaleService;

return static function (Container $container, string $root): void {
    foreach ([CatalogRepository::class, SaleRepository::class] as $class) {
        $container->singleton($class, static fn (Container $c): object => new $class($c->get(Database::class)));
    }
    $container->singleton(CatalogService::class, static fn (Container $c): CatalogService => new CatalogService(
        $c->get(CatalogRepository::class), $c->get(Database::class), $c->get(Authorizer::class), $c->get(ProjectAudit::class),
    ));
    $container->singleton(SaleCalculatorService::class, static fn (Container $c): SaleCalculatorService => new SaleCalculatorService(
        $c->get(CatalogRepository::class), $c->get(Authorizer::class),
    ));
    $container->singleton(SaleService::class, static fn (Container $c): SaleService => new SaleService(
        $c->get(SaleRepository::class), $c->get(SequenceRepository::class), $c->get(SaleCalculatorService::class),
        $c->get(PaymentLedger::class), $c->get(Database::class), $c->get(Authorizer::class),
        $c->get(ProjectAudit::class), $c->get(ProjectIdempotency::class),
    ));
    $container->singleton(CatalogController::class, static fn (Container $c): CatalogController => new CatalogController(
        $c->get(CatalogService::class), $c->get(ProjectAudit::class),
    ));
    $container->singleton(SalesController::class, static fn (Container $c): SalesController => new SalesController(
        $c->get(SaleService::class), $c->get(ProjectAudit::class),
    ));
};
