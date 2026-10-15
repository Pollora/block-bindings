<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Tests\Fixtures;

use Pollora\Attributes\Meta;
use Pollora\Attributes\TermMeta;

#[TermMeta('venue')]
class VenueExtras
{
    #[Meta(showInRest: true)]
    public ?string $city = null;
}
