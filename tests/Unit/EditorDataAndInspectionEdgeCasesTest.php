<?php

declare(strict_types=1);

use Pollora\BlockBinding\Application\Services\BindingReferenceInspector;
use Pollora\BlockBinding\Application\Services\BindingSourceBuilder;
use Pollora\BlockBinding\Application\Services\BindingSourceRegistry;
use Pollora\BlockBinding\Infrastructure\Services\BindingEditorData;
use Pollora\BlockBinding\Infrastructure\Sources\AuthorMetaSource;
use Pollora\BlockBinding\Infrastructure\Sources\OptionSource;
use Pollora\BlockBinding\Infrastructure\Sources\PostMetaSource;
use Pollora\BlockBinding\Infrastructure\Sources\TermMetaSource;
use Pollora\BlockBinding\Infrastructure\Sources\TypedMetaReader;
use Pollora\BlockBinding\Tests\Fixtures\ArrayMetaStore;
use Pollora\BlockBinding\Tests\Fixtures\ArtistProfile;
use Pollora\BlockBinding\Tests\Fixtures\Concert;
use Pollora\BlockBinding\Tests\Fixtures\ConcertExtras;
use Pollora\BlockBinding\Tests\Fixtures\EventBinding;
use Pollora\BlockBinding\Tests\Fixtures\InvokableBinding;
use Pollora\BlockBinding\Tests\Fixtures\VenueExtras;
use Pollora\BlockBinding\Tests\Fixtures\VenueSecrets;
use Pollora\Meta\Application\Services\MetaAccessor;
use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Application\Services\MetaSchemaRepository;
use Pollora\Meta\Domain\Enums\MetaObjectType;
use Pollora\Meta\Domain\Models\MetaDefinition;
use Pollora\Meta\Domain\Services\MetaValueCaster;

beforeEach(function (): void {
    $this->sources = new BindingSourceRegistry;

    foreach ([PostMetaSource::class, TermMetaSource::class, AuthorMetaSource::class, OptionSource::class, EventBinding::class, InvokableBinding::class] as $class) {
        $this->sources->add((new BindingSourceBuilder)->build($class));
    }

    $this->schemas = new MetaSchemaRepository;

    foreach ([Concert::class, ConcertExtras::class, ArtistProfile::class, VenueExtras::class, VenueSecrets::class] as $class) {
        $this->schemas->add((new MetaSchemaBuilder)->build($class));
    }
});

it('describes every kind of source to the editor, whole', function (): void {
    $data = (new BindingEditorData($this->sources, $this->schemas, ['blogdescription']))->toArray();
    $sources = $data['sources'];

    expect(array_keys($sources))->toBe(['pollora/post-meta', 'pollora/term-meta', 'pollora/author-meta', 'pollora/option', 'acme/event', 'acme/echo'])
        ->and(array_diff_key($sources['pollora/post-meta'], ['fields' => 0]))->toBe(['kind' => 'meta', 'subtype' => 'postType'])
        ->and(array_diff_key($sources['pollora/term-meta'], ['fields' => 0]))->toBe(['kind' => 'meta', 'subtype' => 'taxonomy'])
        ->and(array_diff_key($sources['pollora/author-meta'], ['fields' => 0]))->toBe(['kind' => 'meta', 'subtype' => null])
        ->and(array_diff_key($sources['pollora/option'], ['fields' => 0]))->toBe(['kind' => 'fields', 'postTypes' => []])
        ->and(array_diff_key($sources['acme/event'], ['fields' => 0]))->toBe(['kind' => 'fields', 'postTypes' => ['event']])
        ->and(array_diff_key($sources['acme/echo'], ['fields' => 0]))->toBe(['kind' => 'fields', 'postTypes' => []])
        ->and($sources['acme/echo']['fields'])->toBe([]);
});

it('offers the media meta twice, as text and as an ID, with the same label, and skips structured meta', function (): void {
    $fields = (new BindingEditorData($this->sources, $this->schemas, []))->toArray()['sources']['pollora/post-meta']['fields']['concert'];

    expect($fields)->toContain(['label' => 'Cover Image Id', 'args' => ['key' => 'cover_image_id'], 'type' => 'string'])
        ->toContain(['label' => 'Cover Image Id', 'args' => ['key' => 'cover_image_id'], 'type' => 'number'])
        ->toContain(['label' => 'Name of the tour', 'args' => ['key' => 'tour_name'], 'type' => 'string'])
        ->and(array_column(array_column($fields, 'args'), 'key'))->not->toContain('lineup');
});

it('does not offer a meta twice for a post type declared by two schemas', function (): void {
    $fields = (new BindingEditorData($this->sources, $this->schemas, []))->toArray()['sources']['pollora/post-meta']['fields']['concert'];
    $keys = array_column(array_column($fields, 'args'), 'key');

    expect($keys)->toContain('tour_name')
        ->and(array_count_values($keys)['tour_name'])->toBe(1);
});

it('reads a meta from the second schema of a post type', function (): void {
    $store = new ArrayMetaStore(['post' => [7 => ['tour_name' => 'Hums tour']]]);
    $reader = new TypedMetaReader($this->schemas, new MetaAccessor($this->schemas, new MetaSchemaBuilder, $store, new MetaValueCaster));

    $meta = $reader->read(MetaObjectType::Post, 'concert', 7, 'tour_name', TypedMetaReader::exposedInRest(...));

    expect($meta[0])->toBeInstanceOf(MetaDefinition::class)
        ->and($meta[0]->property)->toBe('tourName')
        ->and($meta[1])->toBe('Hums tour')
        ->and($reader->read(MetaObjectType::Post, 'concert', 7, 'missing', TypedMetaReader::exposedInRest(...)))->toBeNull();
});

describe('inspecting a binding', function (): void {
    beforeEach(function (): void {
        $this->inspector = new BindingReferenceInspector($this->sources, $this->schemas, ['blogdescription']);
    });

    it('asks for an argument that is empty or not text, as for one that is missing', function (string $source, array $args, string $problem): void {
        expect($this->inspector->problem($source, $args))->toBe($problem);
    })->with([
        'empty key' => ['pollora/post-meta', ['key' => ''], 'no "key" argument'],
        'key not text' => ['pollora/term-meta', ['key' => 12], 'no "key" argument'],
        'empty name' => ['pollora/option', ['name' => ''], 'no "name" argument'],
        'name not text' => ['pollora/option', ['name' => ['x']], 'no "name" argument'],
        'empty field' => ['acme/event', ['field' => ''], 'no "field" argument; acme/event has the fields "remaining_seats", "booking_url", "capacity", "sold_out", "summary", "nothing", "broken"'],
        'field not text' => ['acme/event', ['field' => 3], 'no "field" argument; acme/event has the fields "remaining_seats", "booking_url", "capacity", "sold_out", "summary", "nothing", "broken"'],
    ]);

    it('does not show a term meta REST does not expose', function (): void {
        expect($this->inspector->problem('pollora/term-meta', ['key' => 'door_code']))
            ->toBe('the meta "door_code" is never shown: it is not exposed in REST (showInRest: true) or its key is protected')
            ->and($this->inspector->problem('pollora/term-meta', ['key' => 'city']))->toBeNull();
    });
});

it('lists its sources in the order they were added, without gaps', function (): void {
    $registry = new BindingSourceRegistry;
    $registry->add((new BindingSourceBuilder)->build(InvokableBinding::class));
    $registry->add((new BindingSourceBuilder)->build(EventBinding::class));

    expect(array_keys($registry->all()))->toBe([0, 1])
        ->and($registry->all()[1]->name)->toBe('acme/event');
});
