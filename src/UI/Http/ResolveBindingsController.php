<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\UI\Http;

use Pollora\BlockBinding\Application\Services\BindingResolver;
use Pollora\BlockBinding\Application\Services\BindingSourceRegistry;
use Pollora\BlockBinding\Domain\Models\BindingSource;
use Pollora\BlockBinding\Infrastructure\Services\BindingEditorData;

/**
 * `POST /wp-json/pollora/v1/block-bindings/resolve`: the values the editor
 * previews for the bound blocks of a post, in one request, computed by the
 * same resolver as the page.
 *
 *     {"context": {"postId": 12, "postType": "event"},
 *      "bindings": [{"key": "a", "source": "acme/event", "args": {"field": "seats"}, "block": "core/paragraph", "attribute": "content"}]}
 *     → {"values": {"a": "120 seats"}}
 *
 * Values are formatted in the site's language, as on the page, whatever the
 * language of the user. Only for a user who can edit the post (or edit posts, without one), with
 * the REST nonce; only Pollora's sources are answered.
 */
final readonly class ResolveBindingsController
{
    /** Bindings answered in one request at most. */
    public const int MAX_BINDINGS = 200;

    public function __construct(
        private BindingSourceRegistry $sources,
        private BindingResolver $resolver,
    ) {}

    /**
     * Registers the route. Hooked on `rest_api_init`.
     */
    public function register(): void
    {
        \register_rest_route(BindingEditorData::REST_NAMESPACE, BindingEditorData::REST_ROUTE, [
            'methods' => 'POST',
            'callback' => $this->handle(...),
            'permission_callback' => $this->canPreview(...),
            'args' => [
                'context' => ['type' => 'object', 'default' => []],
                'bindings' => ['type' => 'array', 'required' => true, 'maxItems' => self::MAX_BINDINGS],
            ],
        ]);
    }

    public function canPreview(\WP_REST_Request $request): bool
    {
        $postId = $this->context($request)['postId'] ?? null;

        return is_int($postId) ? \current_user_can('edit_post', $postId) : \current_user_can('edit_posts');
    }

    /**
     * @return array{values: array<string, mixed>}
     */
    public function handle(\WP_REST_Request $request): array
    {
        $context = $this->context($request);
        $values = [];

        // The page formats dates and numbers in the site's language; REST runs in the user's
        $switched = \switch_to_locale(\get_locale());

        foreach ((array) $request->get_param('bindings') as $binding) {
            if (! is_array($binding) || ! is_scalar($binding['key'] ?? null)) {
                continue;
            }

            $source = is_string($binding['source'] ?? null) ? $this->sources->find($binding['source']) : null;
            $args = is_array($binding['args'] ?? null) ? $binding['args'] : [];
            $attribute = is_string($binding['attribute'] ?? null) ? $binding['attribute'] : 'content';

            $values[(string) $binding['key']] = $source instanceof BindingSource
                ? $this->resolver->preview($source, $args, $context, $attribute, $this->attributeSource($binding['block'] ?? null, $attribute))
                : null;
        }

        if ($switched) {
            \restore_previous_locale();
        }

        return ['values' => $values];
    }

    /**
     * The `source` of an attribute in a registered block type, which decides its escaping.
     */
    private function attributeSource(mixed $blockName, string $attribute): ?string
    {
        if (! is_string($blockName)) {
            return null;
        }

        $source = \WP_Block_Type_Registry::get_instance()->get_registered($blockName)?->attributes[$attribute]['source'] ?? null;

        return is_string($source) ? $source : null;
    }

    /**
     * The block context the editor sent, reduced to what the sources read.
     *
     * @return array{postId?: int, postType?: string, termId?: int, taxonomy?: string}
     */
    private function context(\WP_REST_Request $request): array
    {
        $sent = (array) $request->get_param('context');
        $context = [];

        foreach (['postId', 'termId'] as $name) {
            if (is_numeric($sent[$name] ?? null) && (int) $sent[$name] > 0) {
                $context[$name] = (int) $sent[$name];
            }
        }

        foreach (['postType', 'taxonomy'] as $name) {
            if (is_string($sent[$name] ?? null) && $sent[$name] !== '') {
                $context[$name] = \sanitize_key($sent[$name]);
            }
        }

        return $context;
    }
}
