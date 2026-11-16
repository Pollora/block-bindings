<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Tests\Fixtures;

use Pollora\Attributes\Meta;
use Pollora\Attributes\PostType;

/**
 * A second schema on the same post type as {@see Concert}: a structured meta, then a labelled one.
 */
#[PostType('concert')]
class ConcertExtras
{
    #[Meta(showInRest: true)]
    public Lineup $lineup;

    #[Meta(showInRest: true, label: 'Name of the tour')]
    public string $tourName = '';
}
