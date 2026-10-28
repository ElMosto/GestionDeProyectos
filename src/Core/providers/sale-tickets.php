<?php

declare(strict_types=1);

use App\Controllers\SaleTicketsController;
use App\Core\Container;
use App\Core\Database;
use App\Exports\SaleTicket;
use App\Policies\Authorizer;
use App\Repositories\StudioRepository;
use App\Services\ProjectAudit;
use App\Services\SaleService;
use App\Services\SaleTicketService;

return static function (Container $container, string $root): void {
    $container->singleton(SaleTicket::class, static fn (): SaleTicket => new SaleTicket($root . '/templates'));
    $container->singleton(StudioRepository::class, static fn (Container $c): StudioRepository => new StudioRepository($c->get(Database::class)));
    $container->singleton(SaleTicketService::class, static fn (Container $c): SaleTicketService => new SaleTicketService(
        $c->get(SaleService::class), $c->get(StudioRepository::class), $c->get(Authorizer::class),
    ));
    $container->singleton(SaleTicketsController::class, static fn (Container $c): SaleTicketsController => new SaleTicketsController(
        $c->get(SaleTicketService::class), $c->get(SaleTicket::class), $c->get(ProjectAudit::class),
    ));
};
