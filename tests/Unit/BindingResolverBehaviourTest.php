<?php

declare(strict_types=1);

use Pollora\BlockBinding\Application\Services\BindingResolver;
use Pollora\BlockBinding\Application\Services\BindingSourceBuilder;
use Pollora\BlockBinding\Application\Services\DefaultMethodInvoker;
use Pollora\BlockBinding\Domain\Contracts\MethodInvokerInterface;
use Pollora\BlockBinding\Domain\Enums\BindingFieldType;
use Pollora\BlockBinding\Domain\Models\BindingContext;
use Pollora\BlockBinding\Domain\Models\BindingFieldDefinition;
use Pollora\BlockBinding\Domain\Models\BindingSource;
use Pollora\BlockBinding\Tests\Fixtures\EventBinding;
use Pollora\BlockBinding\Tests\Fixtures\FakePresenter;
use Pollora\BlockBinding\Tests\Fixtures\FakeVisibility;
use Pollora\BlockBinding\Tests\Fixtures\InvokableBinding;
use Pollora\BlockBinding\Tests\Fixtures\RecordingEvents;
use Pollora\BlockBinding\Tests\Fixtures\SimpleContainer;
use Pollora\BlockBinding\Tests\Fixtures\SlowBinding;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__).'/Fixtures/blocks.php';

beforeEach(function (): void {
    $this->visibility = new FakeVisibility;
    $this->event = (new BindingSourceBuilder)->build(EventBinding::class);
    $this->echo = (new BindingSourceBuilder)->build(InvokableBinding::class);

    // An invoker that gives back what the test hands it and keeps what it was asked.
    $this->invoker = new class implements MethodInvokerInterface
    {
        /** @var list<array{object, string, array<string, mixed>}> */
        public array $calls = [];

        public mixed $value = 'given';

        public bool $counting = false;

        public function call(object $instance, string $method, array $parameters): mixed
        {
            $this->calls[] = [$instance, $method, $parameters];

            return $this->counting ? count($this->calls) : $this->value;
        }
    };

    $this->make = fn (bool $debug = false, ?LoggerInterface $logger = null, ?MethodInvokerInterface $invoker = null, ?RecordingEvents $events = null, ?ContainerInterface $container = null): BindingResolver => new BindingResolver(
        $container ?? new SimpleContainer, $this->visibility, new FakePresenter, null, $debug, $logger, $invoker ?? $this->invoker, $events,
    );
});

it('calls the invoker it is given with the context under its class name and under "context"', function (bool $debug): void {
    $resolver = ($this->make)($debug);

    $resolver->resolve($this->event, ['field' => 'sold_out'], boundParagraph(), 'content');

    [$instance, $method, $parameters] = $this->invoker->calls[0];

    expect($this->invoker->calls)->toHaveCount(1)
        ->and($instance)->toBeInstanceOf(EventBinding::class)
        ->and($method)->toBe('soldOut')
        ->and(array_keys($parameters))->toBe([BindingContext::class, 'context'])
        ->and($parameters['context'])->toBeInstanceOf(BindingContext::class)
        ->and($parameters[BindingContext::class])->toBe($parameters['context'])
        ->and($parameters['context']->postId)->toBe(7)
        ->and($parameters['context']->attribute)->toBe('content');
})->with([false, true]);

it('falls back on its own invoker when none is given', function (): void {
    $resolver = new BindingResolver(new SimpleContainer, $this->visibility, new FakePresenter);

    expect($resolver->resolve($this->event, ['field' => 'capacity'], boundParagraph(), 'content'))->toBe('120');
});

it('resolves without a block, from an empty context', function (): void {
    $resolver = new BindingResolver(new SimpleContainer, $this->visibility, new FakePresenter);

    expect($resolver->resolve($this->echo, ['say' => 'hi'], null, 'content'))->toBe('hi');
});

it('resolves a block whose type is unknown', function (): void {
    $block = boundParagraph();
    $block->block_type = null;

    expect(($this->make)()->resolve($this->echo, ['say' => 'hi'], $block, 'content'))->toBe('given');
});

it('names the invokable field with an empty name when it announces it', function (): void {
    $events = new RecordingEvents;

    ($this->make)(false, null, null, $events)->resolve($this->echo, ['say' => 'hi'], boundParagraph(), 'content');

    expect($events->announced[0]->field)->toBe('')
        ->and($this->invoker->calls[0][1])->toBe('__invoke');
});

it('says on a cached answer whether it holds a value', function (mixed $value, bool $hasValue): void {
    $this->invoker->value = $value;
    $events = new RecordingEvents;
    $resolver = ($this->make)(false, null, null, $events);

    $resolver->resolve($this->event, ['field' => 'sold_out'], boundParagraph(), 'content');
    $resolver->resolve($this->event, ['field' => 'sold_out'], boundParagraph(), 'content');

    expect($events->announced[0]->hasValue)->toBe($hasValue)
        ->and($events->announced[1]->cached)->toBeTrue()
        ->and($events->announced[1]->hasValue)->toBe($hasValue);
})->with([
    'a value' => ['text', true],
    'null' => [null, false],
]);

it('announces the time a field took, in milliseconds', function (): void {
    $events = new RecordingEvents;
    $slow = (new BindingSourceBuilder)->build(SlowBinding::class);

    ($this->make)(false, null, new DefaultMethodInvoker, $events)->resolve($slow, ['field' => 'late'], boundParagraph(), 'content');

    expect($events->announced[0]->milliseconds)->toBeGreaterThanOrEqual(59.0)->toBeLessThan(2000.0);
});

it('shows a term whose taxonomy is unknown, and a taxonomy without term, whatever the term visibility', function (array $context): void {
    $this->visibility->hiddenTerms = [3];

    expect(($this->make)()->resolve($this->echo, ['say' => 'hi'], boundBlock($context), 'content'))->toBe('given');
})->with([
    'term without taxonomy' => [['termId' => 3]],
    'taxonomy without term' => [['taxonomy' => 'genre']],
]);

it('asks the container for a source once, however many fields it answers', function (): void {
    $container = new class implements ContainerInterface
    {
        public int $gets = 0;

        public function get(string $id): mixed
        {
            $this->gets++;

            return new $id;
        }

        public function has(string $id): bool
        {
            return true;
        }
    };
    $resolver = ($this->make)(false, null, null, null, $container);

    $resolver->resolve($this->event, ['field' => 'sold_out'], boundParagraph(), 'content');
    $resolver->resolve($this->event, ['field' => 'capacity'], boundParagraph(), 'content');
    $resolver->resolve($this->event, ['field' => 'capacity'], boundParagraph(['postId' => 8, 'postType' => 'event']), 'content');

    expect($container->gets)->toBe(1)
        ->and($this->invoker->calls)->toHaveCount(3);
});

it('logs nothing, and keeps the block content, when a failing field has no logger to tell', function (): void {
    $resolver = new BindingResolver(new SimpleContainer, $this->visibility, new FakePresenter);

    expect($resolver->resolve($this->event, ['field' => 'broken'], boundParagraph(), 'content'))->toBeNull();
});

it('hands the exception to the logger', function (): void {
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('error')->once()->with(
        'The block binding "acme/echo" failed on no post: Boom.',
        Mockery::on(fn (array $context): bool => array_keys($context) === ['exception'] && $context['exception'] instanceof RuntimeException && $context['exception']->getMessage() === 'Boom.'),
    );
    $invoker = new class implements MethodInvokerInterface
    {
        public function call(object $instance, string $method, array $parameters): mixed
        {
            throw new RuntimeException('Boom.');
        }
    };

    expect(($this->make)(false, $logger, $invoker)->resolve($this->echo, ['say' => 'hi'], boundBlock(), 'content'))->toBeNull();
});

it('times a slow field in debug mode without a logger to tell', function (): void {
    $slow = (new BindingSourceBuilder)->build(SlowBinding::class);
    $resolver = new BindingResolver(new SimpleContainer, $this->visibility, new FakePresenter, null, true);

    expect($resolver->resolve($slow, ['field' => 'late'], boundParagraph(), 'content'))->toBe('late');
});

describe('reporting a slow field', function (): void {
    beforeEach(function (): void {
        $this->report = function (BindingResolver $resolver, BindingSource $source, BindingFieldDefinition $field, ?int $postId, float $milliseconds): void {
            $method = new ReflectionMethod($resolver, 'reportSlow');
            $method->invoke($resolver, $source, $field, new BindingContext(postId: $postId), $milliseconds);
        };
        $this->logger = Mockery::mock(LoggerInterface::class);
    });

    it('logs only above 50 ms', function (float $milliseconds, bool $logged): void {
        $this->logger->shouldReceive('warning')->times($logged ? 1 : 0);

        ($this->report)(($this->make)(true, $this->logger), $this->event, $this->event->field('capacity'), 7, $milliseconds);
    })->with([
        'at the limit' => [50.0, false],
        'just above' => [50.1, true],
        'below' => [49.9, false],
    ]);

    it('words the field, the rounded time and the post', function (): void {
        $this->logger->shouldReceive('warning')->once()->with(
            'The block binding "acme/event" (field "capacity") took 121 ms on post 7',
            ['source' => 'acme/event', 'field' => 'capacity', 'post' => 7, 'milliseconds' => 120.6],
        );

        ($this->report)(($this->make)(true, $this->logger), $this->event, $this->event->field('capacity'), 7, 120.55);
    });

    it('rounds the time to the nearest millisecond, down as well as up', function (float $milliseconds, string $expected): void {
        $this->logger->shouldReceive('warning')->once()->with($expected, Mockery::type('array'));

        ($this->report)(($this->make)(true, $this->logger), $this->event, $this->event->field('capacity'), 7, $milliseconds);
    })->with([
        'down' => [120.4, 'The block binding "acme/event" (field "capacity") took 120 ms on post 7'],
        'up' => [120.6, 'The block binding "acme/event" (field "capacity") took 121 ms on post 7'],
    ]);

    it('leaves out the field of an invokable source and says when there is no post', function (): void {
        $this->logger->shouldReceive('warning')->once()->with(
            'The block binding "acme/echo" took 80 ms on no post',
            ['source' => 'acme/echo', 'field' => '', 'post' => null, 'milliseconds' => 80.0],
        );
        $field = new BindingFieldDefinition('', '', BindingFieldType::Text, '__invoke');

        ($this->report)(($this->make)(true, $this->logger), $this->echo, $field, null, 80.0);
    });

    it('does not need a logger', function (): void {
        $field = new BindingFieldDefinition('', '', BindingFieldType::Text, '__invoke');

        ($this->report)(($this->make)(true), $this->echo, $field, null, 80.0);

        expect(true)->toBeTrue();
    });
});

describe('presenting what a source gives', function (): void {
    it('filters a toHtml() value, whatever it returns, as post content', function (): void {
        $this->invoker->value = new class
        {
            public function toHtml(): int
            {
                return 5;
            }
        };

        expect(($this->make)()->resolve($this->event, ['field' => 'sold_out'], boundParagraph(), 'content'))->toBe('kses(5)');
    });

    it('turns a Stringable into its text, and anything else without a text into an empty string', function (mixed $value, string $expected): void {
        $this->invoker->value = $value;

        expect(($this->make)()->resolve($this->event, ['field' => 'sold_out'], boundParagraph(), 'content'))->toBe($expected);
    })->with([
        'a Stringable' => [new class implements Stringable
        {
            public function __toString(): string
            {
                return 'a & b';
            }
        }, 'a &amp; b'],
        'an array' => [['x'], ''],
        'an object' => [new stdClass, ''],
    ]);

    it('leaves a Stringable unescaped outside HTML, and an array as nothing', function (): void {
        $this->invoker->value = new class implements Stringable
        {
            public function __toString(): string
            {
                return 'a & b';
            }
        };
        $plain = boundBlock(['postId' => 7, 'postType' => 'event']);

        expect(($this->make)()->resolve($this->event, ['field' => 'sold_out'], $plain, 'title'))->toBe('a & b');
    });
});

describe('remembering a value for the request', function (): void {
    it('calls the field again for a source, an attribute, a post, a term, a block, an argument or an attribute source that differs', function (string $what): void {
        $this->invoker->counting = true;
        $resolver = ($this->make)();
        $block = fn (array $context = ['postId' => 7, 'postType' => 'event', 'termId' => 3, 'taxonomy' => 'genre'], string $name = 'core/paragraph'): WP_Block => boundBlock($context, ['content' => ['source' => 'rich-text'], 'title' => ['type' => 'string']], $name);
        $source = $this->echo;

        $first = $resolver->resolve($source, ['say' => 'a'], $block(), 'content');
        $second = match ($what) {
            'source' => $resolver->resolve(new BindingSource('acme/other', 'Other', $source->class, [], [], []), ['say' => 'a'], $block(), 'content'),
            'attribute' => $resolver->resolve($source, ['say' => 'a'], $block(), 'title'),
            'post' => $resolver->resolve($source, ['say' => 'a'], $block(['postId' => 8, 'postType' => 'event', 'termId' => 3, 'taxonomy' => 'genre']), 'content'),
            'term' => $resolver->resolve($source, ['say' => 'a'], $block(['postId' => 7, 'postType' => 'event', 'termId' => 4, 'taxonomy' => 'genre']), 'content'),
            'block' => $resolver->resolve($source, ['say' => 'a'], $block(name: 'core/heading'), 'content'),
            'arguments' => $resolver->resolve($source, ['say' => 'b'], $block(), 'content'),
        };

        expect($this->invoker->calls)->toHaveCount(2)
            ->and((string) $first)->toBe('1')
            ->and((string) $second)->toBe('2');
    })->with(['source', 'attribute', 'post', 'term', 'block', 'arguments']);

    it('calls the field again when only the source of the attribute differs', function (): void {
        $this->invoker->counting = true;
        $resolver = ($this->make)();
        $context = ['postId' => 7, 'postType' => 'event'];

        $first = $resolver->preview($this->echo, ['say' => 'a'], $context, 'content', 'rich-text');
        $second = $resolver->preview($this->echo, ['say' => 'a'], $context, 'content', 'attribute');
        $third = $resolver->preview($this->echo, ['say' => 'a'], $context, 'content', null);

        expect(array_map(strval(...), [$first, $second, $third]))->toBe(['1', '2', '3']);
    });

    it('answers a block-less preview without a warning', function (): void {
        $warnings = [];
        set_error_handler(function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            ($this->make)()->preview($this->echo, ['say' => 'a'], ['postId' => 7, 'postType' => 'event'], 'content');
        } finally {
            restore_error_handler();
        }

        expect($warnings)->toBe([]);
    });
});

describe('reading ids from the block context', function (): void {
    it('keeps a positive number as an integer, and drops anything else', function (mixed $sent, ?int $expected): void {
        ($this->make)()->preview($this->echo, [], ['postId' => $sent, 'termId' => $sent], 'content');

        $context = $this->invoker->calls[0][2]['context'] ?? null;

        // A source hidden from nobody is called only for visible posts: the fake sees every id.
        expect($context?->postId)->toBe($expected)
            ->and($context?->termId)->toBe($expected);
    })->with([
        'integer' => [7, 7],
        'one' => [1, 1],
        'numeric string' => ['7', 7],
        'float' => [7.9, 7],
        'zero' => [0, null],
        'zero string' => ['0', null],
        'negative' => [-1, null],
        'not a number' => ['abc', null],
        'null' => [null, null],
    ]);
});
