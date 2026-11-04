<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Infrastructure\Adapters;

use Pollora\BlockBinding\Application\Services\DefaultMethodInvoker;
use Pollora\BlockBinding\Domain\Contracts\MethodInvokerInterface;
use Psr\Container\ContainerInterface;

/**
 * Calls a source's method by reflection, for a project without Laravel's
 * container. A parameter takes the value given under its name or under its
 * class name; another class-typed parameter is asked of the container, if
 * there is one, or built when it needs no argument; else its default value, or
 * null when it allows it.
 *
 * The work is the application layer's {@see DefaultMethodInvoker}, which the
 * resolver uses when it is given no invoker.
 */
final readonly class ReflectionMethodInvoker implements MethodInvokerInterface
{
    private DefaultMethodInvoker $invoker;

    public function __construct(?ContainerInterface $container = null)
    {
        $this->invoker = new DefaultMethodInvoker($container);
    }

    public function call(object $instance, string $method, array $parameters): mixed
    {
        return $this->invoker->call($instance, $method, $parameters);
    }
}
