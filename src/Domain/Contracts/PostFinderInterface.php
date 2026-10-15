<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Domain\Contracts;

use Pollora\Colt\Model\Post;

/**
 * Loads the post of a block context as a model, for `BindingContext::post()`.
 * In a Pollora application it is the framework's `Pollora\Models\Post` (the
 * class bound to the post type, with its typed meta accessors); without it,
 * Colt's `Post`.
 */
interface PostFinderInterface
{
    public function find(int $postId): ?Post;
}
