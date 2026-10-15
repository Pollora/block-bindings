<?php

declare(strict_types=1);

if (! class_exists('WP_Block')) {
    eval('class WP_Block {}');
}

/**
 * A block as WordPress hands it to a source: its context, its name and the
 * sources of its attributes.
 *
 * @param  array<string, mixed>  $context
 * @param  array<string, array<string, mixed>>  $attributes
 */
function boundBlock(array $context = [], array $attributes = [], string $name = 'core/paragraph'): WP_Block
{
    $block = new #[AllowDynamicProperties] class extends WP_Block
    {
        public $context = [];

        public $name;

        public $block_type;
    };
    $block->context = $context;
    $block->name = $name;
    $block->block_type = (object) ['attributes' => $attributes];

    return $block;
}

/**
 * A paragraph, whose content WordPress writes as HTML.
 *
 * @param  array<string, mixed>  $context
 */
function boundParagraph(array $context = ['postId' => 7, 'postType' => 'event']): WP_Block
{
    return boundBlock($context, ['content' => ['type' => 'rich-text', 'source' => 'rich-text', 'selector' => 'p']]);
}
