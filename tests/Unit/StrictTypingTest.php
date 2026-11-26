<?php

declare(strict_types=1);

use Pollora\BlockBinding\Application\Services\BindingResolver;
use Pollora\BlockBinding\Application\Services\BindingSourceBuilder;
use Pollora\BlockBinding\Application\Services\DefaultMethodInvoker;
use Pollora\BlockBinding\Domain\Exceptions\InvalidBindingSourceException;
use Pollora\BlockBinding\Tests\Fixtures\EventBinding;
use Pollora\BlockBinding\Tests\Fixtures\FakePresenter;
use Pollora\BlockBinding\Tests\Fixtures\FakeVisibility;
use Psr\Container\ContainerInterface;

require_once dirname(__DIR__).'/Fixtures/blocks.php';

final class MisbehavingService {}

final class MisbehavingTarget
{
    public function service(MisbehavingService $service): MisbehavingService
    {
        return $service;
    }
}

/**
 * A container that answers every id with a string.
 */
function stringContainer(bool $has): ContainerInterface
{
    return new class($has) implements ContainerInterface
    {
        public function __construct(private readonly bool $has) {}

        public function get(string $id): mixed
        {
            return 'not an object';
        }

        public function has(string $id): bool
        {
            return $this->has;
        }
    };
}

it('refuses a service the container gives that is not an object', function (bool $has): void {
    expect(fn (): mixed => (new DefaultMethodInvoker(stringContainer($has)))->call(new MisbehavingTarget, 'service', []))
        ->toThrow(InvalidBindingSourceException::class, 'The container gives no object for MisbehavingService.');
})->with(['a service it holds' => [true], 'a service it does not say it holds' => [false]]);

it('refuses a source the container gives no object for', function (): void {
    $resolver = new BindingResolver(stringContainer(true), new FakeVisibility, new FakePresenter, null, true);
    $source = (new BindingSourceBuilder)->build(EventBinding::class);

    expect(fn (): mixed => $resolver->resolve($source, ['field' => 'capacity'], boundParagraph(), 'content'))
        ->toThrow(InvalidBindingSourceException::class, 'The container gives no object for '.EventBinding::class.'.');
});
