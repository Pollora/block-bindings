<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Tests\Fixtures;

use Stringable;

/**
 * Markup a source gives as HTML: a Stringable with a toHtml() method, as Laravel's HtmlString is.
 */
final readonly class Html implements Stringable
{
    public function __construct(private string $markup) {}

    public function toHtml(): string
    {
        return $this->markup;
    }

    public function __toString(): string
    {
        return $this->markup;
    }
}
