<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Attributes\BlockBinding;
use Pollora\BlockBinding\Application\Services\BindingResolver;
use Pollora\BlockBinding\Application\Services\BindingSourceBuilder;
use Pollora\BlockBinding\Application\Services\BindingSourceRegistry;
use Pollora\BlockBinding\Domain\Contracts\MethodInvokerInterface;
use Pollora\BlockBinding\Domain\Contracts\PostFinderInterface;
use Pollora\BlockBinding\Domain\Exceptions\InvalidBindingSourceException;
use Pollora\BlockBinding\Domain\Models\BindingContext;
use Pollora\BlockBinding\Infrastructure\Adapters\WordPressBindingEditorScript;
use Pollora\BlockBinding\Infrastructure\Adapters\WordPressBindingRegistry;
use Pollora\BlockBinding\Infrastructure\Adapters\WordPressContentVisibility;
use Pollora\BlockBinding\Infrastructure\Services\BindingEditorData;
use Pollora\BlockBinding\Infrastructure\Services\BlockBindingDiscovery;
use Pollora\BlockBinding\Tests\Fixtures\ArrayMetaStore;
use Pollora\BlockBinding\Tests\Fixtures\ArtistProfile;
use Pollora\BlockBinding\Tests\Fixtures\FakePresenter;
use Pollora\BlockBinding\Tests\Fixtures\FakeVisibility;
use Pollora\BlockBinding\Tests\Fixtures\HelperFirst;
use Pollora\BlockBinding\Tests\Fixtures\IntersectionField;
use Pollora\BlockBinding\Tests\Fixtures\InvokableBinding;
use Pollora\BlockBinding\Tests\Fixtures\NoField;
use Pollora\BlockBinding\Tests\Fixtures\SimpleContainer;
use Pollora\BlockBinding\Tests\Fixtures\UnionField;
use Pollora\BlockBinding\Tests\Fixtures\UntypedInvokable;
use Pollora\BlockBinding\UI\Http\ResolveBindingsController;
use Pollora\Colt\Model\Post;
use Pollora\Discovery\Domain\Models\DiscoveryLocation;
use Pollora\Hook\Domain\Contract\Action;
use Pollora\Meta\Application\Services\MetaAccessor;
use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Application\Services\MetaSchemaRepository;
use Pollora\Meta\Domain\Services\MetaValueCaster;
use Psr\Log\LoggerInterface;
use Spatie\StructureDiscoverer\Data\DiscoveredClass;
use Spatie\StructureDiscoverer\Data\DiscoveredInterface;

require_once dirname(__DIR__).'/Fixtures/Invalid.php';
require_once dirname(__DIR__).'/Fixtures/blocks.php';

#[BlockBinding('acme/abstract-marked')]
abstract class EdgeAbstractMarkedBinding
{
    abstract public function __invoke(): string;
}

#[BlockBinding('acme/interface-marked')]
interface EdgeInterfaceMarkedBinding {}

describe('the preview route', function (): void {
    beforeEach(function (): void {
        Functions\when('get_locale')->justReturn('fr_FR');
        Functions\when('switch_to_locale')->justReturn(false);
        Functions\when('sanitize_key')->alias(fn (string $key): string => strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', $key)));

        $this->invoker = new class implements MethodInvokerInterface
        {
            /** @var list<BindingContext> */
            public array $contexts = [];

            public function call(object $instance, string $method, array $parameters): mixed
            {
                $this->contexts[] = $parameters['context'];

                return 'seen';
            }
        };
        $sources = new BindingSourceRegistry;
        $sources->add((new BindingSourceBuilder)->build(InvokableBinding::class));
        $this->controller = new ResolveBindingsController($sources, new BindingResolver(new SimpleContainer, new FakeVisibility, new FakePresenter, invoker: $this->invoker));
        $this->request = function (array $params): WP_REST_Request {
            $request = Mockery::mock(WP_REST_Request::class);
            $request->shouldReceive('get_param')->andReturnUsing(fn (string $name): mixed => $params[$name] ?? null);

            return $request;
        };
        $this->bind = fn (array|object $context) => $this->controller->handle(($this->request)(['context' => $context, 'bindings' => [['key' => 'a', 'source' => 'acme/echo']]]));
    });

    it('registers the whole route definition', function (): void {
        $registered = null;
        Functions\when('register_rest_route')->alias(function (string $namespace, string $route, array $args) use (&$registered): bool {
            $registered = [$namespace, $route, $args];

            return true;
        });

        $this->controller->register();

        expect($registered[0])->toBe('pollora/v1')
            ->and($registered[1])->toBe('/block-bindings/resolve')
            ->and(array_keys($registered[2]))->toBe(['methods', 'callback', 'permission_callback', 'args'])
            ->and($registered[2]['args'])->toBe([
                'context' => ['type' => 'object', 'default' => []],
                'bindings' => ['type' => 'array', 'required' => true, 'maxItems' => 200],
            ]);
    });

    it('hands the resolver the post, the term, their types and the block context, cleaned', function (): void {
        ($this->bind)(['postId' => '7', 'postType' => 'Event!', 'termId' => '3', 'taxonomy' => 'Genre', 'other' => 'x']);

        $context = $this->invoker->contexts[0];

        expect($context->postId)->toBe(7)
            ->and($context->postType)->toBe('event')
            ->and($context->termId)->toBe(3)
            ->and($context->taxonomy)->toBe('genre');
    });

    it('leaves out an empty type and an empty taxonomy', function (): void {
        ($this->bind)(['postType' => '', 'taxonomy' => '']);

        $context = $this->invoker->contexts[0];

        expect($context->postType)->toBeNull()
            ->and($context->taxonomy)->toBeNull();
    });

    it('takes the context of an object as well as of an array', function (): void {
        ($this->bind)((object) ['postId' => 7, 'taxonomy' => 'genre']);

        expect($this->invoker->contexts[0]->postId)->toBe(7)
            ->and($this->invoker->contexts[0]->taxonomy)->toBe('genre');
    });

    it('lets edit a post whose id is one, but only edit posts for zero or a fraction', function (mixed $postId, array $expected): void {
        $seen = [];
        Functions\when('current_user_can')->alias(function (string $capability, mixed ...$args) use (&$seen): bool {
            $seen[] = [$capability, $args];

            return true;
        });

        $this->controller->canPreview(($this->request)(['context' => ['postId' => $postId]]));

        expect($seen)->toBe([$expected]);
    })->with([
        'one' => [1, ['edit_post', [1]]],
        'a numeric string' => ['7', ['edit_post', [7]]],
        'zero' => [0, ['edit_posts', []]],
        'a fraction under one' => ['0.5', ['edit_posts', []]],
    ]);

    it('answers an empty list for bindings that are not a list', function (mixed $bindings): void {
        $warnings = [];
        set_error_handler(function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $response = $this->controller->handle(($this->request)(['bindings' => $bindings]));
        } finally {
            restore_error_handler();
        }

        expect($response)->toBe(['values' => []])
            ->and($warnings)->toBe([]);
    })->with(['nothing' => [null], 'a string' => ['x'], 'empty' => [[]]]);

    it('keys the values by the text of the key', function (): void {
        $response = $this->controller->handle(($this->request)(['bindings' => [['key' => 1.5, 'source' => 'acme/echo'], ['key' => true, 'source' => 'acme/echo']]]));

        expect(array_keys($response['values']))->toBe(['1.5', 1]);
    });

    it('asks the registry of block types without a warning when it does not know the block', function (): void {
        $warnings = [];
        set_error_handler(function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $this->controller->handle(($this->request)(['bindings' => [['key' => 'a', 'source' => 'acme/echo', 'block' => 'acme/ghost']]]));
        } finally {
            restore_error_handler();
        }

        expect($warnings)->toBe([]);
    });
});

describe('building a source', function (): void {
    it('refuses an invokable source without a declared return type', function (): void {
        expect(fn () => (new BindingSourceBuilder)->build(UntypedInvokable::class))
            ->toThrow(InvalidBindingSourceException::class, 'UntypedInvokable::__invoke() cannot be registered: declare its return type');
    });

    it('refuses an intersection return type, naming it', function (): void {
        expect(fn () => (new BindingSourceBuilder)->build(IntersectionField::class))
            ->toThrow(InvalidBindingSourceException::class, 'it returns Stringable&Countable; a field returns string, int, float, bool, Stringable or null.');
    });

    it('refuses a union with a type a field may not return', function (): void {
        expect(fn () => (new BindingSourceBuilder)->build(UnionField::class))
            ->toThrow(InvalidBindingSourceException::class, 'it returns array|string');
    });

    it('goes past the methods that are not fields', function (): void {
        $source = (new BindingSourceBuilder)->build(HelperFirst::class);

        expect(array_keys($source->fields))->toBe(['title']);
    });
});

describe('discovering sources', function (): void {
    beforeEach(function (): void {
        $this->discovery = new BlockBindingDiscovery(
            new BindingSourceBuilder,
            new BindingSourceRegistry,
            new WordPressBindingRegistry(Mockery::mock(Action::class), new BindingResolver(new SimpleContainer, new FakeVisibility, new FakePresenter)),
        );
        $this->location = new DiscoveryLocation('Tests\\', __DIR__);
    });

    it('skips an abstract class and an interface even when they carry the attribute', function (): void {
        $this->discovery->discover($this->location, DiscoveredClass::fromReflection(new ReflectionClass(EdgeAbstractMarkedBinding::class)));
        $this->discovery->discover($this->location, DiscoveredInterface::fromReflection(new ReflectionClass(EdgeInterfaceMarkedBinding::class)));
        $this->discovery->discover($this->location, DiscoveredClass::fromReflection(new ReflectionClass(InvokableBinding::class)));

        expect(array_column(iterator_to_array($this->discovery->getItems()), 'class'))->toBe([InvokableBinding::class]);
    });

    it('carries on without a logger when a source cannot be built', function (): void {
        $this->discovery->discover($this->location, DiscoveredClass::fromReflection(new ReflectionClass(NoField::class)));

        $this->discovery->apply();

        expect(iterator_to_array($this->discovery->getItems()))->toHaveCount(1);
    });

    it('hands the logger the exception of a source it cannot build', function (): void {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once()->with(Mockery::type('string'), Mockery::on(fn (array $context): bool => array_keys($context) === ['exception'] && $context['exception'] instanceof InvalidBindingSourceException));
        $discovery = new BlockBindingDiscovery(new BindingSourceBuilder, new BindingSourceRegistry, new WordPressBindingRegistry(Mockery::mock(Action::class), new BindingResolver(new SimpleContainer, new FakeVisibility, new FakePresenter)), $logger);
        $discovery->discover($this->location, DiscoveredClass::fromReflection(new ReflectionClass(NoField::class)));

        $discovery->apply();
    });
});

describe('the WordPress adapters', function (): void {
    it('treats a taxonomy WordPress does not know as private, without a warning', function (): void {
        Functions\when('get_term')->justReturn(new WP_Term);
        Functions\when('get_taxonomy')->justReturn(false);
        Functions\when('current_user_can')->justReturn(true);
        $warnings = [];
        set_error_handler(function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $shown = (new WordPressContentVisibility)->canShowTerm(3, 'ghost');
        } finally {
            restore_error_handler();
        }

        expect($shown)->toBeTrue()
            ->and($warnings)->toBe([]);
    });

    it('registers its editor script in the footer and ends the data statement', function (): void {
        $calls = [];
        Functions\when('wp_json_encode')->alias(fn (mixed $data, int $flags = 0): string => json_encode($data, $flags));
        Functions\when('wp_register_script')->alias(function (string $handle, mixed $src, array $deps, mixed $version, mixed $inFooter) use (&$calls): bool {
            $calls['register'] = [$version, $inFooter];

            return true;
        });
        Functions\when('wp_add_inline_script')->alias(function (string $handle, string $script, string $position = 'after') use (&$calls): bool {
            $calls[$position] = $script;

            return true;
        });
        Functions\when('wp_enqueue_script')->justReturn(null);

        (new WordPressBindingEditorScript(new BindingEditorData(new BindingSourceRegistry, new MetaSchemaRepository, [])))->enqueue();

        expect($calls['register'])->toBe([null, true])
            ->and($calls['before'])->toEndWith('};')
            ->and(substr_count($calls['before'], ';'))->toBe(1);
    });
});

describe('the binding context', function (): void {
    it('reads the author of a post whose author is given as text, or is the first user', function (mixed $author, ?string $expected): void {
        $store = new ArrayMetaStore(['user' => [1 => ['stage_name' => 'The First'], 5 => ['stage_name' => 'The Hums']]]);
        $schemas = new MetaSchemaRepository;
        $schemas->add((new MetaSchemaBuilder)->build(ArtistProfile::class));
        $accessor = new MetaAccessor($schemas, new MetaSchemaBuilder, $store, new MetaValueCaster);
        $post = new Post;
        $post->post_author = $author;
        $finder = new class($post) implements PostFinderInterface
        {
            public function __construct(private readonly Post $post) {}

            public function find(int $postId): ?Post
            {
                return $this->post;
            }
        };
        $context = new BindingContext(postId: 7, postFinder: $finder, metaAccessor: $accessor);

        expect($context->meta(ArtistProfile::class)?->stageName)->toBe($expected);
    })->with([
        'text' => ['5', 'The Hums'],
        'the first user' => [1, 'The First'],
        'the first user, as text' => ['1', 'The First'],
        'none' => [0, null],
    ]);
});
