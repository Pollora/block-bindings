<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Tests\Fixtures;

use Pollora\Attributes\BlockBinding;
use Pollora\Attributes\BlockBinding\BindingField;
use Pollora\BlockBinding\Domain\Models\BindingContext;
use RuntimeException;

#[BlockBinding('acme/event', label: 'Event', postTypes: ['event'])]
final class EventBinding
{
    public int $calls = 0;

    #[BindingField(label: 'Remaining seats')]
    public function remainingSeats(BindingContext $context, SeatCounter $seats): string
    {
        $this->calls++;

        return $seats->left($context->postId ?? 0).' seats & more';
    }

    #[BindingField(type: 'url')]
    public function bookingUrl(BindingContext $context): string
    {
        return 'https://example.test/book/'.$context->postId;
    }

    #[BindingField(name: 'capacity')]
    public function seatCount(): int
    {
        return 120;
    }

    #[BindingField]
    public function soldOut(): bool
    {
        return true;
    }

    #[BindingField]
    public function summary(): Html
    {
        return new Html('<strong>Live</strong>');
    }

    #[BindingField]
    public function nothing(): ?string
    {
        return null;
    }

    #[BindingField]
    public function broken(): string
    {
        throw new RuntimeException('No database.');
    }

    public function notAField(): string
    {
        return '';
    }
}
