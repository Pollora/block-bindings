<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\BlockBinding\Domain\Models\BindingContext;
use Pollora\BlockBinding\Infrastructure\Services\BindingFormatter;
use Pollora\BlockBinding\Infrastructure\Sources\AuthorMetaSource;
use Pollora\BlockBinding\Infrastructure\Sources\OptionSource;
use Pollora\BlockBinding\Infrastructure\Sources\PostMetaSource;
use Pollora\BlockBinding\Infrastructure\Sources\TermMetaSource;
use Pollora\BlockBinding\Infrastructure\Sources\TypedMetaReader;
use Pollora\BlockBinding\Tests\Fixtures\ArrayMetaStore;
use Pollora\BlockBinding\Tests\Fixtures\ArtistProfile;
use Pollora\BlockBinding\Tests\Fixtures\Concert;
use Pollora\BlockBinding\Tests\Fixtures\FakePresenter;
use Pollora\BlockBinding\Tests\Fixtures\FakeVisibility;
use Pollora\BlockBinding\Tests\Fixtures\VenueExtras;
use Pollora\Meta\Application\Services\MetaAccessor;
use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Application\Services\MetaSchemaRepository;
use Pollora\Meta\Domain\Services\MetaValueCaster;

beforeEach(function (): void {
    $this->store = new ArrayMetaStore([
        'post' => [7 => ['capacity' => '1200', 'backstage_code' => 'X42', '_promoter_fee' => '900']],
        'user' => [5 => ['stage_name' => 'The Hums', 'phone' => '0600000000']],
        'term' => [3 => ['city' => 'Lyon']],
    ]);
    $schemas = new MetaSchemaRepository;

    foreach ([Concert::class, ArtistProfile::class, VenueExtras::class] as $class) {
        $schemas->add((new MetaSchemaBuilder)->build($class));
    }

    $this->reader = new TypedMetaReader($schemas, new MetaAccessor($schemas, new MetaSchemaBuilder, $this->store, new MetaValueCaster));
    $this->formatter = new BindingFormatter(new FakePresenter);

    Functions\when('number_format_i18n')->alias(fn (float|int $number, int $decimals = 0): string => number_format((float) $number, $decimals, ',', ' '));
});

/**
 * @param  array<string, mixed>  $args
 */
function concertContext(array $args, ?int $termId = null, ?string $taxonomy = null): BindingContext
{
    return new BindingContext(args: $args, attribute: 'content', postId: 7, postType: 'concert', termId: $termId, taxonomy: $taxonomy);
}

it('pollora/post-meta reads a declared meta exposed in REST, formatted', function (): void {
    $source = new PostMetaSource($this->reader, $this->formatter);

    expect($source(concertContext(['key' => 'capacity'])))->toBe('1 200')
        ->and($source(concertContext(['key' => 'capacity', 'format' => 'raw'])))->toBe(1200);
});

it('pollora/post-meta reads nothing hidden from REST, protected or undeclared', function (string $key): void {
    expect((new PostMetaSource($this->reader, $this->formatter))(concertContext(['key' => $key])))->toBeNull();
})->with(['not in REST' => ['backstage_code'], 'protected' => ['_promoter_fee'], 'undeclared' => ['unknown_key']]);

it('pollora/term-meta reads the term of the block context', function (): void {
    $source = new TermMetaSource($this->reader, $this->formatter, new FakeVisibility);

    expect($source(concertContext(['key' => 'city'], 3, 'venue')))->toBe('Lyon');
});

it('pollora/term-meta falls back on the queried term, when the visitor can see it', function (): void {
    $term = new WP_Term;
    $term->term_id = 3;
    $term->taxonomy = 'venue';

    Functions\when('get_queried_object')->justReturn($term);

    expect((new TermMetaSource($this->reader, $this->formatter, new FakeVisibility))(concertContext(['key' => 'city'])))->toBe('Lyon')
        ->and((new TermMetaSource($this->reader, $this->formatter, new FakeVisibility(hiddenTerms: [3])))(concertContext(['key' => 'city'])))->toBeNull();
});

it('pollora/author-meta reads only the public meta of the author', function (): void {
    Functions\when('get_post_field')->justReturn('5');
    $source = new AuthorMetaSource($this->reader, $this->formatter);

    expect($source(concertContext(['key' => 'stage_name'])))->toBe('The Hums')
        ->and($source(concertContext(['key' => 'phone'])))->toBeNull();
});

it('pollora/option reads only the options the project lists', function (): void {
    Functions\when('get_option')->alias(fn (string $name): string => $name === 'blogdescription' ? 'Live music' : 'secret');
    $source = new OptionSource(['blogdescription']);

    expect($source(new BindingContext(args: ['name' => 'blogdescription'])))->toBe('Live music')
        ->and($source(new BindingContext(args: ['name' => 'admin_email'])))->toBeNull();
});

it('pollora/author-meta reads nothing without a key, a post or an author', function (array $args, ?int $postId, string $author): void {
    Functions\when('get_post_field')->justReturn($author);
    $context = new BindingContext(args: $args, attribute: 'content', postId: $postId);

    expect((new AuthorMetaSource($this->reader, $this->formatter))($context))->toBeNull();
})->with([
    'no key' => [[], 7, '5'],
    'empty key' => [['key' => ''], 7, '5'],
    'key not a string' => [['key' => 12], 7, '5'],
    'no post' => [['key' => 'stage_name'], null, '5'],
    'post without author' => [['key' => 'stage_name'], 7, '0'],
]);

it('pollora/post-meta reads nothing without a key or a post', function (array $args, ?int $postId): void {
    $context = new BindingContext(args: $args, attribute: 'content', postId: $postId, postType: 'concert');

    expect((new PostMetaSource($this->reader, $this->formatter))($context))->toBeNull();
})->with([
    'no key' => [[], 7],
    'empty key' => [['key' => ''], 7],
    'no post' => [['key' => 'capacity'], null],
]);

it('pollora/post-meta finds the post type of the post when the block context has none', function (): void {
    Functions\when('get_post_type')->justReturn('concert');
    $context = new BindingContext(args: ['key' => 'capacity'], attribute: 'content', postId: 7);

    expect((new PostMetaSource($this->reader, $this->formatter))($context))->toBe('1 200');
});
