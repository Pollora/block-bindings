<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Infrastructure\Adapters;

use InvalidArgumentException;
use Pollora\BlockBinding\Domain\Contracts\MethodInvokerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Calls a source's method by reflection, for a project without Laravel's
 * container. A parameter takes the value given under its name or under its
 * class name; another class-typed parameter is asked of the container, if
 * there is one, or built when it needs no argument; else its default value, or
 * null when it allows it.
 */
final readonly class ReflectionMethodInvoker implements MethodInvokerInterface
{
    public function __construct(private ?ContainerInterface $container = null) {}

    public function call(object $instance, string $method, array $parameters): mixed
    {
        $reflection = new ReflectionMethod($instance, $method);
        $arguments = [];

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            $class = $type instanceof ReflectionNamedType && ! $type->isBuiltin() ? $type->getName() : null;

            $arguments[] = match (true) {
                array_key_exists($parameter->getName(), $parameters) => $parameters[$parameter->getName()],
                $class !== null && array_key_exists($class, $parameters) => $parameters[$class],
                $class !== null && ($service = $this->service($class)) !== null => $service,
                $parameter->isDefaultValueAvailable() => $parameter->getDefaultValue(),
                $type?->allowsNull() === true => null,
                default => throw new InvalidArgumentException(sprintf('Unable to resolve the parameter $%s of %s::%s().', $parameter->getName(), $instance::class, $method)),
            };
        }

        return $reflection->invokeArgs($instance, $arguments);
    }

    /**
     * A service the container gives, or an instance of a class that needs
     * nothing to be built.
     */
    private function service(string $class): ?object
    {
        if ($this->container?->has($class) === true) {
            return $this->container->get($class);
        }

        try {
            return $this->container?->get($class);
        } catch (ContainerExceptionInterface) {
            // not a service of this container: a class with no required argument is built below
        }

        $constructor = class_exists($class) ? (new ReflectionClass($class))->getConstructor() : null;

        return class_exists($class) && ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0) ? new $class : null;
    }
}
