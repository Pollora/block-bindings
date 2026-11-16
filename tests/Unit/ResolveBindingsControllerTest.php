<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\BlockBinding\Application\Services\BindingResolver;
use Pollora\BlockBinding\Application\Services\BindingSourceBuilder;
use Pollora\BlockBinding\Application\Services\BindingSourceRegistry;
use Pollora\BlockBinding\Tests\Fixtures\EventBinding;
use Pollora\BlockBinding\Tests\Fixtures\FakePresenter;
use Pollora\BlockBinding\Tests\Fixtures\FakeVisibility;
use Pollora\BlockBinding\Tests\Fixtures\SimpleContainer;
use Pollora\BlockBinding\UI\Http\ResolveBindingsController;

/**
 * @param  array<string, mixed>  $params
 */
function previewRequest(array $params): WP_REST_Request
{
    $request = Mockery::mock(WP_REST_Request::class);
    $request->shouldReceive('get_param')->andReturnUsing(fn (string $name): mixed => $params[$name] ?? null);

    return $request;
}

beforeEach(function (): void {
    Functions\when('get_locale')->justReturn('fr_FR');
    $this->locales = [];
    Functions\when('switch_to_locale')->alias(function (string $locale): bool {
        $this->locales[] = 'switch:'.$locale;

        return true;
    });
    Functions\when('restore_previous_locale')->alias(function (): void {
        $this->locales[] = 'restore';
    });
    Functions\when('sanitize_key')->alias(fn (string $key): string => strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', $key)));

    $sources = new BindingSourceRegistry;
    $sources->add((new BindingSourceBuilder)->build(EventBinding::class));

    $this->visibility = new FakeVisibility;
    $this->controller = new ResolveBindingsController($sources, new BindingResolver(new SimpleContainer, $this->visibility, new FakePresenter));
});

it('answers the values of the bindings sent, by key', function (): void {
    $response = $this->controller->handle(previewRequest([
        'context' => ['postId' => '7', 'postType' => 'event', 'injected' => 'x'],
        'bindings' => [
            ['key' => 'a', 'source' => 'acme/event', 'args' => ['field' => 'remaining_seats'], 'attribute' => 'content'],
            ['key' => 'b', 'source' => 'acme/event', 'args' => ['field' => 'booking_url'], 'attribute' => 'url'],
            ['key' => 'c', 'source' => 'core/post-meta', 'args' => ['key' => 'secret']],
            ['key' => 'd', 'source' => 'acme/event', 'args' => ['field' => 'sold_out']],
            ['source' => 'acme/event'],
        ],
    ]));

    expect($response['values'])->toBe([
        'a' => '14 seats & more',
        'b' => 'url(https://example.test/book/7)',
        'c' => null,
        'd' => 'Yes',
    ]);
});

it('shows nothing of a post the editor may not read', function (): void {
    $this->visibility->hiddenPosts = [7];

    $response = $this->controller->handle(previewRequest([
        'context' => ['postId' => 7, 'postType' => 'event'],
        'bindings' => [['key' => 'a', 'source' => 'acme/event', 'args' => ['field' => 'capacity']]],
    ]));

    expect($response['values'])->toBe(['a' => null]);
});

it('lets preview only who can edit the post, or edit posts without one', function (): void {
    Functions\when('current_user_can')->alias(fn (string $capability, mixed ...$args): bool => $capability === 'edit_post' && $args === [7]);

    expect($this->controller->canPreview(previewRequest(['context' => ['postId' => 7]])))->toBeTrue()
        ->and($this->controller->canPreview(previewRequest(['context' => ['postId' => 8]])))->toBeFalse()
        ->and($this->controller->canPreview(previewRequest(['context' => []])))->toBeFalse();
});

it('formats the values in the site language, then gives the user theirs back', function (): void {
    $this->controller->handle(previewRequest(['bindings' => []]));

    expect($this->locales)->toBe(['switch:fr_FR', 'restore']);
});

it('does not restore a locale it could not switch', function (): void {
    Functions\when('switch_to_locale')->justReturn(false);

    $this->controller->handle(previewRequest(['bindings' => []]));

    expect($this->locales)->toBe([]);
});

it('registers a POST route guarded by the preview permission', function (): void {
    $routes = [];
    Functions\when('register_rest_route')->alias(function (string $namespace, string $route, array $args) use (&$routes): bool {
        $routes[] = [$namespace, $route, $args];

        return true;
    });

    $this->controller->register();

    expect($routes)->toHaveCount(1)
        ->and($routes[0][0])->toBe('pollora/v1')
        ->and($routes[0][2]['methods'])->toBe('POST')
        ->and($routes[0][2]['callback'])->toBeCallable()
        ->and($routes[0][2]['permission_callback'])->toBeCallable()
        ->and($routes[0][2]['args']['bindings'])->toMatchArray(['required' => true, 'maxItems' => ResolveBindingsController::MAX_BINDINGS]);
});

it('ignores what is not a binding, and answers null for a source it does not know', function (): void {
    $response = $this->controller->handle(previewRequest([
        'context' => ['postId' => 7, 'postType' => 'event'],
        'bindings' => [
            'not an array',
            ['key' => ['nested'], 'source' => 'acme/event'],
            ['key' => 'ghost', 'source' => 'acme/unknown'],
            ['key' => 'untyped', 'source' => 42],
        ],
    ]));

    expect($response['values'])->toBe(['ghost' => null, 'untyped' => null]);
});

it('previews with the edit_posts capability when the post id sent is not a positive number', function (): void {
    $seen = [];
    Functions\when('current_user_can')->alias(function (string $capability, mixed ...$args) use (&$seen): bool {
        $seen[] = [$capability, $args];

        return true;
    });

    $request = previewRequest(['context' => ['postId' => '-3', 'termId' => 'abc', 'postType' => '', 'taxonomy' => ['x']]]);

    expect($this->controller->canPreview($request))->toBeTrue()
        ->and($seen)->toBe([['edit_posts', []]]);
});

it('decides the escaping of a binding by the source of the block attribute in the registry', function (): void {
    $type = new WP_Block_Type;
    $type->attributes = ['content' => ['source' => 'rich-text'], 'url' => ['type' => 'string']];
    WP_Block_Type_Registry::get_instance()->types['core/paragraph'] = $type;

    try {
        $response = $this->controller->handle(previewRequest([
            'context' => ['postId' => 7, 'postType' => 'event'],
            'bindings' => [
                ['key' => 'html', 'source' => 'acme/event', 'args' => ['field' => 'remaining_seats'], 'attribute' => 'content', 'block' => 'core/paragraph'],
                ['key' => 'plain', 'source' => 'acme/event', 'args' => ['field' => 'remaining_seats'], 'attribute' => 'url', 'block' => 'core/paragraph'],
                ['key' => 'unknown', 'source' => 'acme/event', 'args' => ['field' => 'remaining_seats'], 'attribute' => 'content', 'block' => 'acme/ghost'],
            ],
        ]));
    } finally {
        unset(WP_Block_Type_Registry::get_instance()->types['core/paragraph']);
    }

    expect($response['values'])->toBe([
        'html' => '14 seats &amp; more',
        'plain' => '14 seats & more',
        'unknown' => '14 seats & more',
    ]);
});
