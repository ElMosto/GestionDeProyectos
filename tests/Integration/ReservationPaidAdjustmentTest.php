<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Policies\Role;
use App\Tests\Support\InProcessApi as Api;
use App\Tests\Support\ReservationFixtures as F;
use App\Tests\Support\SalesFixtures;

/** D-15/D-21: mover horario no concede ajustar documentos ya cobrados. */
final class ReservationPaidAdjustmentTest extends DatabaseTestCase
{
    public function test_E_paid_reservation_owner_can_move_time_but_only_admin_changes_its_price(): void
    {
        $f = new F($this->fixtures);
        [, $admin] = $f->actor();
        [$owner, $token] = $f->actor(Role::COLABORADOR);
        $band = $f->band($owner['id']);
        $space = $f->space($admin);
        $reservation = F::reserve($token, $band, $space);
        SalesFixtures::pay($token, $reservation['sale_id'], '30.00');
        $path = '/api/v1/reservations/' . $reservation['id'];
        $current = F::ok(Api::get($path, bearer: $token));
        $moved = F::ok(Api::post($path . '/reschedule', F::move($current), $token, F::key()));
        $this->assertSame('180.00', $moved['total']);
        $before = F::snapshot($this->pdo);
        $longer = F::move($moved, ['end_at' => '2030-01-08T12:00:00Z']);
        $rejected = Api::post($path . '/reschedule', $longer, $token, F::key());
        $this->assertSame(403, $rejected->status, $rejected->rawBody);
        $this->assertSame($before, F::snapshot($this->pdo));
        $allowed = F::ok(Api::post($path . '/reschedule', $longer, $admin, F::key()));
        $this->assertSame('240.00', $allowed['total']);
        $this->assertSame($reservation['sale_id'], $allowed['sale_id']);
        $this->assertSame('30.00', $this->pdo->query('SELECT amount FROM payments')->fetchColumn());
    }
}
