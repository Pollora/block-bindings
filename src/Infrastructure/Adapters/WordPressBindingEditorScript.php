<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Infrastructure\Adapters;

use Pollora\BlockBinding\Infrastructure\Services\BindingEditorData;

/**
 * Completes Pollora's binding sources in the editor: the fields each one
 * offers, and the values of the bound blocks, asked from the server.
 *
 * resources/js/block-bindings.js is printed as the inline script of a handle
 * without a URL (nothing under vendor/ has a public one), after the data it
 * reads. It depends on wp-blocks, whose inline script registers the sources
 * the server declared: the script then adds what only the editor knows.
 */
final readonly class WordPressBindingEditorScript
{
    public const string HANDLE = 'pollora-block-bindings';

    private const array DEPENDENCIES = ['wp-api-fetch', 'wp-block-editor', 'wp-blocks', 'wp-data'];

    public function __construct(
        private BindingEditorData $data,
        private string $scriptPath = __DIR__.'/../../../resources/js/block-bindings.js',
    ) {}

    /**
     * Hooked on `enqueue_block_editor_assets`.
     */
    public function enqueue(): void
    {
        $script = is_readable($this->scriptPath) ? file_get_contents($this->scriptPath) : false;

        if ($script === false) {
            return;
        }

        \wp_register_script(self::HANDLE, false, self::DEPENDENCIES, null, true);
        \wp_add_inline_script(self::HANDLE, 'window.polloraBlockBindings = '.\wp_json_encode($this->data->toArray(), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES).';', 'before');
        \wp_add_inline_script(self::HANDLE, $script);
        \wp_enqueue_script(self::HANDLE);
    }
}
