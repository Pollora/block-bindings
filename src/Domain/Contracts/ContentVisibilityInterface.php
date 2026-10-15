<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Domain\Contracts;

/**
 * Whether the current visitor may see a content, so that a bound block never
 * shows what the content itself would hide.
 */
interface ContentVisibilityInterface
{
    /**
     * A post publicly viewable or readable by the current user, and not waiting
     * for its password.
     */
    public function canShowPost(int $postId): bool;

    /**
     * A term of a publicly queryable taxonomy, or a logged-in user who can read.
     */
    public function canShowTerm(int $termId, string $taxonomy): bool;
}
