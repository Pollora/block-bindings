<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Tests\Fixtures;

use Pollora\Attributes\BlockBinding;
use Pollora\BlockBinding\Domain\Models\BindingContext;

#[BlockBinding('acme/echo', usesContext: [])]
final class InvokableBinding
{
    public function __invoke(BindingContext $context): ?string
    {
        return is_string($context->arg('say')) ? $context->arg('say') : null;
    }
}
