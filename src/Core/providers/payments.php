<?php

declare(strict_types=1);

use App\Controllers\PaymentsController;
use App\Core\Container;
use App\Core\Database;
use App\Policies\Authorizer;
use App\Repositories\PaymentDocumentRepository;
use App\Repositories\PaymentLedgerRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\RefundRepository;
use App\Repositories\SequenceRepository;
use App\Services\PaymentLedger;
use App\Services\PaymentService;
use App\Services\ProjectAudit;
use App\Services\ProjectIdempotency;

return static function (Container $container, string $root): void {
    foreach ([PaymentDocumentRepository::class, PaymentLedgerRepository::class, PaymentRepository::class,
        RefundRepository::class] as $class) {
        $container->singleton($class, static fn (Container $c): object => new $class($c->get(Database::class)));
    }
    $container->singleton(PaymentLedger::class, static fn (Container $c): PaymentLedger => new PaymentLedger(
        $c->get(PaymentLedgerRepository::class),
    ));
    $container->singleton(PaymentService::class, static fn (Container $c): PaymentService => new PaymentService(
        $c->get(PaymentDocumentRepository::class), $c->get(PaymentRepository::class), $c->get(RefundRepository::class),
        $c->get(PaymentLedgerRepository::class), $c->get(PaymentLedger::class), $c->get(SequenceRepository::class),
        $c->get(Authorizer::class), $c->get(ProjectIdempotency::class), $c->get(ProjectAudit::class),
    ));
    $container->singleton(PaymentsController::class, static fn (Container $c): PaymentsController => new PaymentsController(
        $c->get(PaymentService::class), $c->get(ProjectAudit::class),
    ));
};
