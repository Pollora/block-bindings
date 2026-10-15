<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Domain\Contracts;

/**
 * Turns a bound value into what the page shows, escaped for the place it
 * lands in.
 */
interface ValuePresenterInterface
{
    /** Text placed in HTML. */
    public function html(string $value): string;

    /** HTML a field returned on purpose, filtered to what a post may contain. */
    public function richText(string $value): string;

    /** A URL, sanitized; whatever writes it into the page escapes it there. */
    public function url(string $value): string;

    /** A boolean as a word: the labels given, or "Yes" and "No" translated. */
    public function boolean(bool $value, ?string $true = null, ?string $false = null): string;
}
