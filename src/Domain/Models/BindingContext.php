<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Domain\Models;

use LogicException;
use Pollora\BlockBinding\Domain\Contracts\PostFinderInterface;
use Pollora\Colt\Model\Post;
use Pollora\Meta\Application\Services\MetaAccessor;
use Pollora\Meta\Domain\Enums\MetaObjectType;
use Pollora\Meta\Domain\Models\MetaRecord;

/**
 * What a binding field knows about the block it fills: the post or term of the
 * block context (right inside a query loop), the arguments of the binding and
 * the bound attribute.
 */
final class BindingContext
{
    private Post|false|null $post = null;

    /**
     * @param  array<string, mixed>  $args  Arguments of the binding, `field` included
     * @param  string  $attribute  The bound attribute: `content`, `url`, `alt`…
     * @param  int|null  $postId  Post of the block context
     * @param  string|null  $postType  Post type of the block context
     * @param  int|null  $termId  Term of the block context
     * @param  string|null  $taxonomy  Taxonomy of the block context
     * @param  \WP_Block|null  $block  The block, for advanced cases
     */
    public function __construct(
        public readonly array $args = [],
        public readonly string $attribute = '',
        public readonly ?int $postId = null,
        public readonly ?string $postType = null,
        public readonly ?int $termId = null,
        public readonly ?string $taxonomy = null,
        public readonly ?\WP_Block $block = null,
        private readonly ?MetaAccessor $metaAccessor = null,
        private readonly ?PostFinderInterface $postFinder = null,
    ) {}

    /**
     * An argument of the binding: `"args": {"field": "date", "format": "j F Y"}`.
     */
    public function arg(string $name, mixed $default = null): mixed
    {
        return $this->args[$name] ?? $default;
    }

    /**
     * The post of the block context, as a model (the class bound to its post type, when
     * there is one; in a Pollora application, the framework's Post model).
     */
    public function post(): ?Post
    {
        $this->post ??= $this->postId === null ? false : (($this->postFinder instanceof PostFinderInterface ? $this->postFinder->find($this->postId) : Post::query()->find($this->postId)) ?? false);

        return $this->post ?: null;
    }

    /**
     * The typed meta a class declares, on the object of the block context that
     * carries them: the post, the term, the post's author.
     *
     * @param  class-string  $class  A class declaring `#[Meta]` properties
     */
    public function meta(string $class): ?MetaRecord
    {
        $accessor = $this->metaAccessor ?? throw new LogicException('Typed meta are not available.');
        $objectId = $this->objectIdFor($accessor->schema($class)->objectType);

        return $objectId === null ? null : $accessor->of($class, $objectId);
    }

    private function objectIdFor(MetaObjectType $objectType): ?int
    {
        return match ($objectType) {
            MetaObjectType::Post => $this->postId,
            MetaObjectType::Term => $this->termId,
            MetaObjectType::User => $this->authorId(),
            MetaObjectType::Comment => isset($this->block?->context['commentId']) ? (int) $this->block->context['commentId'] : null,
        };
    }

    private function authorId(): ?int
    {
        $author = (int) $this->post()?->getAttribute('post_author');

        return $author > 0 ? $author : null;
    }
}
