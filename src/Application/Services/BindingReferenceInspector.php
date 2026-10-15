<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Application\Services;

use Pollora\BlockBinding\Domain\Models\BindingFieldDefinition;
use Pollora\BlockBinding\Domain\Models\BindingSource;
use Pollora\Meta\Application\Services\MetaSchemaRepository;
use Pollora\Meta\Domain\Enums\MetaObjectType;
use Pollora\Meta\Domain\Models\MetaDefinition;

/**
 * Says why a binding to a Pollora source would leave its block with the
 * content it was saved with: a field the source does not have, a meta no
 * `#[Meta]` declares, a meta or an option the source may not show.
 *
 * WordPress gives no sign of any of these: the block renders, unchanged.
 */
final readonly class BindingReferenceInspector
{
    /**
     * @param  list<string>  $options  The options `pollora/option` may read
     */
    public function __construct(
        private BindingSourceRegistry $sources,
        private MetaSchemaRepository $schemas,
        private array $options = [],
    ) {}

    /**
     * Whether the source is one of Pollora's, which the inspector can check.
     */
    public function knows(string $sourceName): bool
    {
        return $this->sources->find($sourceName) instanceof BindingSource;
    }

    /**
     * Why the binding shows nothing, or null when it can show its value.
     *
     * @param  array<string, mixed>  $args
     */
    public function problem(string $sourceName, array $args): ?string
    {
        $source = $this->sources->find($sourceName);

        if (! $source instanceof BindingSource) {
            return null;
        }

        return match ($sourceName) {
            'pollora/post-meta' => $this->metaProblem(MetaObjectType::Post, $args, static fn (MetaDefinition $definition): bool => $definition->showInRest && ! $definition->isProtected(), 'it is not exposed in REST (showInRest: true) or its key is protected'),
            'pollora/term-meta' => $this->metaProblem(MetaObjectType::Term, $args, static fn (MetaDefinition $definition): bool => $definition->showInRest && ! $definition->isProtected(), 'it is not exposed in REST (showInRest: true) or its key is protected'),
            'pollora/author-meta' => $this->metaProblem(MetaObjectType::User, $args, static fn (MetaDefinition $definition): bool => $definition->public, 'it is not marked #[Meta(public: true)]'),
            'pollora/option' => $this->optionProblem($args),
            default => $this->fieldProblem($source, $args),
        };
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function fieldProblem(BindingSource $source, array $args): ?string
    {
        if ($source->isInvokable()) {
            return null;
        }

        $field = $args['field'] ?? null;

        if (! is_string($field) || $field === '') {
            return sprintf('no "field" argument; %s has %s', $source->name, $this->fieldList($source));
        }

        return $source->field($field) instanceof BindingFieldDefinition
            ? null
            : sprintf('the field "%s" does not exist; %s has %s', $field, $source->name, $this->fieldList($source));
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  \Closure(MetaDefinition): bool  $readable
     */
    private function metaProblem(MetaObjectType $objectType, array $args, \Closure $readable, string $unreadable): ?string
    {
        $key = $args['key'] ?? null;

        if (! is_string($key) || $key === '') {
            return 'no "key" argument';
        }

        $found = false;

        foreach ($this->schemas->all() as $schema) {
            if ($schema->objectType !== $objectType || ! ($definition = $schema->find($key)) instanceof MetaDefinition) {
                continue;
            }

            if ($readable($definition)) {
                return null;
            }

            $found = true;
        }

        return $found
            ? sprintf('the meta "%s" is never shown: %s', $key, $unreadable)
            : sprintf('no #[Meta] declares the %s meta "%s"', $objectType->value, $key);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function optionProblem(array $args): ?string
    {
        $name = $args['name'] ?? null;

        if (! is_string($name) || $name === '') {
            return 'no "name" argument';
        }

        return in_array($name, $this->options, true) ? null : sprintf('the option "%s" is not listed in block-bindings.options', $name);
    }

    private function fieldList(BindingSource $source): string
    {
        return 'the fields '.implode(', ', array_map(static fn (string $name): string => '"'.$name.'"', array_keys($source->fields)));
    }
}
