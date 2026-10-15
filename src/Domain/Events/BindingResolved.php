<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Domain\Events;

/**
 * A block binding gave a value to a block attribute.
 *
 * For debugging tools: which source answered which attribute, on which post,
 * and what it cost. Dispatched only when something listens, so a site
 * without a listener pays nothing for it.
 */
final readonly class BindingResolved
{
    /**
     * @param  string  $source  The source's name (`acme/reading-time`)
     * @param  string  $field  The field asked for, empty for an invokable source
     * @param  string  $attribute  The block attribute it fills
     * @param  int|null  $postId  The post the block was rendered for
     * @param  float  $milliseconds  Time spent in the source, 0 when the value came from this request's cache
     * @param  bool  $cached  Whether the same binding had already been resolved in this request
     * @param  bool  $hasValue  Whether the source returned a value (false: the block keeps its own)
     */
    public function __construct(
        public string $source,
        public string $field,
        public string $attribute,
        public ?int $postId,
        public float $milliseconds,
        public bool $cached,
        public bool $hasValue,
    ) {}
}
