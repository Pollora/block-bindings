<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Tests\Fixtures;

use Pollora\Attributes\BlockBinding;
use Pollora\Attributes\BlockBinding\BindingField;

final class NotASource
{
    #[BindingField]
    public function title(): string
    {
        return '';
    }
}

#[BlockBinding('Acme/Event')]
final class UppercaseName
{
    public function __invoke(): string
    {
        return '';
    }
}

#[BlockBinding('acme')]
final class NoNamespace
{
    public function __invoke(): string
    {
        return '';
    }
}

#[BlockBinding('acme/empty')]
final class NoField
{
    public function title(): string
    {
        return '';
    }
}

#[BlockBinding('acme/untyped')]
final class UntypedField
{
    #[BindingField]
    public function title()
    {
        return '';
    }
}

#[BlockBinding('acme/array')]
final class ArrayField
{
    #[BindingField]
    public function tags(): array
    {
        return [];
    }
}

#[BlockBinding('acme/twice')]
final class DuplicateField
{
    #[BindingField(name: 'title')]
    public function heading(): string
    {
        return '';
    }

    #[BindingField]
    public function title(): string
    {
        return '';
    }
}

#[BlockBinding('acme/kind')]
final class UnknownType
{
    #[BindingField(type: 'video')]
    public function clip(): string
    {
        return '';
    }
}

#[BlockBinding('acme/hidden')]
final class PrivateField
{
    #[BindingField]
    private function secret(): string
    {
        return '';
    }
}
