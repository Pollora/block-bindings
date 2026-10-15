<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Infrastructure\Sources;

use Pollora\Attributes\BlockBinding;
use Pollora\BlockBinding\Domain\Contracts\ContentVisibilityInterface;
use Pollora\BlockBinding\Domain\Models\BindingContext;
use Pollora\BlockBinding\Infrastructure\Services\BindingFormatter;
use Pollora\Meta\Domain\Enums\MetaObjectType;

/**
 * `pollora/term-meta`: a typed meta of the term of the block, formatted by its
 * type. The term comes from the block context (a terms query) or, on a term
 * archive, from the queried term.
 *
 * Reads only the meta a `#[Meta]` declares with `showInRest: true` under a key
 * that is not protected.
 */
#[BlockBinding('pollora/term-meta', label: 'Term meta (Pollora)', usesContext: ['termId', 'taxonomy'])]
final readonly class TermMetaSource
{
    public function __construct(
        private TypedMetaReader $reader,
        private BindingFormatter $formatter,
        private ContentVisibilityInterface $visibility,
    ) {}

    public function __invoke(BindingContext $context): string|int|float|bool|null
    {
        $key = $context->arg('key');
        [$termId, $taxonomy] = $this->term($context);

        if (! is_string($key) || $key === '' || $termId === null || $taxonomy === null) {
            return null;
        }

        $meta = $this->reader->read(MetaObjectType::Term, $taxonomy, $termId, $key, TypedMetaReader::exposedInRest(...));

        return $meta === null ? null : $this->formatter->format($meta[0], $meta[1], $context->args, $context->attribute);
    }

    /**
     * @return array{0: int|null, 1: string|null}
     */
    private function term(BindingContext $context): array
    {
        if ($context->termId !== null && $context->taxonomy !== null) {
            return [$context->termId, $context->taxonomy];
        }

        $queried = \get_queried_object();

        if (! $queried instanceof \WP_Term || ! $this->visibility->canShowTerm($queried->term_id, $queried->taxonomy)) {
            return [null, null];
        }

        return [$queried->term_id, $queried->taxonomy];
    }
}
