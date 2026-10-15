<?php

declare(strict_types=1);

use Pollora\BlockBinding\Application\Services\BindingReferenceInspector;
use Pollora\BlockBinding\Application\Services\BindingSourceBuilder;
use Pollora\BlockBinding\Application\Services\BindingSourceRegistry;
use Pollora\BlockBinding\Infrastructure\Sources\AuthorMetaSource;
use Pollora\BlockBinding\Infrastructure\Sources\OptionSource;
use Pollora\BlockBinding\Infrastructure\Sources\PostMetaSource;
use Pollora\BlockBinding\Infrastructure\Sources\TermMetaSource;
use Pollora\BlockBinding\Tests\Fixtures\ArtistProfile;
use Pollora\BlockBinding\Tests\Fixtures\Concert;
use Pollora\BlockBinding\Tests\Fixtures\EventBinding;
use Pollora\BlockBinding\Tests\Fixtures\InvokableBinding;
use Pollora\BlockBinding\Tests\Fixtures\VenueExtras;
use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Application\Services\MetaSchemaRepository;

beforeEach(function (): void {
    $sources = new BindingSourceRegistry;

    foreach ([PostMetaSource::class, TermMetaSource::class, AuthorMetaSource::class, OptionSource::class, EventBinding::class, InvokableBinding::class] as $class) {
        $sources->add((new BindingSourceBuilder)->build($class));
    }

    $schemas = new MetaSchemaRepository;

    foreach ([Concert::class, ArtistProfile::class, VenueExtras::class] as $class) {
        $schemas->add((new MetaSchemaBuilder)->build($class));
    }

    $this->inspector = new BindingReferenceInspector($sources, $schemas, ['blogdescription']);
});

it('accepts a binding that can show its value', function (string $source, array $args): void {
    expect($this->inspector->problem($source, $args))->toBeNull();
})->with([
    'a field' => ['acme/event', ['field' => 'remaining_seats']],
    'an invokable source' => ['acme/echo', ['say' => 'hi']],
    'a post meta in REST' => ['pollora/post-meta', ['key' => 'capacity']],
    'a term meta in REST' => ['pollora/term-meta', ['key' => 'city']],
    'a public user meta' => ['pollora/author-meta', ['key' => 'stage_name']],
    'a listed option' => ['pollora/option', ['name' => 'blogdescription']],
]);

it('says why a binding would leave its block unchanged', function (string $source, array $args, string $problem): void {
    expect($this->inspector->problem($source, $args))->toBe($problem);
})->with([
    'unknown field' => ['acme/event', ['field' => 'seats'], 'the field "seats" does not exist; acme/event has the fields "remaining_seats", "booking_url", "capacity", "sold_out", "summary", "nothing", "broken"'],
    'no field' => ['acme/event', [], 'no "field" argument; acme/event has the fields "remaining_seats", "booking_url", "capacity", "sold_out", "summary", "nothing", "broken"'],
    'undeclared meta' => ['pollora/post-meta', ['key' => 'nope'], 'no #[Meta] declares the post meta "nope"'],
    'meta out of REST' => ['pollora/post-meta', ['key' => 'backstage_code'], 'the meta "backstage_code" is never shown: it is not exposed in REST (showInRest: true) or its key is protected'],
    'protected meta' => ['pollora/post-meta', ['key' => '_promoter_fee'], 'the meta "_promoter_fee" is never shown: it is not exposed in REST (showInRest: true) or its key is protected'],
    'private user meta' => ['pollora/author-meta', ['key' => 'phone'], 'the meta "phone" is never shown: it is not marked #[Meta(public: true)]'],
    'no key' => ['pollora/term-meta', [], 'no "key" argument'],
    'unlisted option' => ['pollora/option', ['name' => 'admin_email'], 'the option "admin_email" is not listed in block-bindings.options'],
]);

it('leaves the sources it does not know to WordPress', function (): void {
    expect($this->inspector->knows('core/post-meta'))->toBeFalse()
        ->and($this->inspector->problem('core/post-meta', ['key' => 'anything']))->toBeNull()
        ->and($this->inspector->knows('acme/event'))->toBeTrue();
});
