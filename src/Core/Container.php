<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use RuntimeException;

final class Container
{
    /** @var array<string, callable(self):object> */
    private array $factories = [];
    /** @var array<string, object> */
    private array $instances = [];

    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }

    public function singleton(string $id, callable $factory): void
    {
        $this->set($id, function (self $c) use ($id, $factory): object {
            return $c->instances[$id] ??= $factory($c);
        });
    }

    public function get(string $id): object
    {
        if (isset($this->instances[$id]) && is_object($this->instances[$id])) {
            return $this->instances[$id];
        }

        $factory = $this->factories[$id] ?? null;

        if ($factory === null) {
            if (class_exists($id)) {
                return new $id();
            }

            throw new RuntimeException(sprintf('Servicio no registrado: %s', $id));
        }

        /** @var object $instance */
        $instance = $factory($this);
        $this->instances[$id] = $instance;

        return $instance;
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || isset($this->instances[$id]) || class_exists($id);
    }
}
