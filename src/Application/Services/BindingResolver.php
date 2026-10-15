<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Application\Services;

use Pollora\BlockBinding\Domain\Contracts\BindingEventsInterface;
use Pollora\BlockBinding\Domain\Contracts\ContentVisibilityInterface;
use Pollora\BlockBinding\Domain\Contracts\MethodInvokerInterface;
use Pollora\BlockBinding\Domain\Contracts\PostFinderInterface;
use Pollora\BlockBinding\Domain\Contracts\ValuePresenterInterface;
use Pollora\BlockBinding\Domain\Enums\BindingFieldType;
use Pollora\BlockBinding\Domain\Events\BindingResolved;
use Pollora\BlockBinding\Domain\Models\BindingContext;
use Pollora\BlockBinding\Domain\Models\BindingFieldDefinition;
use Pollora\BlockBinding\Domain\Models\BindingSource;
use Pollora\BlockBinding\Infrastructure\Adapters\ReflectionMethodInvoker;
use Pollora\Meta\Application\Services\MetaAccessor;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;

/**
 * Answers WordPress for every bound attribute of every Pollora source.
 *
 * The checks live here, so that no source can forget them: the post or term of
 * the block must be visible to the visitor, and the value is escaped for the
 * place it lands in — text in HTML is escaped, a URL sanitized, an `HtmlString`
 * filtered like post content; an attribute a template prints (a Blade block's)
 * is left for the template to escape, like any other attribute.
 *
 * A field that throws reads as null (the block keeps its own content) and is
 * logged; in debug mode the exception is thrown, so it shows during development.
 * In debug mode too, a field slower than {@see self::SLOW_FIELD_MS} is logged,
 * with its source and its post, since every bound block of a page waits for it.
 */
final class BindingResolver
{
    /**
     * Attribute sources whose value WordPress writes as HTML.
     */
    private const array HTML_SOURCES = ['html', 'rich-text'];

    /**
     * Milliseconds past which a field is logged as slow, in debug mode.
     */
    public const int SLOW_FIELD_MS = 50;

    /**
     * @var array<string, mixed> Values already resolved in this request
     */
    private array $resolved = [];

    /**
     * @var array<class-string, object> Source instances, made on first use
     */
    private array $instances = [];

    private readonly MethodInvokerInterface $invoker;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ContentVisibilityInterface $visibility,
        private readonly ValuePresenterInterface $presenter,
        private readonly ?MetaAccessor $metaAccessor = null,
        private readonly bool $debug = false,
        private readonly ?LoggerInterface $logger = null,
        ?MethodInvokerInterface $invoker = null,
        private readonly ?BindingEventsInterface $events = null,
        private readonly ?PostFinderInterface $posts = null,
    ) {
        $this->invoker = $invoker ?? new ReflectionMethodInvoker($container);
    }

    /**
     * The value of a bound attribute, or null to keep what the block holds.
     *
     * @param  array<string, mixed>  $args  Arguments of the binding
     */
    public function resolve(BindingSource $source, array $args, ?\WP_Block $block, string $attribute): mixed
    {
        $attributeSource = $block?->block_type?->attributes[$attribute]['source'] ?? null;

        return $this->resolveIn($source, $args, $block instanceof \WP_Block ? $block->context : [], $block, $attribute, is_string($attributeSource) ? $attributeSource : null);
    }

    /**
     * The value the editor previews for a bound attribute: the same checks, the
     * same field and the same escaping, from the block context the editor sends.
     *
     * @param  array<string, mixed>  $args  Arguments of the binding
     * @param  array<string, mixed>  $blockContext  `postId`, `postType`, `termId`, `taxonomy`
     * @param  string|null  $attributeSource  The `source` of the attribute in the block type (`rich-text`, `attribute`…)
     */
    public function preview(BindingSource $source, array $args, array $blockContext, string $attribute, ?string $attributeSource = null): mixed
    {
        $value = $this->resolveIn($source, $args, $blockContext, null, $attribute, $attributeSource);

        return is_bool($value) ? $this->presenter->boolean($value) : $value;
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $blockContext
     */
    private function resolveIn(BindingSource $source, array $args, array $blockContext, ?\WP_Block $block, string $attribute, ?string $attributeSource = null): mixed
    {
        $context = $this->context($args, $blockContext, $block, $attribute);

        if (! $this->isVisible($context) || ! $source->answersFor($context->postType)) {
            return null;
        }

        $field = $source->isInvokable()
            ? new BindingFieldDefinition('', '', BindingFieldType::Text, '__invoke')
            : $source->field(is_string($args['field'] ?? null) ? $args['field'] : '');

        if (! $field instanceof BindingFieldDefinition) {
            return null;
        }

        $key = $this->cacheKey($source, $context, $attributeSource);

        if (array_key_exists($key, $this->resolved)) {
            $this->announce($source, $field, $context, 0.0, true, $this->resolved[$key] !== null);

            return $this->resolved[$key];
        }

        $start = hrtime(true);
        $value = $this->call($source, $field, $context);
        $milliseconds = (hrtime(true) - $start) / 1_000_000;

        $this->resolved[$key] = $this->present($value, $field->type, $attributeSource);
        $this->announce($source, $field, $context, $milliseconds, false, $this->resolved[$key] !== null);

        return $this->resolved[$key];
    }

    /**
     * Tell debugging tools what was resolved, when one is listening.
     */
    private function announce(BindingSource $source, BindingFieldDefinition $field, BindingContext $context, float $milliseconds, bool $cached, bool $hasValue): void
    {
        if (! $this->events instanceof BindingEventsInterface || ! $this->events->isListening()) {
            return;
        }

        $this->events->dispatch(new BindingResolved(
            source: $source->name,
            field: $field->name,
            attribute: $context->attribute,
            postId: $context->postId,
            milliseconds: round($milliseconds, 3),
            cached: $cached,
            hasValue: $hasValue,
        ));
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $blockContext
     */
    private function context(array $args, array $blockContext, ?\WP_Block $block, string $attribute): BindingContext
    {
        return new BindingContext(
            args: $args,
            attribute: $attribute,
            postId: $this->positiveInt($blockContext['postId'] ?? null),
            postType: is_string($blockContext['postType'] ?? null) ? $blockContext['postType'] : null,
            termId: $this->positiveInt($blockContext['termId'] ?? null),
            taxonomy: is_string($blockContext['taxonomy'] ?? null) ? $blockContext['taxonomy'] : null,
            block: $block,
            metaAccessor: $this->metaAccessor,
            postFinder: $this->posts,
        );
    }

    private function isVisible(BindingContext $context): bool
    {
        if ($context->postId !== null && ! $this->visibility->canShowPost($context->postId)) {
            return false;
        }

        return $context->termId === null || $context->taxonomy === null || $this->visibility->canShowTerm($context->termId, $context->taxonomy);
    }

    private function call(BindingSource $source, BindingFieldDefinition $field, BindingContext $context): mixed
    {
        try {
            $instance = $this->instances[$source->class] ??= $this->container->get($source->class);

            if (! $this->debug) {
                return $this->invoker->call($instance, $field->method, [BindingContext::class => $context, 'context' => $context]);
            }

            $start = hrtime(true);
            $value = $this->invoker->call($instance, $field->method, [BindingContext::class => $context, 'context' => $context]);
            $this->reportSlow($source, $field, $context, (hrtime(true) - $start) / 1_000_000);

            return $value;
        } catch (Throwable $throwable) {
            if ($this->debug) {
                throw $throwable;
            }

            $this->logger?->error(sprintf(
                'The block binding "%s" failed on %s: %s',
                $source->name,
                $context->postId === null ? 'no post' : 'post '.$context->postId,
                $throwable->getMessage()
            ), ['exception' => $throwable]);

            return null;
        }
    }

    private function reportSlow(BindingSource $source, BindingFieldDefinition $field, BindingContext $context, float $milliseconds): void
    {
        if ($milliseconds <= self::SLOW_FIELD_MS) {
            return;
        }

        $this->logger?->warning(sprintf(
            'The block binding "%s"%s took %d ms on %s',
            $source->name,
            $field->name === '' ? '' : sprintf(' (field "%s")', $field->name),
            (int) round($milliseconds),
            $context->postId === null ? 'no post' : 'post '.$context->postId,
        ), [
            'source' => $source->name,
            'field' => $field->name,
            'post' => $context->postId,
            'milliseconds' => round($milliseconds, 1),
        ]);
    }

    private function present(mixed $value, BindingFieldType $type, ?string $attributeSource): mixed
    {
        if ($value === null) {
            return null;
        }

        $inHtml = in_array($attributeSource, self::HTML_SOURCES, true);

        if (is_object($value) && method_exists($value, 'toHtml')) {
            return $this->presenter->richText((string) $value->toHtml());
        }

        if (is_bool($value)) {
            return $inHtml ? $this->presenter->html($this->presenter->boolean($value)) : $value;
        }

        if ((is_int($value) || is_float($value)) && ! $inHtml && ! $type->isUrl()) {
            return $value;
        }

        $string = $value instanceof Stringable || is_scalar($value) ? (string) $value : '';

        return match (true) {
            $type->isUrl() => $this->presenter->url($string),
            $inHtml => $this->presenter->html($string),
            default => $string,
        };
    }

    private function cacheKey(BindingSource $source, BindingContext $context, ?string $attributeSource): string
    {
        return implode('|', [
            $source->name,
            $context->attribute,
            (string) $attributeSource,
            (string) $context->postId,
            (string) $context->termId,
            (string) $context->block?->name,
            serialize($context->args),
        ]);
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
