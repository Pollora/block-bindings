<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Attributes\BlockBinding;
use Pollora\BlockBinding\Application\Services\BindingResolver;
use Pollora\BlockBinding\Application\Services\BindingSourceBuilder;
use Pollora\BlockBinding\Application\Services\BindingSourceRegistry;
use Pollora\BlockBinding\Infrastructure\Adapters\WordPressBindingRegistry;
use Pollora\BlockBinding\Infrastructure\Services\BlockBindingDiscovery;
use Pollora\BlockBinding\Tests\Fixtures\EventBinding;
use Pollora\BlockBinding\Tests\Fixtures\FakePresenter;
use Pollora\BlockBinding\Tests\Fixtures\FakeVisibility;
use Pollora\BlockBinding\Tests\Fixtures\InvokableBinding;
use Pollora\BlockBinding\Tests\Fixtures\NoField;
use Pollora\BlockBinding\Tests\Fixtures\SeatCounter;
use Pollora\BlockBinding\Tests\Fixtures\SimpleContainer;
use Pollora\Discovery\Domain\Models\DiscoveryLocation;
use Pollora\Hook\Domain\Contract\Action;
use Psr\Log\LoggerInterface;
use Spatie\StructureDiscoverer\Data\DiscoveredClass;

require_once dirname(__DIR__).'/Fixtures/Invalid.php';
require_once dirname(__DIR__).'/Fixtures/blocks.php';

beforeEach(function (): void {
    $this->registered = [];
    Functions\when('did_action')->justReturn(1);
    Functions\when('get_block_bindings_source')->alias(fn (string $name): ?object => isset($this->registered[$name]) ? (object) [] : null);
    Functions\when('register_block_bindings_source')->alias(function (string $name, array $properties): bool {
        $this->registered[$name] = $properties;

        return true;
    });

    $this->sources = new BindingSourceRegistry;
    $this->logger = Mockery::mock(LoggerInterface::class);
    $resolver = new BindingResolver(new SimpleContainer, new FakeVisibility, new FakePresenter);
    $this->discovery = new BlockBindingDiscovery(new BindingSourceBuilder, $this->sources, new WordPressBindingRegistry(Mockery::mock(Action::class), $resolver), $this->logger);
    $this->location = new DiscoveryLocation('Tests\\', __DIR__);

    $this->discover = function (string ...$classes): void {
        foreach ($classes as $class) {
            $this->discovery->discover($this->location, DiscoveredClass::fromReflection(new ReflectionClass($class)));
        }
    };
});

it('keeps the #[BlockBinding] classes only', function (): void {
    ($this->discover)(EventBinding::class, SeatCounter::class, InvokableBinding::class);

    expect(array_column(iterator_to_array($this->discovery->getItems()), 'class'))->toBe([EventBinding::class, InvokableBinding::class]);
});

it('registers each source with WordPress, its label, context and one callback', function (): void {
    ($this->discover)(EventBinding::class, InvokableBinding::class);

    $this->discovery->apply();

    expect(array_keys($this->registered))->toBe(['acme/event', 'acme/echo'])
        ->and($this->registered['acme/event']['label'])->toBe('Event')
        ->and($this->registered['acme/event']['uses_context'])->toBe(['postId', 'postType'])
        ->and(($this->registered['acme/echo']['get_value_callback'])(['say' => 'hello'], boundParagraph([]), 'content'))->toBe('hello')
        ->and($this->sources->find('acme/event'))->not->toBeNull();
});

it('waits for init when it has not run yet', function (): void {
    Functions\when('did_action')->justReturn(0);
    $action = Mockery::mock(Action::class);
    $action->shouldReceive('add')->once()->with('init', Mockery::type(Closure::class))->andReturnUsing(function (string $hook, Closure $callback) use ($action): Action {
        $callback();

        return $action;
    });

    (new WordPressBindingRegistry($action, new BindingResolver(new SimpleContainer, new FakeVisibility, new FakePresenter)))
        ->register((new BindingSourceBuilder)->build(InvokableBinding::class));

    expect(array_keys($this->registered))->toBe(['acme/echo']);
});

it('logs a source it cannot register and carries on', function (): void {
    $this->logger->shouldReceive('error')->once()->with(Mockery::pattern('/NoField: .*mark a public method/'), Mockery::type('array'));
    ($this->discover)(NoField::class, InvokableBinding::class);

    $this->discovery->apply();

    expect(array_keys($this->registered))->toBe(['acme/echo']);
});

it('refuses a source name another class declares', function (): void {
    $this->logger->shouldReceive('error')->once()->with(Mockery::pattern('/"acme\/echo" is declared twice/'), Mockery::type('array'));
    $this->sources->add((new BindingSourceBuilder)->build(InvokableBinding::class));
    $clone = new #[BlockBinding('acme/echo')] class
    {
        public function __invoke(): string
        {
            return '';
        }
    };
    $this->discovery->getItems()->add($this->location, ['class' => $clone::class]);

    $this->discovery->apply();
});
