<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Infrastructure\Sources;

use Pollora\Attributes\BlockBinding;
use Pollora\BlockBinding\Domain\Models\BindingContext;
use Pollora\BlockBinding\Infrastructure\Services\BindingFormatter;
use Pollora\Meta\Domain\Enums\MetaObjectType;
use Pollora\Meta\Domain\Models\MetaDefinition;

/**
 * `pollora/author-meta`: a typed meta of the author of the post of the block,
 * formatted by its type.
 *
 * A user meta may be personal, so `showInRest` is not enough: only the meta a
 * `#[Meta(public: true)]` declares are read.
 */
#[BlockBinding('pollora/author-meta', label: 'Author meta (Pollora)', usesContext: ['postId'])]
final readonly class AuthorMetaSource
{
    public function __construct(
        private TypedMetaReader $reader,
        private BindingFormatter $formatter,
    ) {}

    public function __invoke(BindingContext $context): string|int|float|bool|null
    {
        $key = $context->arg('key');

        if (! is_string($key) || $key === '' || $context->postId === null) {
            return null;
        }

        $authorId = (int) \get_post_field('post_author', $context->postId);

        if ($authorId <= 0) {
            return null;
        }

        $meta = $this->reader->read(MetaObjectType::User, null, $authorId, $key, static fn (MetaDefinition $definition): bool => $definition->public);

        return $meta === null ? null : $this->formatter->format($meta[0], $meta[1], $context->args, $context->attribute);
    }
}
