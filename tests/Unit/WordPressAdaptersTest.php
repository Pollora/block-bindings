<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\BlockBinding\Infrastructure\Adapters\WordPressContentVisibility;
use Pollora\BlockBinding\Infrastructure\Adapters\WordPressValuePresenter;

beforeEach(function (): void {
    $this->visibility = new WordPressContentVisibility;
});

it('shows nothing of a post WordPress does not know', function (): void {
    Functions\when('get_post')->justReturn(null);

    expect($this->visibility->canShowPost(9))->toBeFalse();
});

it('shows a publicly viewable post, or one the user may read', function (bool $public, bool $canRead, bool $expected): void {
    Functions\when('get_post')->justReturn(new WP_Post);
    Functions\when('is_post_publicly_viewable')->justReturn($public);
    Functions\when('current_user_can')->alias(fn (string $capability, int $id): bool => $capability === 'read_post' && $id === 9 && $canRead);
    Functions\when('post_password_required')->justReturn(false);

    expect($this->visibility->canShowPost(9))->toBe($expected);
})->with([
    'public' => [true, false, true],
    'readable by the user' => [false, true, true],
    'neither' => [false, false, false],
]);

it('hides a post behind a password even when it is viewable', function (): void {
    Functions\when('get_post')->justReturn(new WP_Post);
    Functions\when('is_post_publicly_viewable')->justReturn(true);
    Functions\when('current_user_can')->justReturn(true);
    Functions\when('post_password_required')->justReturn(true);

    expect($this->visibility->canShowPost(9))->toBeFalse();
});

it('shows nothing of a term WordPress does not know', function (): void {
    Functions\when('get_term')->justReturn(new WP_Error('invalid_term'));

    expect($this->visibility->canShowTerm(3, 'venue'))->toBeFalse();
});

it('shows a term of a publicly queryable taxonomy, or to a user who can read', function (bool $queryable, bool $canRead, bool $expected): void {
    $taxonomy = new WP_Taxonomy;
    $taxonomy->publicly_queryable = $queryable;
    Functions\when('get_term')->justReturn(new WP_Term);
    Functions\when('get_taxonomy')->justReturn($taxonomy);
    Functions\when('current_user_can')->alias(fn (string $capability): bool => $capability === 'read' && $canRead);

    expect($this->visibility->canShowTerm(3, 'venue'))->toBe($expected);
})->with([
    'queryable' => [true, false, true],
    'private taxonomy, user can read' => [false, true, true],
    'private taxonomy, user cannot' => [false, false, false],
]);

it('treats a taxonomy WordPress does not know as private', function (): void {
    Functions\when('get_term')->justReturn(new WP_Term);
    Functions\when('get_taxonomy')->justReturn(false);
    Functions\when('current_user_can')->justReturn(false);

    expect($this->visibility->canShowTerm(3, 'ghost'))->toBeFalse();
});

it('escapes, cleans and words the values with the WordPress functions', function (): void {
    Functions\when('esc_html')->alias(fn (string $value): string => 'esc('.$value.')');
    Functions\when('wp_kses_post')->alias(fn (string $value): string => 'kses('.$value.')');
    Functions\when('sanitize_url')->alias(fn (string $value): string => 'url('.$value.')');
    $presenter = new WordPressValuePresenter;

    expect($presenter->html('a & b'))->toBe('esc(a & b)')
        ->and($presenter->richText('<b>x</b>'))->toBe('kses(<b>x</b>)')
        ->and($presenter->url('https://example.test'))->toBe('url(https://example.test)');
});

it('words a boolean with the given labels, or the translated Yes and No', function (): void {
    $presenter = new WordPressValuePresenter;

    expect($presenter->boolean(true))->toBe('Yes')
        ->and($presenter->boolean(false))->toBe('No')
        ->and($presenter->boolean(true, 'Open', 'Closed'))->toBe('Open')
        ->and($presenter->boolean(false, 'Open', 'Closed'))->toBe('Closed');
});
