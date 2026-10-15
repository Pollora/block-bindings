<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Domain\Models;

/**
 * A Block Bindings source, as declared by a `#[BlockBinding]` class.
 *
 * Immutable: built once by reflection, then registered with WordPress and
 * handed to the resolver on each bound attribute.
 */
final readonly class BindingSource
{
    /**
     * @param  string  $name  Source name, `namespace/name`
     * @param  string  $label  Label shown in the editor
     * @param  class-string  $class  The class answering for the source
     * @param  list<string>  $usesContext  Block context the source reads
     * @param  list<string>  $postTypes  Post types the source answers for; empty for all
     * @param  array<string, BindingFieldDefinition>  $fields  Fields by name; empty for an invokable source
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $class,
        public array $usesContext,
        public array $postTypes,
        public array $fields,
    ) {}

    /**
     * Whether `__invoke()` answers every call, the source having no field.
     */
    public function isInvokable(): bool
    {
        return $this->fields === [];
    }

    public function field(string $name): ?BindingFieldDefinition
    {
        return $this->fields[$name] ?? null;
    }

    /**
     * Whether the source answers for a post of this type.
     */
    public function answersFor(?string $postType): bool
    {
        return $this->postTypes === [] || ($postType !== null && in_array($postType, $this->postTypes, true));
    }
}
