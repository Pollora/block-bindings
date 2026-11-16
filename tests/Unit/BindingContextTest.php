<?php

declare(strict_types=1);

use Pollora\BlockBinding\Domain\Contracts\PostFinderInterface;
use Pollora\BlockBinding\Domain\Models\BindingContext;
use Pollora\BlockBinding\Tests\Fixtures\ArrayMetaStore;
use Pollora\BlockBinding\Tests\Fixtures\ArtistProfile;
use Pollora\BlockBinding\Tests\Fixtures\CommentRating;
use Pollora\BlockBinding\Tests\Fixtures\Concert;
use Pollora\BlockBinding\Tests\Fixtures\VenueExtras;
use Pollora\Colt\Model\Post;
use Pollora\Meta\Application\Services\MetaAccessor;
use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Application\Services\MetaSchemaRepository;
use Pollora\Meta\Domain\Services\MetaValueCaster;

require_once dirname(__DIR__).'/Fixtures/blocks.php';

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

it('reads the typed meta of the post author, given by the post of the block context', function (): void {
    $store = new ArrayMetaStore(['user' => [5 => ['stage_name' => 'The Hums']]]);
    $accessor = new MetaAccessor(new MetaSchemaRepository, new MetaSchemaBuilder, $store, new MetaValueCaster);
    $post = new Post;
    $post->post_author = 5;
    $finder = new class($post) implements PostFinderInterface
    {
        public function __construct(private readonly Post $post) {}

        public function find(int $postId): ?Post
        {
            return $this->post;
        }
    };
    $context = new BindingContext(postId: 7, postFinder: $finder, metaAccessor: $accessor);

    expect($context->meta(ArtistProfile::class)?->stageName)->toBe('The Hums');
});

it('has no author meta when the post is unknown or has no author', function (): void {
    $finder = new class implements PostFinderInterface
    {
        public function find(int $postId): ?Post
        {
            return $postId === 8 ? new Post : null;
        }
    };

    expect((new BindingContext(postId: 7, postFinder: $finder, metaAccessor: $this->accessor))->meta(ArtistProfile::class))->toBeNull()
        ->and((new BindingContext(postId: 8, postFinder: $finder, metaAccessor: $this->accessor))->meta(ArtistProfile::class))->toBeNull();
});

it('reads the typed meta of the comment of the block context', function (): void {
    $store = new ArrayMetaStore(['comment' => [12 => ['rating' => '4']]]);
    $accessor = new MetaAccessor(new MetaSchemaRepository, new MetaSchemaBuilder, $store, new MetaValueCaster);
    $withComment = new BindingContext(block: boundBlock(['commentId' => '12']), metaAccessor: $accessor);
    $withoutComment = new BindingContext(block: boundBlock(), metaAccessor: $accessor);

    expect($withComment->meta(CommentRating::class)?->rating)->toBe(4)
        ->and($withoutComment->meta(CommentRating::class))->toBeNull()
        ->and((new BindingContext(metaAccessor: $accessor))->meta(CommentRating::class))->toBeNull();
});

it('refuses typed meta when no accessor is given', function (): void {
    expect(fn () => (new BindingContext)->meta(Concert::class))->toThrow(LogicException::class, 'Typed meta are not available.');
});
