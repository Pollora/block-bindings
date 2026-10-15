<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Application\Services;

use Pollora\Attributes\BlockBinding;
use Pollora\Attributes\BlockBinding\BindingField;
use Pollora\Attributes\Support\Naming;
use Pollora\BlockBinding\Domain\Enums\BindingFieldType;
use Pollora\BlockBinding\Domain\Exceptions\InvalidBindingSourceException;
use Pollora\BlockBinding\Domain\Models\BindingFieldDefinition;
use Pollora\BlockBinding\Domain\Models\BindingSource;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Stringable;

/**
 * Builds a binding source from a `#[BlockBinding]` class and its
 * `#[BindingField]` methods.
 *
 * Every mistake that would otherwise leave a block silently empty is checked
 * here, so it fails at discovery with the class and method named.
 */
final class BindingSourceBuilder
{
    /**
     * WordPress's rule for a source name: one namespace, lowercase.
     */
    private const string NAME_PATTERN = '/^[a-z0-9-]+\/[a-z0-9-]+$/';

    /**
     * Return types a field may declare.
     */
    private const array SCALAR_RETURNS = ['string', 'int', 'float', 'bool', 'null'];

    /**
     * @param  class-string  $class
     *
     * @throws InvalidBindingSourceException When the class is not a valid source
     */
    public function build(string $class): BindingSource
    {
        $reflection = new ReflectionClass($class);
        $attribute = ($reflection->getAttributes(BlockBinding::class)[0] ?? null)?->newInstance();

        if (! $attribute instanceof BlockBinding) {
            throw InvalidBindingSourceException::forClass($class, 'the class has no #[BlockBinding] attribute.');
        }

        if (preg_match(self::NAME_PATTERN, $attribute->name) !== 1) {
            throw InvalidBindingSourceException::forClass($class, sprintf(
                'the name "%s" must be "namespace/name", in lowercase letters, digits and dashes.',
                $attribute->name
            ));
        }

        if (! $reflection->isInstantiable()) {
            throw InvalidBindingSourceException::forClass($class, 'the class cannot be instantiated.');
        }

        $fields = $this->buildFields($reflection);

        if ($fields === [] && ! $reflection->hasMethod('__invoke')) {
            throw InvalidBindingSourceException::forClass($class, 'mark a public method #[BindingField], or define __invoke() to answer every call.');
        }

        if ($fields === []) {
            $this->assertReturnType($class, $reflection->getMethod('__invoke'));
        }

        return new BindingSource(
            name: $attribute->name,
            label: $attribute->label ?? Naming::headline($reflection->getShortName()),
            class: $class,
            usesContext: $attribute->usesContext,
            postTypes: $attribute->postTypes,
            fields: $fields,
        );
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     * @return array<string, BindingFieldDefinition>
     */
    private function buildFields(ReflectionClass $reflection): array
    {
        $class = $reflection->getName();
        $fields = [];

        foreach ($reflection->getMethods() as $method) {
            $attribute = ($method->getAttributes(BindingField::class)[0] ?? null)?->newInstance();

            if (! $attribute instanceof BindingField) {
                continue;
            }

            if (! $method->isPublic() || $method->isStatic()) {
                throw InvalidBindingSourceException::forField($class, $method->getName(), 'a field must be a public, non-static method.');
            }

            $this->assertReturnType($class, $method);

            $name = $attribute->name ?? Naming::snake($method->getName());

            if (isset($fields[$name])) {
                throw InvalidBindingSourceException::forField($class, $method->getName(), sprintf(
                    'the field "%s" is already answered by %s().',
                    $name,
                    $fields[$name]->method
                ));
            }

            $type = $attribute->type instanceof BindingFieldType ? $attribute->type : BindingFieldType::tryFrom($attribute->type);

            if (! $type instanceof BindingFieldType) {
                throw InvalidBindingSourceException::forField($class, $method->getName(), sprintf(
                    'the type "%s" is unknown; use text, url or image.',
                    (string) $attribute->type
                ));
            }

            $fields[$name] = new BindingFieldDefinition(
                name: $name,
                label: $attribute->label ?? Naming::headline($method->getName()),
                type: $type,
                method: $method->getName(),
            );
        }

        return $fields;
    }

    private function assertReturnType(string $class, ReflectionMethod $method): void
    {
        $type = $method->getReturnType();

        if (! $type instanceof ReflectionType) {
            throw InvalidBindingSourceException::forField($class, $method->getName(), 'declare its return type: string, int, float, bool, Stringable or null.');
        }

        $types = match (true) {
            $type instanceof ReflectionUnionType => $type->getTypes(),
            $type instanceof ReflectionIntersectionType => [$type],
            default => [$type],
        };

        foreach ($types as $member) {
            if (! $member instanceof ReflectionNamedType || ! $this->isAllowedReturn($member)) {
                throw InvalidBindingSourceException::forField($class, $method->getName(), sprintf(
                    'it returns %s; a field returns string, int, float, bool, Stringable or null.',
                    (string) $type
                ));
            }
        }
    }

    private function isAllowedReturn(ReflectionNamedType $type): bool
    {
        $name = $type->getName();

        return in_array($name, self::SCALAR_RETURNS, true) || is_a($name, Stringable::class, true);
    }
}
