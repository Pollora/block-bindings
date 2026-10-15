<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Tests\Fixtures;

use Pollora\BlockBinding\Domain\Contracts\ValuePresenterInterface;

/**
 * Marks what it did, so a test sees which escaping a value went through.
 */
final class FakePresenter implements ValuePresenterInterface
{
    public function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES);
    }

    public function richText(string $value): string
    {
        return 'kses('.$value.')';
    }

    public function url(string $value): string
    {
        return 'url('.$value.')';
    }

    public function boolean(bool $value, ?string $true = null, ?string $false = null): string
    {
        return $value ? ($true ?? 'Yes') : ($false ?? 'No');
    }
}
