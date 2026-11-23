<?php

declare(strict_types=1);

use Pollora\BlockBinding\Application\Services\BindingResolver;
use Pollora\BlockBinding\Application\Services\BindingSourceBuilder;
use Pollora\BlockBinding\Application\Services\BindingSourceRegistry;
use Pollora\BlockBinding\Infrastructure\Adapters\WordPressBindingEditorScript;
use Pollora\BlockBinding\Infrastructure\Adapters\WordPressBindingRegistry;
use Pollora\BlockBinding\Infrastructure\Adapters\WordPressContentVisibility;
use Pollora\BlockBinding\Infrastructure\Adapters\WordPressValuePresenter;
use Pollora\BlockBinding\Infrastructure\Services\BindingEditorData;
use Pollora\BlockBinding\Infrastructure\Services\BindingFormatter;
use Pollora\BlockBinding\Infrastructure\Sources\PostMetaSource;
use Pollora\BlockBinding\Infrastructure\Sources\TypedMetaReader;
use Pollora\BlockBinding\Tests\Fixtures\Concert;
use Pollora\BlockBinding\Tests\Fixtures\SimpleContainer;
use Pollora\BlockBinding\UI\Http\ResolveBindingsController;
use Pollora\Hook\Adapter\Out\WordPress\Action;
use Pollora\Meta\Application\Services\MetaAccessor;
use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Application\Services\MetaSchemaRepository;
use Pollora\Meta\Domain\Services\MetaValueCaster;
use Pollora\Meta\Infrastructure\Adapters\WordPressMetaRegistry;
use Pollora\Meta\Infrastructure\Adapters\WordPressMetaStore;

/**
 * What the Brain Monkey doubles cannot show: the source is registered with WordPress's own Block Bindings API, a core
 * block asks it for its value through a real `WP_Block`, the REST route and the editor script go through WordPress's
 * validation and dependency system, and the typed meta is stored and read by the real meta tables.
 *
 * WordPress is loaded once for the whole run (bootstrap.php), so everything a test creates is removed after it.
 */
const BINDING_TEST_SOURCE = 'pollora/post-meta';

/**
 * The real stack of the README for a project without Laravel, over the `concert` fixture.
 *
 * @return array{registry: WordPressBindingRegistry, sources: BindingSourceRegistry, schemas: MetaSchemaRepository, resolver: BindingResolver}
 */
function bindingStack(): array
{
    if (! post_type_exists('concert')) {
        register_post_type('concert', ['public' => true, 'show_in_rest' => true, 'supports' => ['title', 'editor']]);
    }

    $schemas = new MetaSchemaRepository;
    $builder = new MetaSchemaBuilder;
    $schema = $builder->build(Concert::class);
    $schemas->add($schema);
    (new WordPressMetaRegistry(new Action, new MetaValueCaster))->register($schema);

    $accessor = new MetaAccessor($schemas, $builder, new WordPressMetaStore, new MetaValueCaster);
    $container = new SimpleContainer;
    $container->instance(PostMetaSource::class, new PostMetaSource(new TypedMetaReader($schemas, $accessor), new BindingFormatter(new WordPressValuePresenter)));

    $resolver = new BindingResolver($container, new WordPressContentVisibility, new WordPressValuePresenter, $accessor);
    $registry = new WordPressBindingRegistry(new Action, $resolver);
    $sources = new BindingSourceRegistry;
    $source = (new BindingSourceBuilder)->build(PostMetaSource::class);
    $sources->add($source);
    $registry->register($source);

    return ['registry' => $registry, 'sources' => $sources, 'schemas' => $schemas, 'resolver' => $resolver];
}

/**
 * Renders a paragraph whose content is bound, the way a template does.
 *
 * @param  array<string, mixed>  $args  Arguments of the binding
 */
function renderBoundParagraph(int $postId, array $args, string $postType = 'concert'): string
{
    $block = new WP_Block(
        [
            'blockName' => 'core/paragraph',
            'attrs' => ['metadata' => ['bindings' => ['content' => ['source' => BINDING_TEST_SOURCE, 'args' => $args]]]],
            'innerBlocks' => [],
            'innerHTML' => '<p class="wp-block-paragraph">kept</p>',
            'innerContent' => ['<p class="wp-block-paragraph">kept</p>'],
        ],
        ['postId' => $postId, 'postType' => $postType],
    );

    return $block->render();
}

require_once ABSPATH.'wp-admin/includes/user.php';

beforeEach(function (): void {
    $this->postIds = [];
    $this->userIds = [];
    wp_set_current_user(0);
});

afterEach(function (): void {
    foreach ($this->postIds as $id) {
        wp_delete_post($id, true);
    }

    foreach ($this->userIds as $id) {
        wp_delete_user($id);
    }

    wp_set_current_user(0);
});

function makeUser(object $test, string $role): int
{
    $id = wp_insert_user(['user_login' => 'binding-'.uniqid(), 'user_pass' => wp_generate_password(), 'role' => $role]);

    expect($id)->toBeInt();
    $test->userIds[] = $id;

    return $id;
}

/**
 * @param  array<string, mixed>  $post
 * @param  array<string, mixed>  $meta
 */
function makeConcert(object $test, array $post = [], array $meta = []): int
{
    $id = wp_insert_post([...['post_type' => 'concert', 'post_status' => 'publish', 'post_title' => 'Night'], ...$post], true);
    $test->postIds[] = $id;

    foreach ($meta as $key => $value) {
        update_post_meta($id, $key, $value);
    }

    return $id;
}

it('registers the source with WordPress and a core paragraph shows the typed meta it answers with', function (): void {
    $stack = bindingStack();
    $id = makeConcert($this, meta: ['capacity' => 1200]);

    $registered = get_block_bindings_source(BINDING_TEST_SOURCE);

    expect($registered)->toBeInstanceOf(WP_Block_Bindings_Source::class)
        ->and($registered->label)->toBe('Post meta (Pollora)')
        ->and(renderBoundParagraph($id, ['key' => 'capacity']))->toBe('<p class="wp-block-paragraph">1,200</p>');

    // The registry does not register twice: a second call leaves WordPress's notice out of the log.
    $stack['registry']->register($stack['sources']->find(BINDING_TEST_SOURCE));
    expect(get_block_bindings_source(BINDING_TEST_SOURCE))->toBe($registered);
});

it('escapes what a bound value holds, and does not read a meta that is not exposed in REST', function (): void {
    bindingStack();
    $id = makeConcert($this, meta: ['backstage_code' => 'door 4']);

    // `backstage_code` has no showInRest: the paragraph keeps its own content, as with core/post-meta.
    expect(renderBoundParagraph($id, ['key' => 'backstage_code']))->toBe('<p class="wp-block-paragraph">kept</p>')
        ->and(renderBoundParagraph($id, ['key' => 'starts_at', 'fallback' => '<b>none</b>']))->toBe('<p class="wp-block-paragraph">&lt;b&gt;none&lt;/b&gt;</p>');
});

it('hides the meta of a draft from a visitor and shows it to the editor', function (): void {
    bindingStack();
    $id = makeConcert($this, ['post_status' => 'draft'], ['capacity' => 80]);

    $editor = makeUser($this, 'editor');

    expect(renderBoundParagraph($id, ['key' => 'capacity']))->toBe('<p class="wp-block-paragraph">kept</p>');

    wp_set_current_user($editor);

    expect(renderBoundParagraph($id, ['key' => 'capacity']))->toBe('<p class="wp-block-paragraph">80</p>');
});

it('answers the preview route through WordPress validation and capability checks', function (): void {
    $stack = bindingStack();
    $id = makeConcert($this, meta: ['capacity' => 45]);
    $controller = new ResolveBindingsController($stack['sources'], $stack['resolver']);
    add_action('rest_api_init', $controller->register(...));
    $GLOBALS['wp_rest_server'] = null;

    $request = function (array $body): WP_REST_Response {
        $request = new WP_REST_Request('POST', '/'.BindingEditorData::REST_NAMESPACE.BindingEditorData::REST_ROUTE);
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode($body));

        return rest_do_request($request);
    };
    $body = ['context' => ['postId' => $id, 'postType' => 'concert'], 'bindings' => [['key' => 'a', 'source' => BINDING_TEST_SOURCE, 'args' => ['key' => 'capacity'], 'block' => 'core/paragraph', 'attribute' => 'content']]];

    $editor = makeUser($this, 'editor');
    $subscriber = makeUser($this, 'subscriber');

    $anonymous = $request($body);
    wp_set_current_user($subscriber);
    $reader = $request($body);
    wp_set_current_user($editor);
    $allowed = $request($body);
    $tooMany = $request(['bindings' => array_fill(0, ResolveBindingsController::MAX_BINDINGS + 1, ['key' => 'x'])]);
    $missing = $request(['context' => []]);

    expect($anonymous->get_status())->toBe(401)
        ->and($reader->get_status())->toBe(403)
        ->and($allowed->get_status())->toBe(200)
        ->and($allowed->get_data())->toBe(['values' => ['a' => '45']])
        ->and($tooMany->get_status())->toBe(400)
        ->and($missing->get_status())->toBe(400);

    remove_action('rest_api_init', $controller->register(...));
    $GLOBALS['wp_rest_server'] = null;
});

it('prints the editor script after the data it reads, on handles WordPress knows', function (): void {
    $stack = bindingStack();
    $script = new WordPressBindingEditorScript(new BindingEditorData($stack['sources'], $stack['schemas'], []));

    $script->enqueue();

    $scripts = wp_scripts();
    foreach ($scripts->registered[WordPressBindingEditorScript::HANDLE]->deps as $dependency) {
        expect($scripts->query($dependency))->not->toBeFalse("The handle {$dependency} is not registered by WordPress.");
    }

    ob_start();
    $scripts->do_item(WordPressBindingEditorScript::HANDLE);
    $printed = (string) ob_get_clean();

    expect(wp_script_is(WordPressBindingEditorScript::HANDLE, 'enqueued'))->toBeTrue()
        ->and(strpos($printed, 'window.polloraBlockBindings'))->toBeInt()
        ->and(strpos($printed, 'window.polloraBlockBindings'))->toBeLessThan(strpos($printed, 'wp.data'))
        ->and($printed)->toContain('"pollora/post-meta"');
});
