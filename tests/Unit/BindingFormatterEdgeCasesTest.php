<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Carbon\CarbonImmutable;
use Pollora\BlockBinding\Infrastructure\Services\BindingFormatter;
use Pollora\BlockBinding\Tests\Fixtures\Concert;
use Pollora\BlockBinding\Tests\Fixtures\FakePresenter;
use Pollora\Meta\Application\Services\MetaSchemaBuilder;

enum EdgeCountedStatus: int
{
    case First = 1;
}

enum EdgeNumberedLabel: string
{
    case Open = 'open';

    public function label(): int
    {
        return 42;
    }
}

beforeEach(function (): void {
    $this->format = fn (string $property, mixed $value, array $args = [], string $attribute = 'content'): mixed => (new BindingFormatter(new FakePresenter))
        ->format((new MetaSchemaBuilder)->build(Concert::class)->definitions[$property], $value, $args, $attribute);

    Functions\when('number_format_i18n')->alias(fn (float|int $number, int $decimals = 0): string => number_format((float) $number, $decimals, ',', ' '));
    Functions\when('wp_date')->alias(fn (string $format, int $timestamp): string => gmdate($format, $timestamp));
    Functions\when('get_option')->alias(fn (string $option, mixed $default = false): mixed => $option === 'date_format' ? 'd/m/Y' : $default);
    Functions\when('wp_sprintf')->alias(fn (string $pattern, array $items): string => implode(', ', $items));
});

it('treats an empty string and an empty array as an empty meta, showing the fallback', function (mixed $empty): void {
    expect(($this->format)('genres', $empty, ['fallback' => 'None']))->toBe('None');
})->with(['null' => [null], 'empty string' => [''], 'empty array' => [[]]]);

it('shows the fallback for an empty string or array whatever the type of the meta', function (string $property, mixed $empty): void {
    expect(($this->format)($property, $empty, ['fallback' => 'None']))->toBe('None');
})->with([
    'a date given as an empty string' => ['startsAt', ''],
    'a number given as an empty string' => ['capacity', ''],
    'a number given as an empty array' => ['capacity', []],
    'a date given as an empty array' => ['startsAt', []],
]);

it('does not take zero, false or a blank for an empty meta', function (): void {
    expect(($this->format)('capacity', 0, ['fallback' => 'None']))->toBe('0')
        ->and(($this->format)('soldOut', false, ['fallback' => 'None']))->toBe('No');
});

it('gives nothing for a raw value it cannot reduce to a scalar', function (): void {
    expect(($this->format)('capacity', new stdClass, ['format' => 'raw']))->toBeNull()
        ->and(($this->format)('genres', ['rock'], ['format' => 'raw']))->toBeNull();
});

it('lists a single value as a one-item list', function (): void {
    expect(($this->format)('genres', 'rock'))->toBe('rock');
});

it('formats the items of a list with the arguments of the binding', function (): void {
    expect(($this->format)('genres', [1.5, 2.25], ['decimals' => 2]))->toBe('1,50, 2,25')
        ->and(($this->format)('genres', [1.5, 2.25]))->toBe('1,5, 2,25');
});

it('skips the empty items of a list instead of showing the fallback for them', function (): void {
    expect(($this->format)('genres', ['rock', '', null, 'jazz'], ['fallback' => 'None']))->toBe('rock, jazz')
        ->and(($this->format)('genres', ['', null], ['fallback' => 'None']))->toBe('None')
        ->and(($this->format)('genres', ['', null]))->toBeNull();
});

it('gives nothing for a date when the site has no date format', function (): void {
    Functions\when('get_option')->justReturn(false);

    expect(($this->format)('startsAt', CarbonImmutable::parse('2026-11-14 09:00:00', 'UTC')))->toBeNull();
});

it('shows an enum by its label or its value, as text', function (): void {
    expect(($this->format)('status', EdgeCountedStatus::First))->toBe('1')
        ->and(($this->format)('status', EdgeNumberedLabel::Open))->toBe('42');
});

it('shows a number with the decimals asked, at least none', function (mixed $decimals, string $expected): void {
    expect(($this->format)('price', 1234.5, ['decimals' => $decimals]))->toBe($expected);
})->with([
    'a number' => [2, '1 234,50'],
    'a numeric string' => ['2', '1 234,50'],
    'zero' => [0, '1 235'],
    'negative' => [-1, '1 235'],
    'one' => [1, '1 234,5'],
]);

it('shows an integer meta without decimals, even when given a float', function (): void {
    expect(($this->format)('capacity', 12.5))->toBe('13')
        ->and(($this->format)('capacity', 1234))->toBe('1 234');
});

it('counts the decimals a float meta carries', function (): void {
    expect(($this->format)('price', 12.0))->toBe('12')
        ->and(($this->format)('price', 12))->toBe('12')
        ->and(($this->format)('price', 1234.0))->toBe('1 234')
        ->and(($this->format)('price', 12.25))->toBe('12,25')
        ->and(($this->format)('price', 3.5))->toBe('3,5');
});

it('shows the fallback given as a number', function (): void {
    expect(($this->format)('startsAt', null, ['fallback' => 0]))->toBe('0')
        ->and(($this->format)('startsAt', null, ['fallback' => ['x']]))->toBeNull();
});

describe('attachments', function (): void {
    beforeEach(function (): void {
        Functions\when('get_post_type')->justReturn('attachment');
        Functions\when('get_the_title')->justReturn('Cover title');
    });

    it('reads the alternative text of the attachment, a single value', function (): void {
        Functions\when('get_post_meta')->alias(fn (int $id, string $key, bool $single = false): string|array => $key === '_wp_attachment_image_alt' && $single ? 'The stage' : ['The stage']);

        expect(($this->format)('coverImageId', 31, [], 'alt'))->toBe('The stage');
    });

    it('gives nothing, rather than false, for what the attachment lacks', function (): void {
        Functions\when('get_post_meta')->justReturn(false);
        Functions\when('wp_get_attachment_caption')->justReturn(false);
        Functions\when('wp_get_attachment_image_url')->justReturn(false);
        Functions\when('wp_get_attachment_url')->justReturn(false);

        expect(($this->format)('coverImageId', 31, [], 'alt'))->toBeNull()
            ->and(($this->format)('coverImageId', 31, [], 'caption'))->toBeNull()
            ->and(($this->format)('coverImageId', 31, ['fallback' => 'No cover'], 'caption'))->toBe('No cover')
            ->and(($this->format)('coverImageId', 31, [], 'url'))->toBe('url()');
    });

    it('gives the caption and the title', function (): void {
        Functions\when('wp_get_attachment_caption')->justReturn('Live at night');

        expect(($this->format)('coverImageId', 31, [], 'caption'))->toBe('Live at night')
            ->and(($this->format)('coverImageId', 31, [], 'title'))->toBe('Cover title');
    });

    it('falls back on the attachment file when no image size can be made', function (): void {
        Functions\when('wp_get_attachment_image_url')->justReturn(false);
        Functions\when('wp_get_attachment_url')->justReturn('https://example.test/cover.pdf');

        expect(($this->format)('coverImageId', 31, [], 'url'))->toBe('url(https://example.test/cover.pdf)');
    });
});
