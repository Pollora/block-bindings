<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Tests\Fixtures;

use Pollora\Attributes\Meta;
use Pollora\Attributes\UserMeta;

#[UserMeta]
class ArtistProfile
{
    #[Meta(public: true)]
    public ?string $stageName = null;

    #[Meta(showInRest: true)]
    public ?string $phone = null;
}
