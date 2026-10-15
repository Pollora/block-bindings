<?php

declare(strict_types=1);

use Pollora\BlockBinding\Application\Services\BindingSourceBuilder;
use Pollora\BlockBinding\Application\Services\BindingSourceRegistry;
use Pollora\BlockBinding\Infrastructure\Services\BindingEditorData;
use Pollora\BlockBinding\Infrastructure\Sources\AuthorMetaSource;
use Pollora\BlockBinding\Infrastructure\Sources\OptionSource;
use Pollora\BlockBinding\Infrastructure\Sources\PostMetaSource;
use Pollora\BlockBinding\Infrastructure\Sources\TermMetaSource;
use Pollora\BlockBinding\Tests\Fixtures\ArtistProfile;
use Pollora\BlockBinding\Tests\Fixtures\Concert;
use Pollora\BlockBinding\Tests\Fixtures\EventBinding;
use Pollora\BlockBinding\Tests\Fixtures\VenueExtras;
use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Application\Services\MetaSchemaRepository;

beforeEach(function (): void {
    $sources = new BindingSourceRegistry;

    foreach ([PostMetaSource::class, TermMetaSource::class, AuthorMetaSource::class, OptionSource::class, EventBinding::class] as $class) {
        $sources->add((new BindingSourceBuilder)->build($class));
    }

    $schemas = new MetaSchemaRepository;

    foreach ([Concert::class, ArtistProfile::class, VenueExtras::class] as $class) {
        $schemas->add((new MetaSchemaBuilder)->build($class));
    }

    $this->data = (new BindingEditorData($sources, $schemas, ['blogdescription']))->toArray();
});

it('gives the editor the route and the fields of a #[BlockBinding] source', function (): void {
    expect($this->data['route'])->toBe('pollora/v1/block-bindings/resolve')
        ->and($this->data['sources']['acme/event']['postTypes'])->toBe(['event'])
        ->and($this->data['sources']['acme/event']['fields'][0])->toBe(['label' => 'Remaining seats', 'args' => ['field' => 'remaining_seats'], 'type' => 'string']);
});

it('offers the post meta a binding may show, by post type', function (): void {
    $fields = $this->data['sources']['pollora/post-meta'];
    $keys = array_map(static fn (array $field): string => $field['args']['key'].':'.$field['type'], $fields['fields']['concert']);

    expect($fields['subtype'])->toBe('postType')
        ->and($keys)->toContain('capacity:string', 'starts_at:string', 'cover_image_id:string', 'cover_image_id:number')
        ->not->toContain('backstage_code:string', '_promoter_fee:string')
        ->and($fields['fields']['concert'][0]['label'])->toBe('Starts At');
});

it('offers the public user meta, the term meta and the listed options', function (): void {
    expect(array_column(array_column($this->data['sources']['pollora/author-meta']['fields'][''], 'args'), 'key'))->toBe(['stage_name'])
        ->and($this->data['sources']['pollora/term-meta']['fields']['venue'][0]['args'])->toBe(['key' => 'city'])
        ->and($this->data['sources']['pollora/option']['fields'])->toBe([['label' => 'Blogdescription', 'args' => ['name' => 'blogdescription'], 'type' => 'string']]);
});
