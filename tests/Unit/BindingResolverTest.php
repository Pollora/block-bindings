<?php

declare(strict_types=1);

use Pollora\BlockBinding\Application\Services\BindingResolver;
use Pollora\BlockBinding\Application\Services\BindingSourceBuilder;
use Pollora\BlockBinding\Tests\Fixtures\EventBinding;
use Pollora\BlockBinding\Tests\Fixtures\FakePresenter;
use Pollora\BlockBinding\Tests\Fixtures\FakeVisibility;
use Pollora\BlockBinding\Tests\Fixtures\InvokableBinding;
use Pollora\BlockBinding\Tests\Fixtures\RecordingEvents;
use Pollora\BlockBinding\Tests\Fixtures\SimpleContainer;
use Pollora\BlockBinding\Tests\Fixtures\SlowBinding;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__).'/Fixtures/blocks.php';

beforeEach(function (): void {
    $this->container = new SimpleContainer;
    $this->visibility = new FakeVisibility;
    $this->logger = Mockery::mock(LoggerInterface::class);
    $this->resolver = fn (bool $debug = false): BindingResolver => new BindingResolver($this->container, $this->visibility, new FakePresenter, null, $debug, $this->logger, null, $this->events ?? null);
    $this->event = (new BindingSourceBuilder)->build(EventBinding::class);
});

it('calls the field through the container and escapes text written as HTML', function (): void {
    $value = ($this->resolver)()->resolve($this->event, ['field' => 'remaining_seats'], boundParagraph(), 'content');

    expect($value)->toBe('14 seats &amp; more');
});

it('leaves an attribute a template prints for the template to escape', function (): void {
    $block = boundBlock(['postId' => 7, 'postType' => 'event'], ['title' => ['type' => 'string']], 'acme/card');

    expect(($this->resolver)()->resolve($this->event, ['field' => 'remaining_seats'], $block, 'title'))->toBe('14 seats & more')
        ->and(($this->resolver)()->resolve($this->event, ['field' => 'capacity'], $block, 'title'))->toBe(120)
        ->and(($this->resolver)()->resolve($this->event, ['field' => 'sold_out'], $block, 'title'))->toBeTrue();
});

it('sanitizes a url field wherever it lands', function (): void {
    $block = boundBlock(['postId' => 7, 'postType' => 'event'], ['url' => ['type' => 'string', 'source' => 'attribute', 'selector' => 'a', 'attribute' => 'href']], 'core/button');

    expect(($this->resolver)()->resolve($this->event, ['field' => 'booking_url'], $block, 'url'))->toBe('url(https://example.test/book/7)');
});

it('words a boolean and filters an HtmlString when written as HTML', function (): void {
    expect(($this->resolver)()->resolve($this->event, ['field' => 'sold_out'], boundParagraph(), 'content'))->toBe('Yes')
        ->and(($this->resolver)()->resolve($this->event, ['field' => 'summary'], boundParagraph(), 'content'))->toBe('kses(<strong>Live</strong>)')
        ->and(($this->resolver)()->resolve($this->event, ['field' => 'capacity'], boundParagraph(), 'content'))->toBe('120');
});

it('keeps the block content for null, an unknown field or no field', function (array $args): void {
    expect(($this->resolver)()->resolve($this->event, $args, boundParagraph(), 'content'))->toBeNull();
})->with([
    'null' => [['field' => 'nothing']],
    'unknown field' => [['field' => 'missing']],
    'no field' => [[]],
]);

it('shows nothing of a post the visitor cannot see', function (): void {
    $this->visibility->hiddenPosts = [7];

    expect(($this->resolver)()->resolve($this->event, ['field' => 'remaining_seats'], boundParagraph(), 'content'))->toBeNull();
});

it('shows nothing of a term the visitor cannot see', function (): void {
    $this->visibility->hiddenTerms = [3];
    $source = (new BindingSourceBuilder)->build(InvokableBinding::class);

    expect(($this->resolver)()->resolve($source, ['say' => 'hi'], boundParagraph(['termId' => 3, 'taxonomy' => 'genre']), 'content'))->toBeNull()
        ->and(($this->resolver)()->resolve($source, ['say' => 'hi'], boundParagraph(['termId' => 4, 'taxonomy' => 'genre']), 'content'))->toBe('hi');
});

it('answers only for the post types the source names', function (): void {
    expect(($this->resolver)()->resolve($this->event, ['field' => 'capacity'], boundParagraph(['postId' => 7, 'postType' => 'page']), 'content'))->toBeNull();
});

it('calls a field once per post, attribute and arguments in a request', function (): void {
    $resolver = ($this->resolver)();
    $instance = new EventBinding;
    $this->container->instance(EventBinding::class, $instance);

    $resolver->resolve($this->event, ['field' => 'remaining_seats'], boundParagraph(), 'content');
    $resolver->resolve($this->event, ['field' => 'remaining_seats'], boundParagraph(), 'content');
    $resolver->resolve($this->event, ['field' => 'remaining_seats'], boundParagraph(['postId' => 8, 'postType' => 'event']), 'content');

    expect($instance->calls)->toBe(2);
});

it('logs a field that throws and keeps the block content', function (): void {
    $this->logger->shouldReceive('error')->once()->with('The block binding "acme/event" failed on post 7: No database.', Mockery::type('array'));

    expect(($this->resolver)()->resolve($this->event, ['field' => 'broken'], boundParagraph(), 'content'))->toBeNull();
});

it('throws a failing field in debug mode', function (): void {
    expect(fn () => ($this->resolver)(true)->resolve($this->event, ['field' => 'broken'], boundParagraph(), 'content'))
        ->toThrow(RuntimeException::class, 'No database.');
});

it('logs a field slower than 50 ms in debug mode, with its source and post', function (): void {
    $source = (new BindingSourceBuilder)->build(SlowBinding::class);
    $this->logger->shouldReceive('warning')->once()->with(
        Mockery::pattern('/^The block binding "acme\/slow" \(field "late"\) took \d+ ms on post 7$/'),
        Mockery::on(fn (array $context): bool => $context['source'] === 'acme/slow' && $context['field'] === 'late' && $context['post'] === 7 && $context['milliseconds'] > 50),
    );

    expect(($this->resolver)(true)->resolve($source, ['field' => 'late'], boundParagraph(), 'content'))->toBe('late');
});

it('does not time a field outside debug mode, nor log a fast one', function (): void {
    $source = (new BindingSourceBuilder)->build(SlowBinding::class);
    $this->logger->shouldNotReceive('warning');

    expect(($this->resolver)()->resolve($source, ['field' => 'late'], boundParagraph(), 'content'))->toBe('late')
        ->and(($this->resolver)(true)->resolve($source, ['field' => 'quick'], boundParagraph(), 'content'))->toBe('quick');
});

it('hands an invokable source every call', function (): void {
    $source = (new BindingSourceBuilder)->build(InvokableBinding::class);

    expect(($this->resolver)()->resolve($source, ['say' => '<b>'], boundParagraph(), 'content'))->toBe('&lt;b&gt;');
});

it('previews a value from the context the editor sends, escaped like the attribute on the page', function (): void {
    $resolver = ($this->resolver)();
    $context = ['postId' => 7, 'postType' => 'event'];

    expect($resolver->preview($this->event, ['field' => 'remaining_seats'], $context, 'content', 'rich-text'))->toBe('14 seats &amp; more')
        ->and($resolver->preview($this->event, ['field' => 'remaining_seats'], $context, 'title'))->toBe('14 seats & more')
        ->and($resolver->preview($this->event, ['field' => 'sold_out'], $context, 'title'))->toBe('Yes');
});

describe('announcing resolutions', function (): void {
    beforeEach(function (): void {
        $this->events = new RecordingEvents;
    });

    it('announces each resolution, and says when the request cache answered', function (): void {
        $resolver = ($this->resolver)();

        $resolver->resolve($this->event, ['field' => 'remaining_seats'], boundParagraph(), 'content');
        $resolver->resolve($this->event, ['field' => 'remaining_seats'], boundParagraph(), 'content');

        expect($this->events->announced)->toHaveCount(2)
            ->and($this->events->announced[0]->source)->toBe('acme/event')
            ->and($this->events->announced[0]->field)->toBe('remaining_seats')
            ->and($this->events->announced[0]->attribute)->toBe('content')
            ->and($this->events->announced[0]->postId)->toBe(7)
            ->and($this->events->announced[0]->cached)->toBeFalse()
            ->and($this->events->announced[0]->hasValue)->toBeTrue()
            ->and($this->events->announced[1]->cached)->toBeTrue()
            ->and($this->events->announced[1]->milliseconds)->toBe(0.0);
    });

    it('says when a source left the block its own content', function (): void {
        ($this->resolver)()->resolve($this->event, ['field' => 'nothing'], boundParagraph(), 'content');

        expect($this->events->announced[0]->hasValue)->toBeFalse();
    });
});
