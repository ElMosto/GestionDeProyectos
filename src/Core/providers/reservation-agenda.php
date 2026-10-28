<?php

declare(strict_types=1);

use App\Controllers\ReservationAgendaController;
use App\Core\Container;
use App\Services\ReservationService;

return static function (Container $container, string $root): void {
    $container->singleton(ReservationAgendaController::class, static fn (Container $c): ReservationAgendaController => new ReservationAgendaController(
        $c->get(ReservationService::class),
    ));
};
