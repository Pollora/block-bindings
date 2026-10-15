<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Infrastructure\Services;

use BackedEnum;
use DateTimeInterface;
use Pollora\BlockBinding\Domain\Contracts\ValuePresenterInterface;
use Pollora\Meta\Domain\Enums\MetaValueType;
use Pollora\Meta\Domain\Models\MetaDefinition;

/**
 * Shows a typed meta the way its type calls for, which `core/post-meta` does
 * not: a date in the site's format and language, a number with the site's
 * separators, a boolean as a word, an enum by its label, an attachment by its
 * URL, alternative text or caption depending on the bound attribute.
 *
 * Arguments of the binding: `format` (a PHP date format, or `raw` for the value
 * as stored), `decimals`, `true` and `false` (boolean labels), `size` (image
 * size), `fallback` (shown when the meta is empty).
 */
final readonly class BindingFormatter
{
    public function __construct(
        private ValuePresenterInterface $presenter,
    ) {}

    /**
     * @param  array<string, mixed>  $args  Arguments of the binding
     */
    public function format(MetaDefinition $definition, mixed $value, array $args, string $attribute): string|int|float|bool|null
    {
        if (in_array($value, [null, '', []], true)) {
            return $this->fallback($args);
        }

        if (($args['format'] ?? null) === 'raw') {
            return match (true) {
                is_scalar($value) => $value,
                $value instanceof BackedEnum => $value->value,
                $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
                default => null,
            };
        }

        if ($definition->media && is_int($value)) {
            return $this->media($value, $args, $attribute) ?? $this->fallback($args);
        }

        return match ($definition->valueType) {
            MetaValueType::ArrayOf => $this->list($definition, (array) $value, $args, $attribute),
            MetaValueType::DataObject => null,
            default => $this->scalar($definition, $value, $args),
        };
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function scalar(MetaDefinition $definition, mixed $value, array $args): ?string
    {
        return match (true) {
            is_bool($value) => $this->presenter->boolean($value, $this->stringArg($args, 'true'), $this->stringArg($args, 'false')),
            is_int($value), is_float($value) => \number_format_i18n($value, $this->decimals($definition, $value, $args)),
            $value instanceof DateTimeInterface => \wp_date($this->stringArg($args, 'format') ?? (string) \get_option('date_format'), $value->getTimestamp()) ?: null,
            $value instanceof BackedEnum => method_exists($value, 'label') ? (string) $value->label() : (string) $value->value,
            is_string($value) => $value,
            default => null,
        };
    }

    /**
     * The items of an array, each formatted, as a sentence: "rock, jazz and blues".
     *
     * @param  array<array-key, mixed>  $values
     * @param  array<string, mixed>  $args
     */
    private function list(MetaDefinition $definition, array $values, array $args, string $attribute): ?string
    {
        $items = [];

        foreach ($values as $value) {
            $item = $value === null ? null : $this->format($definition->item(), $value, [...$args, 'fallback' => null], $attribute);

            if ($item !== null && $item !== '') {
                $items[] = (string) $item;
            }
        }

        return $items === [] ? $this->fallback($args) : \wp_sprintf('%l', $items);
    }

    /**
     * An attachment, as the bound attribute needs it.
     *
     * @param  array<string, mixed>  $args
     */
    private function media(int $attachmentId, array $args, string $attribute): string|int|null
    {
        if (\get_post_type($attachmentId) !== 'attachment') {
            return null;
        }

        $value = match ($attribute) {
            'id' => $attachmentId,
            'alt' => (string) \get_post_meta($attachmentId, '_wp_attachment_image_alt', true),
            'title' => \get_the_title($attachmentId),
            'caption' => (string) \wp_get_attachment_caption($attachmentId),
            default => $this->presenter->url((string) (\wp_get_attachment_image_url($attachmentId, $this->stringArg($args, 'size') ?? 'full') ?: \wp_get_attachment_url($attachmentId))),
        };

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function decimals(MetaDefinition $definition, int|float $value, array $args): int
    {
        if (is_numeric($args['decimals'] ?? null)) {
            return max(0, (int) $args['decimals']);
        }

        if ($definition->valueType === MetaValueType::Integer || is_int($value)) {
            return 0;
        }

        $fraction = strrchr((string) $value, '.');

        return $fraction === false ? 0 : strlen($fraction) - 1;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function fallback(array $args): ?string
    {
        return $this->stringArg($args, 'fallback');
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function stringArg(array $args, string $name): ?string
    {
        return is_scalar($args[$name] ?? null) ? (string) $args[$name] : null;
    }
}
