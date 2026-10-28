<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\UserPresenter;

/** Forma UTC estable del módulo de reservas, sin cambiar serializadores de fase 3. */
final class ReservationPresenter
{
    public static function iso(?string $value): ?string
    {
        $iso = UserPresenter::iso($value);
        return $iso === null ? null : preg_replace('/\+00:00\z/', 'Z', $iso);
    }
}
