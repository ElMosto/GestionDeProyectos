<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Config
{
    public function __construct(private readonly array $values)
    {
    }

    /**
     * @param string $key Ruta con puntos: "db.host"
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $cursor = $this->values;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    public function require(string $key): mixed
    {
        $value = $this->get($key);

        if ($value === null) {
            throw new RuntimeException(sprintf('Falta la clave de configuración "%s".', $key));
        }

        return $value;
    }
}
