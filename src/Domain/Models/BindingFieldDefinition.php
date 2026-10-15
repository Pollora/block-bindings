<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Domain\Models;

use Pollora\BlockBinding\Domain\Enums\BindingFieldType;

/**
 * One field of a binding source, as declared by a `#[BindingField]` method.
 */
final readonly class BindingFieldDefinition
{
    /**
     * @param  string  $name  Value of the `field` argument
     * @param  string  $label  Label shown in the editor
     * @param  BindingFieldType  $type  What the field gives
     * @param  string  $method  The method answering for the field
     */
    public function __construct(
        public string $name,
        public string $label,
        public BindingFieldType $type,
        public string $method,
    ) {}
}
