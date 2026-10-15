<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Infrastructure\Adapters;

use Pollora\BlockBinding\Domain\Contracts\ContentVisibilityInterface;

/**
 * The checks of WordPress's own `core/post-meta` and `core/term-data` sources.
 */
final readonly class WordPressContentVisibility implements ContentVisibilityInterface
{
    public function canShowPost(int $postId): bool
    {
        $post = \get_post($postId);

        if (! $post instanceof \WP_Post) {
            return false;
        }

        return (\is_post_publicly_viewable($post) || \current_user_can('read_post', $postId)) && ! \post_password_required($post);
    }

    public function canShowTerm(int $termId, string $taxonomy): bool
    {
        $term = \get_term($termId, $taxonomy);

        if (! $term instanceof \WP_Term) {
            return false;
        }

        $taxonomyObject = \get_taxonomy($taxonomy);

        return ($taxonomyObject instanceof \WP_Taxonomy && $taxonomyObject->publicly_queryable) || \current_user_can('read');
    }
}
