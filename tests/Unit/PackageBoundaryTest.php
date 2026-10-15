<?php

declare(strict_types=1);

// pollora/block-bindings runs with or without Laravel: nothing in it may reach Illuminate or the framework.
arch('the package does not use Laravel')
    ->expect('Pollora\BlockBinding')
    ->not->toUse(['Illuminate', 'Laravel', 'Pollora\Support', 'Pollora\Models', 'Pollora\Doctor']);

arch('the domain stays free of WordPress adapters and Discovery')
    ->expect('Pollora\BlockBinding\Domain')
    ->not->toUse(['Pollora\Discovery', 'Pollora\Hook', 'Pollora\BlockBinding\Infrastructure']);
