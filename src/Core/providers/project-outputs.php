<?php

declare(strict_types=1);

use App\Controllers\ProjectOutputsController;
use App\Core\Container;
use App\Core\Database;
use App\Exports\ProjectGantt;
use App\Exports\ProjectTicket;
use App\Policies\Authorizer;
use App\Repositories\ProjectOutputRepository;
use App\Repositories\StudioRepository;
use App\Services\ProjectAudit;
use App\Services\ProjectOutputService;
use App\Services\ProjectService;

return static function (Container $container, string $root): void {
    $container->singleton(ProjectOutputRepository::class, static fn (Container $c): ProjectOutputRepository => new ProjectOutputRepository($c->get(Database::class)));
    $container->singleton(ProjectOutputService::class, static fn (Container $c): ProjectOutputService => new ProjectOutputService(
        $c->get(ProjectService::class), $c->get(ProjectOutputRepository::class), $c->get(StudioRepository::class), $c->get(Authorizer::class), $c->get(Database::class),
    ));
    $container->singleton(ProjectGantt::class, static fn (): ProjectGantt => new ProjectGantt());
    $container->singleton(ProjectTicket::class, static fn (): ProjectTicket => new ProjectTicket($root . '/templates'));
    $container->singleton(ProjectOutputsController::class, static fn (Container $c): ProjectOutputsController => new ProjectOutputsController(
        $c->get(ProjectOutputService::class), $c->get(ProjectGantt::class), $c->get(ProjectTicket::class), $c->get(ProjectAudit::class),
    ));
};
