<?php

declare(strict_types=1);

use Pollora\BlockBinding\Application\Services\DefaultMethodInvoker;
use Pollora\BlockBinding\Tests\Fixtures\SimpleContainer;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

final class InvokerNeedsArgument
{
    public function __construct(public int $value) {}
}

final class InvokerNeedsNothing {}

final class InvokerTarget
{
    public function service(InvokerNeedsNothing $service): InvokerNeedsNothing
    {
        return $service;
    }

    public function heavy(?InvokerNeedsArgument $service): ?InvokerNeedsArgument
    {
        return $service;
    }

    public function required(InvokerNeedsArgument $service): InvokerNeedsArgument
    {
        return $service;
    }

    public function optionalString(?string $label): ?string
    {
        return $label;
    }

    public function defaulted(string $label = 'fallback'): string
    {
        return $label;
    }

    public function mandatory(string $label): string
    {
        return $label;
    }
}

/**
 * @param  array<string, object>  $instances
 */
function failingContainer(array $instances = []): ContainerInterface
{
    return new class($instances) implements ContainerInterface
    {
        /** @param array<string, object> $instances */
        public function __construct(private array $instances) {}

        public function get(string $id): mixed
        {
            return $this->instances[$id] ?? throw new class('Not a service.') extends RuntimeException implements ContainerExceptionInterface {};
        }

        public function has(string $id): bool
        {
            return isset($this->instances[$id]);
        }
    };
}

it('takes a parameter from the values given, by name', function (): void {
    expect((new DefaultMethodInvoker)->call(new InvokerTarget, 'mandatory', ['label' => 'given']))->toBe('given');
});

it('takes a class-typed parameter from the values given under its class name', function (): void {
    $service = new InvokerNeedsNothing;

    expect((new DefaultMethodInvoker)->call(new InvokerTarget, 'service', [InvokerNeedsNothing::class => $service]))->toBe($service);
});

it('asks the container for a class-typed parameter it holds', function (): void {
    $service = new InvokerNeedsNothing;
    $container = new SimpleContainer;
    $container->instance(InvokerNeedsNothing::class, $service);

    expect((new DefaultMethodInvoker($container))->call(new InvokerTarget, 'service', []))->toBe($service);
});

it('builds a class that needs nothing when the container does not know it', function (): void {
    $built = (new DefaultMethodInvoker(failingContainer()))->call(new InvokerTarget, 'service', []);

    expect($built)->toBeInstanceOf(InvokerNeedsNothing::class);
});

it('does not build a class with a required constructor argument, and passes null when the parameter allows it', function (): void {
    expect((new DefaultMethodInvoker(failingContainer()))->call(new InvokerTarget, 'heavy', []))->toBeNull();
});

it('uses the default value of a parameter nothing gives', function (): void {
    expect((new DefaultMethodInvoker)->call(new InvokerTarget, 'defaulted', []))->toBe('fallback');
});

it('passes null for a nullable parameter without a default', function (): void {
    expect((new DefaultMethodInvoker)->call(new InvokerTarget, 'optionalString', []))->toBeNull();
});

it('names the parameter it cannot resolve', function (string $method): void {
    expect(fn () => (new DefaultMethodInvoker(failingContainer()))->call(new InvokerTarget, $method, []))
        ->toThrow(InvalidArgumentException::class, sprintf('Unable to resolve the parameter $%s of InvokerTarget::%s().', $method === 'mandatory' ? 'label' : 'service', $method));
})->with(['a scalar with no default' => ['mandatory'], 'a class that cannot be built' => ['required']]);
