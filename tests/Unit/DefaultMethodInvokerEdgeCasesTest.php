<?php

declare(strict_types=1);

use Pollora\BlockBinding\Application\Services\DefaultMethodInvoker;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

final class EdgeNeedsArgument
{
    public function __construct(public int $value) {}
}

final class EdgeOptionalArgument
{
    public function __construct(public int $value = 3) {}
}

final class EdgeTarget
{
    public function untypedWithDefault($value = 'default'): mixed
    {
        return $value;
    }

    public function untyped($value): mixed
    {
        return $value;
    }

    public function union(int|string $value = 'either'): int|string
    {
        return $value;
    }

    public function optional(EdgeOptionalArgument $service): EdgeOptionalArgument
    {
        return $service;
    }

    public function required(EdgeNeedsArgument $service): EdgeNeedsArgument
    {
        return $service;
    }
}

beforeEach(function (): void {
    // A container that counts what it is asked, and answers `has()` as told.
    $this->container = fn (bool $has, ?object $instance): ContainerInterface => new class($has, $instance) implements ContainerInterface
    {
        public int $gets = 0;

        public function __construct(private readonly bool $has, private readonly ?object $instance) {}

        public function get(string $id): mixed
        {
            $this->gets++;

            return $this->instance ?? throw new class('No.') extends RuntimeException implements ContainerExceptionInterface {};
        }

        public function has(string $id): bool
        {
            return $this->has;
        }
    };
});

it('takes the default of a parameter with no type, or a union type, nothing gives', function (): void {
    $invoker = new DefaultMethodInvoker;

    expect($invoker->call(new EdgeTarget, 'untypedWithDefault', []))->toBe('default')
        ->and($invoker->call(new EdgeTarget, 'union', []))->toBe('either')
        ->and($invoker->call(new EdgeTarget, 'untypedWithDefault', ['value' => 'given']))->toBe('given');
});

it('names an untyped parameter it cannot resolve', function (): void {
    expect(fn () => (new DefaultMethodInvoker)->call(new EdgeTarget, 'untyped', []))
        ->toThrow(InvalidArgumentException::class, 'Unable to resolve the parameter $value of EdgeTarget::untyped().');
});

it('does not resolve a class that needs an argument without a container either', function (): void {
    expect(fn () => (new DefaultMethodInvoker)->call(new EdgeTarget, 'required', []))
        ->toThrow(InvalidArgumentException::class, 'Unable to resolve the parameter $service of EdgeTarget::required().');
});

it('asks the container once for a service it holds', function (): void {
    $service = new EdgeOptionalArgument(9);
    $container = ($this->container)(true, $service);

    expect((new DefaultMethodInvoker($container))->call(new EdgeTarget, 'optional', []))->toBe($service)
        ->and($container->gets)->toBe(1);
});

it('takes the service of a container that holds it without saying so', function (): void {
    $service = new EdgeOptionalArgument(9);
    $container = ($this->container)(false, $service);

    expect((new DefaultMethodInvoker($container))->call(new EdgeTarget, 'optional', []))->toBe($service);
});

it('builds a class whose constructor asks for nothing, when the container does not know it', function (): void {
    $built = (new DefaultMethodInvoker(($this->container)(false, null)))->call(new EdgeTarget, 'optional', []);

    expect($built)->toBeInstanceOf(EdgeOptionalArgument::class)
        ->and($built->value)->toBe(3);
});
