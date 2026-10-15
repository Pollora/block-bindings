<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Infrastructure\Sources;

use Pollora\Attributes\BlockBinding;
use Pollora\BlockBinding\Domain\Models\BindingContext;

/**
 * `pollora/option`: a site option, `"args": {"name": "blogdescription"}`.
 *
 * Reads only the options listed in `block-bindings.options`, none by default:
 * an option can hold anything, and anyone who edits a post could bind it.
 */
#[BlockBinding('pollora/option', label: 'Site option (Pollora)', usesContext: [])]
final readonly class OptionSource
{
    /**
     * @param  list<string>  $options  The options a project lists as bindable (`block-bindings.options`)
     */
    public function __construct(
        private array $options,
    ) {}

    public function __invoke(BindingContext $context): ?string
    {
        $name = $context->arg('name');
        $fallback = $context->arg('fallback');
        $fallback = is_scalar($fallback) ? (string) $fallback : null;

        if (! is_string($name) || ! in_array($name, $this->options, true)) {
            return null;
        }

        $value = \get_option($name, null);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : $fallback;
    }
}
