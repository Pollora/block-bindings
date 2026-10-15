<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Application\Services;

use Pollora\BlockBinding\Domain\Exceptions\InvalidBindingSourceException;
use Pollora\BlockBinding\Domain\Models\BindingSource;

/**
 * The binding sources of the project and of the framework, by name.
 */
final class BindingSourceRegistry
{
    /**
     * @var array<string, BindingSource>
     */
    private array $sources = [];

    /**
     * @throws InvalidBindingSourceException When another class already declares the name
     */
    public function add(BindingSource $source): void
    {
        $existing = $this->sources[$source->name] ?? null;

        if ($existing instanceof BindingSource && $existing->class !== $source->class) {
            throw InvalidBindingSourceException::duplicateName($source->name, $existing->class, $source->class);
        }

        $this->sources[$source->name] = $source;
    }

    public function find(string $name): ?BindingSource
    {
        return $this->sources[$name] ?? null;
    }

    /**
     * @return list<BindingSource>
     */
    public function all(): array
    {
        return array_values($this->sources);
    }
}
