<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Tests\Fixtures;

use Pollora\Attributes\BlockBinding;
use Pollora\Attributes\BlockBinding\BindingField;

#[BlockBinding('acme/slow', label: 'Slow')]
final class SlowBinding
{
    #[BindingField]
    public function late(): string
    {
        usleep(60_000);

        return 'late';
    }

    #[BindingField]
    public function quick(): string
    {
        return 'quick';
    }
}
