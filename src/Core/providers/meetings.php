<?php

declare(strict_types=1);

use App\Controllers\MeetingsController;
use App\Controllers\NotificationsController;
use App\Core\Container;
use App\Core\Database;
use App\Policies\Authorizer;
use App\Repositories\MeetingRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\SpaceOccupancyRepository;
use App\Services\MeetingPresenter;
use App\Services\MeetingService;
use App\Services\NotificationService;
use App\Services\ProjectAudit;
use App\Services\ProjectIdempotency;
use App\Services\SpaceOccupancyService;

return static function (Container $container, string $root): void {
    foreach ([MeetingRepository::class, NotificationRepository::class] as $class) {
        $container->singleton($class, static fn (Container $c): object => new $class($c->get(Database::class)));
    }
    $container->singleton(NotificationService::class, static fn (Container $c): NotificationService => new NotificationService(
        $c->get(NotificationRepository::class), $c->get(Database::class), $c->get(Authorizer::class),
    ));
    $container->singleton(MeetingPresenter::class, static fn (Container $c): MeetingPresenter => new MeetingPresenter($c->get(Authorizer::class)));
    $container->singleton(MeetingService::class, static fn (Container $c): MeetingService => new MeetingService(
        $c->get(MeetingRepository::class), $c->get(SpaceOccupancyRepository::class), $c->get(SpaceOccupancyService::class),
        $c->get(NotificationService::class), $c->get(MeetingPresenter::class), $c->get(Authorizer::class),
        $c->get(ProjectAudit::class), $c->get(ProjectIdempotency::class),
    ));
    $container->singleton(MeetingsController::class, static fn (Container $c): MeetingsController => new MeetingsController(
        $c->get(MeetingService::class), $c->get(ProjectAudit::class),
    ));
    $container->singleton(NotificationsController::class, static fn (Container $c): NotificationsController => new NotificationsController($c->get(NotificationService::class)));
};
