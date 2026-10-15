<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Carbon\CarbonImmutable;
use Pollora\BlockBinding\Infrastructure\Services\BindingFormatter;
use Pollora\BlockBinding\Tests\Fixtures\Concert;
use Pollora\BlockBinding\Tests\Fixtures\ConcertStatus;
use Pollora\BlockBinding\Tests\Fixtures\FakePresenter;
use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Domain\Models\MetaDefinition;

function concertMeta(string $property): MetaDefinition
{
    return (new MetaSchemaBuilder)->build(Concert::class)->definitions[$property];
}

/**
 * @param  array<string, mixed>  $args
 */
function formatConcert(string $property, mixed $value, array $args = [], string $attribute = 'content'): mixed
{
    return (new BindingFormatter(new FakePresenter))->format(concertMeta($property), $value, $args, $attribute);
}

beforeEach(function (): void {
    Functions\when('number_format_i18n')->alias(fn (float|int $number, int $decimals = 0): string => number_format((float) $number, $decimals, ',', "\u{202F}"));
    Functions\when('wp_date')->alias(fn (string $format, int $timestamp): string => gmdate($format, $timestamp));
    Functions\when('get_option')->alias(fn (string $option, mixed $default = false): mixed => $option === 'date_format' ? 'd/m/Y' : $default);
    Functions\when('wp_sprintf')->alias(fn (string $pattern, array $items): string => implode(', ', $items));
});

it('formats a number with the site separators and its own decimals', function (): void {
    expect(formatConcert('capacity', 1200))->toBe("1\u{202F}200")
        ->and(formatConcert('price', 12.5))->toBe('12,5')
        ->and(formatConcert('price', 12.5, ['decimals' => 2]))->toBe('12,50');
});

it('formats a date in the site format, or the one given', function (): void {
    $date = CarbonImmutable::parse('2026-11-14 09:00:00', 'UTC');

    expect(formatConcert('startsAt', $date))->toBe('14/11/2026')
        ->and(formatConcert('startsAt', $date, ['format' => 'Y-m-d H:i']))->toBe('2026-11-14 09:00');
});

it('words a boolean, with the labels given', function (): void {
    expect(formatConcert('soldOut', true))->toBe('Yes')
        ->and(formatConcert('soldOut', false, ['true' => 'Complet', 'false' => 'Places disponibles']))->toBe('Places disponibles');
});

it('shows an enum by its label', function (): void {
    expect(formatConcert('status', ConcertStatus::Cancelled))->toBe('Cancelled')
        ->and(formatConcert('status', ConcertStatus::Announced))->toBe('Coming soon');
});

it('lists the items of an array', function (): void {
    expect(formatConcert('genres', ['rock', 'jazz']))->toBe('rock, jazz');
});

it('gives the raw value with format: raw', function (): void {
    expect(formatConcert('capacity', 1200, ['format' => 'raw']))->toBe(1200)
        ->and(formatConcert('status', ConcertStatus::Cancelled, ['format' => 'raw']))->toBe('cancelled')
        ->and(formatConcert('startsAt', CarbonImmutable::parse('2026-11-14 09:00:00', 'UTC'), ['format' => 'raw']))->toBe('2026-11-14T09:00:00+00:00');
});

it('shows the fallback for an empty meta, or keeps the block content', function (): void {
    expect(formatConcert('startsAt', null, ['fallback' => 'Date to come']))->toBe('Date to come')
        ->and(formatConcert('genres', []))->toBeNull();
});

it('gives an attachment as the bound attribute needs it', function (): void {
    Functions\when('get_post_type')->justReturn('attachment');
    Functions\when('wp_get_attachment_image_url')->alias(fn (int $id, string $size): string => "https://example.test/cover-{$size}.jpg");
    Functions\when('get_post_meta')->alias(fn (int $id, string $key): string => $key === '_wp_attachment_image_alt' ? 'The stage' : '');
    Functions\when('wp_get_attachment_caption')->justReturn('');

    expect(formatConcert('coverImageId', 31, ['size' => 'large'], 'url'))->toBe('url(https://example.test/cover-large.jpg)')
        ->and(formatConcert('coverImageId', 31, [], 'url'))->toBe('url(https://example.test/cover-full.jpg)')
        ->and(formatConcert('coverImageId', 31, [], 'alt'))->toBe('The stage')
        ->and(formatConcert('coverImageId', 31, [], 'id'))->toBe(31)
        ->and(formatConcert('coverImageId', 31, [], 'caption'))->toBeNull();
});

it('gives nothing for an ID that is not an attachment', function (): void {
    Functions\when('get_post_type')->justReturn('post');

    expect(formatConcert('coverImageId', 31, [], 'url'))->toBeNull();
});
