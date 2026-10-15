<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Tests\Fixtures;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * A PSR-11 container with no wiring: it knows the instances it is given, and makes any other class
 * that needs no argument.
 */
final class SimpleContainer implements ContainerInterface
{
    /** @var array<string, object> */
    private array $instances = [];

    public function instance(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }

    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (! class_exists($id)) {
            throw new class(sprintf('No entry "%s".', $id)) extends RuntimeException implements NotFoundExceptionInterface {};
        }

        return $this->instances[$id] = new $id;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]);
    }
}
