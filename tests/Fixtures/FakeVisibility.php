<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Tests\Fixtures;

use Pollora\BlockBinding\Domain\Contracts\ContentVisibilityInterface;

final class FakeVisibility implements ContentVisibilityInterface
{
    /**
     * @param  list<int>  $hiddenPosts
     * @param  list<int>  $hiddenTerms
     */
    public function __construct(public array $hiddenPosts = [], public array $hiddenTerms = []) {}

    public function canShowPost(int $postId): bool
    {
        return ! in_array($postId, $this->hiddenPosts, true);
    }

    public function canShowTerm(int $termId, string $taxonomy): bool
    {
        return ! in_array($termId, $this->hiddenTerms, true);
    }
}
