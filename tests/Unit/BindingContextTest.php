<?php

declare(strict_types=1);

use Pollora\BlockBinding\Domain\Contracts\PostFinderInterface;
use Pollora\BlockBinding\Domain\Models\BindingContext;
use Pollora\BlockBinding\Tests\Fixtures\ArrayMetaStore;
use Pollora\BlockBinding\Tests\Fixtures\Concert;
use Pollora\BlockBinding\Tests\Fixtures\VenueExtras;
use Pollora\Colt\Model\Post;
use Pollora\Meta\Application\Services\MetaAccessor;
use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Application\Services\MetaSchemaRepository;
use Pollora\Meta\Domain\Services\MetaValueCaster;

beforeEach(function (): void {
    $store = new ArrayMetaStore(['post' => [7 => ['capacity' => '300']], 'term' => [3 => ['city' => 'Nantes']]]);
    $this->accessor = new MetaAccessor(new MetaSchemaRepository, new MetaSchemaBuilder, $store, new MetaValueCaster);
});

it('gives the arguments of the binding', function (): void {
    $context = new BindingContext(args: ['field' => 'date', 'format' => 'j F Y']);

    expect($context->arg('format'))->toBe('j F Y')
        ->and($context->arg('size', 'large'))->toBe('large');
});

it('reads typed meta on the object of the block context that carries them', function (): void {
    $context = new BindingContext(postId: 7, postType: 'concert', termId: 3, taxonomy: 'venue', metaAccessor: $this->accessor);

    expect($context->meta(Concert::class)?->capacity)->toBe(300)
        ->and($context->meta(VenueExtras::class)?->city)->toBe('Nantes');
});

it('has no meta to give without the object', function (): void {
    expect((new BindingContext(metaAccessor: $this->accessor))->meta(Concert::class))->toBeNull();
});

it('loads the post of the block context through the finder it is given, once', function (): void {
    $post = new Post;
    $finder = new class($post) implements PostFinderInterface
    {
        public int $calls = 0;

        public function __construct(private readonly Post $post) {}

        public function find(int $postId): ?Post
        {
            $this->calls++;

            return $postId === 7 ? $this->post : null;
        }
    };
    $context = new BindingContext(postId: 7, postFinder: $finder);

    expect($context->post())->toBe($post)
        ->and($context->post())->toBe($post)
        ->and($finder->calls)->toBe(1)
        ->and((new BindingContext(postId: 8, postFinder: $finder))->post())->toBeNull()
        ->and((new BindingContext)->post())->toBeNull();
});
