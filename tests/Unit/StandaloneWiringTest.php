<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\BlockBinding\Application\Services\BindingResolver;
use Pollora\BlockBinding\Application\Services\BindingSourceBuilder;
use Pollora\BlockBinding\Application\Services\BindingSourceRegistry;
use Pollora\BlockBinding\Infrastructure\Adapters\WordPressBindingRegistry;
use Pollora\BlockBinding\Infrastructure\Services\BindingFormatter;
use Pollora\BlockBinding\Infrastructure\Services\BlockBindingDiscovery;
use Pollora\BlockBinding\Infrastructure\Sources\PostMetaSource;
use Pollora\BlockBinding\Infrastructure\Sources\TypedMetaReader;
use Pollora\BlockBinding\Tests\Fixtures\ArrayMetaStore;
use Pollora\BlockBinding\Tests\Fixtures\Concert;
use Pollora\BlockBinding\Tests\Fixtures\EventBinding;
use Pollora\BlockBinding\Tests\Fixtures\FakePresenter;
use Pollora\BlockBinding\Tests\Fixtures\FakeVisibility;
use Pollora\Discovery\Domain\Models\DiscoveryLocation;
use Pollora\Hook\Adapter\Out\WordPress\Action;
use Pollora\Meta\Application\Services\MetaAccessor;
use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Application\Services\MetaSchemaRepository;
use Pollora\Meta\Domain\Services\MetaValueCaster;
use Psr\Container\ContainerInterface;
use Spatie\StructureDiscoverer\Data\DiscoveredClass;

require_once dirname(__DIR__).'/Fixtures/blocks.php';

/**
 * The wiring the README shows for a project without Laravel: a PSR-11 container that knows the sources, the
 * resolver on top, the sources registered with WordPress, and a project source found by discovery.
 */
it('declares a source, registers it with WordPress and resolves a bound value with nothing but its own classes', function (): void {
    $registered = [];
    Functions\when('did_action')->justReturn(1);
    Functions\when('get_block_bindings_source')->justReturn(null);
    Functions\when('number_format_i18n')->alias(fn (float|int $number, int $decimals = 0): string => number_format((float) $number, $decimals, ',', ' '));
    Functions\when('register_block_bindings_source')->alias(function (string $name, array $args) use (&$registered): void {
        $registered[$name] = $args;
    });

    $schemas = new MetaSchemaRepository;
    $schemas->add((new MetaSchemaBuilder)->build(Concert::class));
    $meta = new MetaAccessor($schemas, new MetaSchemaBuilder, new ArrayMetaStore(['post' => [7 => ['capacity' => '1200']]]), new MetaValueCaster);
    $services = [
        PostMetaSource::class => fn () => new PostMetaSource(new TypedMetaReader($schemas, $meta), new BindingFormatter(new FakePresenter)),
        EventBinding::class => fn () => new EventBinding,
    ];
    $container = new class($services) implements ContainerInterface
    {
        public function __construct(private readonly array $services) {}

        public function get(string $id): mixed
        {
            return ($this->services[$id])();
        }

        public function has(string $id): bool
        {
            return isset($this->services[$id]);
        }
    };

    $builder = new BindingSourceBuilder;
    $sources = new BindingSourceRegistry;
    $registry = new WordPressBindingRegistry(new Action, new BindingResolver($container, new FakeVisibility, new FakePresenter, $meta));

    $discovery = new BlockBindingDiscovery($builder, $sources, $registry);
    $discovery->discover(new DiscoveryLocation('Pollora\\BlockBinding\\Tests\\', __DIR__), DiscoveredClass::fromReflection(new ReflectionClass(EventBinding::class)));
    $discovery->apply();

    $shipped = $builder->build(PostMetaSource::class);
    $sources->add($shipped);
    $registry->register($shipped);

    $block = boundParagraph(['postId' => 7, 'postType' => 'concert']);

    expect(array_keys($registered))->toBe(['acme/event', 'pollora/post-meta'])
        ->and($registered['pollora/post-meta']['get_value_callback'](['key' => 'capacity'], $block, 'content'))->toBe('1 200')
        ->and($registered['acme/event']['get_value_callback'](['field' => 'sold_out'], boundParagraph(), 'content'))->toBe('Yes');
});
