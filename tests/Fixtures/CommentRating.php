<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Tests\Fixtures;

use Pollora\Attributes\CommentMeta;
use Pollora\Attributes\Meta;

#[CommentMeta]
class CommentRating
{
    #[Meta(showInRest: true)]
    public ?int $rating = null;
}
