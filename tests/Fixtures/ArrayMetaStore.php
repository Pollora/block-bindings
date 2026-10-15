<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Tests\Fixtures;

use Pollora\Meta\Domain\Contracts\MetaStoreInterface;
use Pollora\Meta\Domain\Enums\MetaObjectType;

/**
 * Meta rows in memory, by object type, object ID and key.
 */
final class ArrayMetaStore implements MetaStoreInterface
{
    /**
     * @param  array<string, array<int, array<string, mixed>>>  $rows
     */
    public function __construct(public array $rows = []) {}

    public function get(MetaObjectType $objectType, int $objectId, string $key): mixed
    {
        return $this->rows[$objectType->value][$objectId][$key] ?? null;
    }

    public function getAll(MetaObjectType $objectType, int $objectId, string $key): array
    {
        return (array) ($this->rows[$objectType->value][$objectId][$key] ?? []);
    }

    public function update(MetaObjectType $objectType, int $objectId, string $key, string|array $value): void
    {
        $this->rows[$objectType->value][$objectId][$key] = $value;
    }

    public function replaceAll(MetaObjectType $objectType, int $objectId, string $key, array $values): void
    {
        $this->rows[$objectType->value][$objectId][$key] = $values;
    }

    public function delete(MetaObjectType $objectType, int $objectId, string $key): void
    {
        unset($this->rows[$objectType->value][$objectId][$key]);
    }
}
