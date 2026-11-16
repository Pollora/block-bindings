<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\BlockBinding\Domain\Models\BindingContext;
use Pollora\BlockBinding\Infrastructure\Services\BindingFormatter;
use Pollora\BlockBinding\Infrastructure\Sources\AuthorMetaSource;
use Pollora\BlockBinding\Infrastructure\Sources\OptionSource;
use Pollora\BlockBinding\Infrastructure\Sources\TermMetaSource;
use Pollora\BlockBinding\Infrastructure\Sources\TypedMetaReader;
use Pollora\BlockBinding\Tests\Fixtures\ArrayMetaStore;
use Pollora\BlockBinding\Tests\Fixtures\ArtistProfile;
use Pollora\BlockBinding\Tests\Fixtures\FakePresenter;
use Pollora\BlockBinding\Tests\Fixtures\FakeVisibility;
use Pollora\BlockBinding\Tests\Fixtures\VenueExtras;
use Pollora\Meta\Application\Services\MetaAccessor;
use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Application\Services\MetaSchemaRepository;
use Pollora\Meta\Domain\Services\MetaValueCaster;

beforeEach(function (): void {
    $store = new ArrayMetaStore([
        'user' => [
            0 => ['stage_name' => 'Nobody'],
            1 => ['stage_name' => 'The First'],
            5 => ['stage_name' => 'The Hums'],
        ],
        'term' => [3 => ['city' => 'Lyon']],
    ]);
    $schemas = new MetaSchemaRepository;

    foreach ([ArtistProfile::class, VenueExtras::class] as $class) {
        $schemas->add((new MetaSchemaBuilder)->build($class));
    }

    $this->reader = new TypedMetaReader($schemas, new MetaAccessor($schemas, new MetaSchemaBuilder, $store, new MetaValueCaster));
    $this->formatter = new BindingFormatter(new FakePresenter);
});

it('pollora/author-meta reads the meta of an author whose ID is 1, and of none whose ID is 0', function (string $author, ?string $expected): void {
    Functions\when('get_post_field')->justReturn($author);
    $context = new BindingContext(args: ['key' => 'stage_name'], attribute: 'content', postId: 7);

    expect((new AuthorMetaSource($this->reader, $this->formatter))($context))->toBe($expected);
})->with([
    'the first user' => ['1', 'The First'],
    'another user' => ['5', 'The Hums'],
    'no author' => ['0', null],
    'a negative ID' => ['-1', null],
]);

describe('pollora/option', function (): void {
    beforeEach(function (): void {
        $this->stored = [];
        Functions\when('get_option')->alias(fn (string $name, mixed $default = false): mixed => $this->stored[$name] ?? $default);
        $this->source = new OptionSource(['news', 'count', 'off', 'list']);
    });

    it('gives the value of a text, a number or a boolean option as text', function (mixed $value, ?string $expected): void {
        $this->stored['news'] = $value;

        expect(($this->source)(new BindingContext(args: ['name' => 'news', 'fallback' => 'none'])))->toBe($expected);
    })->with([
        'text' => ['hello', 'hello'],
        'a number' => [12, '12'],
        'zero' => [0, '0'],
        'a float' => [1.5, '1.5'],
        'true' => [true, '1'],
        'empty text' => ['', 'none'],
        'false' => [false, 'none'],
        'an array' => [['a'], 'none'],
    ]);

    it('gives the fallback, as text, for an option that is not set', function (mixed $fallback, ?string $expected): void {
        expect(($this->source)(new BindingContext(args: ['name' => 'news', 'fallback' => $fallback])))->toBe($expected);
    })->with([
        'text' => ['none', 'none'],
        'a number' => [5, '5'],
        'zero' => [0, '0'],
        'an array' => [['x'], null],
        'nothing' => [null, null],
    ]);

    it('answers nothing for a name that is not text', function (): void {
        expect(($this->source)(new BindingContext(args: ['name' => ['news'], 'fallback' => 'none'])))->toBeNull()
            ->and(($this->source)(new BindingContext(args: ['fallback' => 'none'])))->toBeNull();
    });
});

describe('pollora/term-meta', function (): void {
    beforeEach(function (): void {
        $this->warnings = [];
        set_error_handler(function (int $level, string $message): bool {
            $this->warnings[] = $message;

            return true;
        });
    });

    afterEach(function (): void {
        restore_error_handler();
    });

    it('takes the queried term when the block context names only half of one', function (array $context): void {
        $term = new WP_Term;
        $term->term_id = 3;
        $term->taxonomy = 'venue';
        Functions\when('get_queried_object')->justReturn($term);
        $source = new TermMetaSource($this->reader, $this->formatter, new FakeVisibility);

        expect($source(new BindingContext(...[...$context, 'args' => ['key' => 'city'], 'attribute' => 'content'])))->toBe('Lyon');
    })->with([
        'a term without taxonomy' => [['termId' => 9]],
        'a taxonomy without term' => [['taxonomy' => 'genre']],
    ]);

    it('reads nothing when the queried object is not a term, or a term the visitor cannot see', function (mixed $queried, array $hidden): void {
        Functions\when('get_queried_object')->justReturn($queried);
        $source = new TermMetaSource($this->reader, $this->formatter, new FakeVisibility(hiddenTerms: $hidden));

        expect($source(new BindingContext(args: ['key' => 'city'], attribute: 'content')))->toBeNull()
            ->and($this->warnings)->toBe([]);
    })->with([
        'no queried object' => [null, []],
        'a post' => [new WP_Post, []],
        'a hidden term' => [(function (): WP_Term {
            $term = new WP_Term;
            $term->term_id = 3;
            $term->taxonomy = 'venue';

            return $term;
        })(), [3]],
    ]);
});
