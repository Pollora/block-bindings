<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Infrastructure\Services;

use Pollora\Attributes\Support\Naming;
use Pollora\BlockBinding\Application\Services\BindingSourceRegistry;
use Pollora\BlockBinding\Domain\Models\BindingSource;
use Pollora\BlockBinding\Infrastructure\Sources\TypedMetaReader;
use Pollora\Meta\Application\Services\MetaSchemaRepository;
use Pollora\Meta\Domain\Enums\MetaObjectType;
use Pollora\Meta\Domain\Enums\MetaValueType;
use Pollora\Meta\Domain\Models\MetaDefinition;

/**
 * What the editor needs to offer the fields of every Pollora source: the
 * fields of the `#[BlockBinding]` classes, and for the typed meta sources the
 * meta they may show, by post type or taxonomy.
 *
 * A field is offered to the attributes of its data type, as the editor
 * compares them: every field gives a string, and an attachment also its ID.
 */
final readonly class BindingEditorData
{
    public const string REST_NAMESPACE = 'pollora/v1';

    public const string REST_ROUTE = '/block-bindings/resolve';

    /**
     * @param  list<string>  $options  The options a project lists as bindable (`block-bindings.options`)
     */
    public function __construct(
        private BindingSourceRegistry $sources,
        private MetaSchemaRepository $schemas,
        private array $options,
    ) {}

    /**
     * @return array{route: string, sources: array<string, array<string, mixed>>}
     */
    public function toArray(): array
    {
        $sources = [];

        foreach ($this->sources->all() as $source) {
            $sources[$source->name] = match ($source->name) {
                'pollora/post-meta' => ['kind' => 'meta', 'subtype' => 'postType', 'fields' => $this->metaFields(MetaObjectType::Post, TypedMetaReader::exposedInRest(...))],
                'pollora/term-meta' => ['kind' => 'meta', 'subtype' => 'taxonomy', 'fields' => $this->metaFields(MetaObjectType::Term, TypedMetaReader::exposedInRest(...))],
                'pollora/author-meta' => ['kind' => 'meta', 'subtype' => null, 'fields' => $this->metaFields(MetaObjectType::User, static fn (MetaDefinition $definition): bool => $definition->public)],
                'pollora/option' => ['kind' => 'fields', 'postTypes' => [], 'fields' => $this->optionFields()],
                default => ['kind' => 'fields', 'postTypes' => $source->postTypes, 'fields' => $this->sourceFields($source)],
            };
        }

        return ['route' => self::REST_NAMESPACE.self::REST_ROUTE, 'sources' => $sources];
    }

    /**
     * @return list<array{label: string, args: array<string, string>, type: string}>
     */
    private function sourceFields(BindingSource $source): array
    {
        $fields = [];

        foreach ($source->fields as $field) {
            $fields[] = ['label' => $field->label, 'args' => ['field' => $field->name], 'type' => 'string'];
        }

        return $fields;
    }

    /**
     * The meta a source may show, by subtype ('' for every object of the type).
     *
     * @param  \Closure(MetaDefinition): bool  $readable
     * @return array<string, list<array{label: string, args: array<string, string>, type: string}>>
     */
    private function metaFields(MetaObjectType $objectType, \Closure $readable): array
    {
        $fields = [];

        foreach ($this->schemas->all() as $schema) {
            if ($schema->objectType !== $objectType) {
                continue;
            }

            foreach ($schema->definitions as $definition) {
                if (! $readable($definition) || $definition->valueType === MetaValueType::DataObject) {
                    continue;
                }

                $label = $definition->label ?? Naming::headline($definition->property);

                foreach ($schema->subtypes === [] ? [''] : $schema->subtypes as $subtype) {
                    $fields[$subtype][] = ['label' => $label, 'args' => ['key' => $definition->key], 'type' => 'string'];

                    if ($definition->media) {
                        $fields[$subtype][] = ['label' => $label, 'args' => ['key' => $definition->key], 'type' => 'number'];
                    }
                }
            }
        }

        return $fields;
    }

    /**
     * @return list<array{label: string, args: array<string, string>, type: string}>
     */
    private function optionFields(): array
    {
        $fields = [];

        foreach ($this->options as $name) {
            $fields[] = ['label' => Naming::headline($name), 'args' => ['name' => $name], 'type' => 'string'];
        }

        return $fields;
    }
}
