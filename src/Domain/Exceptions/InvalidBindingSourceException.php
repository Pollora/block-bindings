<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Domain\Exceptions;

use InvalidArgumentException;

/**
 * A `#[BlockBinding]` class WordPress could not register, or would register
 * in a way that fails silently in a block.
 */
final class InvalidBindingSourceException extends InvalidArgumentException
{
    public static function forClass(string $class, string $reason): self
    {
        return new self(sprintf('The block binding source %s cannot be registered: %s', $class, $reason));
    }

    public static function forField(string $class, string $method, string $reason): self
    {
        return new self(sprintf('The block binding field %s::%s() cannot be registered: %s', $class, $method, $reason));
    }

    public static function duplicateName(string $name, string $existing, string $class): self
    {
        return new self(sprintf('The block binding source "%s" is declared twice, by %s and by %s.', $name, $existing, $class));
    }
}
