<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Tests\Fixtures;

use Pollora\Attributes\Meta;
use Pollora\Attributes\TermMeta;

/**
 * Term meta that REST does not expose.
 */
#[TermMeta('venue')]
class VenueSecrets
{
    #[Meta]
    public ?string $door_code = null;
}
