<?php

declare(strict_types=1);

use App\Controllers\ReservationsController;
use App\Controllers\SpacesController;
use App\Controllers\SpaceBlocksController;
use App\Core\Container;
use App\Core\Database;
use App\Policies\Authorizer;
use App\Repositories\ReservationRepository;
use App\Repositories\ReservationSaleRepository;
use App\Repositories\SpaceRepository;
use App\Repositories\SpaceOccupancyRepository;
use App\Repositories\SpaceBlockRepository;
use App\Repositories\SequenceRepository;
use App\Repositories\StudioRepository;
use App\Services\ReservationService;
use App\Services\SpaceService;
use App\Services\SpaceOccupancyService;
use App\Services\SpaceBlockService;
use App\Services\PaymentLedger;
use App\Services\ProjectAudit;
use App\Services\ProjectIdempotency;

return static function (Container $container, string $root): void {
    foreach ([ReservationRepository::class, ReservationSaleRepository::class, SpaceRepository::class,
        SpaceOccupancyRepository::class, SpaceBlockRepository::class] as $class) {
        $container->singleton($class, static fn (Container $c): object => new $class($c->get(Database::class)));
    }
    $container->singleton(SpaceOccupancyService::class, static fn (Container $c): SpaceOccupancyService => new SpaceOccupancyService(
        $c->get(SpaceRepository::class), $c->get(SpaceOccupancyRepository::class), $c->get(StudioRepository::class),
    ));
    $container->singleton(SpaceService::class, static fn (Container $c): SpaceService => new SpaceService(
        $c->get(SpaceRepository::class), $c->get(SpaceOccupancyRepository::class), $c->get(SpaceOccupancyService::class),
        $c->get(Database::class), $c->get(Authorizer::class), $c->get(ProjectAudit::class),
    ));
    $container->singleton(ReservationService::class, static fn (Container $c): ReservationService => new ReservationService(
        $c->get(ReservationRepository::class), $c->get(ReservationSaleRepository::class), $c->get(SpaceOccupancyRepository::class),
        $c->get(SpaceOccupancyService::class), $c->get(SequenceRepository::class), $c->get(PaymentLedger::class),
        $c->get(Authorizer::class), $c->get(ProjectAudit::class), $c->get(ProjectIdempotency::class),
    ));
    $container->singleton(SpaceBlockService::class, static fn (Container $c): SpaceBlockService => new SpaceBlockService(
        $c->get(SpaceBlockRepository::class), $c->get(SpaceOccupancyRepository::class), $c->get(SpaceOccupancyService::class),
        $c->get(Authorizer::class), $c->get(ProjectAudit::class), $c->get(ProjectIdempotency::class),
    ));
    $container->singleton(SpacesController::class, static fn (Container $c): SpacesController => new SpacesController(
        $c->get(SpaceService::class), $c->get(ProjectAudit::class),
    ));
    $container->singleton(ReservationsController::class, static fn (Container $c): ReservationsController => new ReservationsController(
        $c->get(ReservationService::class), $c->get(ProjectAudit::class),
    ));
    $container->singleton(SpaceBlocksController::class, static fn (Container $c): SpaceBlocksController => new SpaceBlocksController(
        $c->get(SpaceBlockService::class), $c->get(ProjectAudit::class),
    ));
};
