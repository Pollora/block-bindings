<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Infrastructure\Adapters;

use Pollora\BlockBinding\Application\Services\BindingResolver;
use Pollora\BlockBinding\Domain\Models\BindingSource;
use Pollora\Hook\Domain\Contract\Action;

/**
 * Registers binding sources with `register_block_bindings_source()` on `init`,
 * or right away when `init` has already run. Every source shares one callback,
 * which hands the bound attribute to the resolver.
 */
final readonly class WordPressBindingRegistry
{
    public function __construct(
        private Action $action,
        private BindingResolver $resolver,
    ) {}

    public function register(BindingSource $source): void
    {
        if (\did_action('init') > 0) {
            $this->registerNow($source);

            return;
        }

        $this->action->add('init', fn () => $this->registerNow($source));
    }

    private function registerNow(BindingSource $source): void
    {
        if (\get_block_bindings_source($source->name) !== null) {
            return;
        }

        \register_block_bindings_source($source->name, [
            'label' => $source->label,
            'uses_context' => $source->usesContext,
            'get_value_callback' => fn (array $args, \WP_Block $block, string $attribute): mixed => $this->resolver->resolve($source, $args, $block, $attribute),
        ]);
    }
}
