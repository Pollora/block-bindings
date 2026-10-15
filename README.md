<p align="center">
  <a href="https://pollora.dev">
    <img src="https://raw.githubusercontent.com/Pollora/.github/main/brand/banners/block-bindings.png" width="100%" alt="Pollora Block Bindings: WordPress Block Bindings declared with attributes">
  </a>
</p>

<p align="center">
  <a href="https://packagist.org/packages/pollora/block-bindings"><img src="https://img.shields.io/packagist/v/pollora/block-bindings" alt="Latest version"></a>
  <a href="https://packagist.org/packages/pollora/block-bindings"><img src="https://img.shields.io/packagist/dt/pollora/block-bindings" alt="Total downloads"></a>
  <a href="https://github.com/Pollora/block-bindings/actions/workflows/tests.yml"><img src="https://github.com/Pollora/block-bindings/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/Pollora/block-bindings" alt="License"></a>
</p>

WordPress Block Bindings declared in code: a class with `#[BlockBinding]` is a source, each method with `#[BindingField]` is a field a block attribute can be bound to, and the package registers it with WordPress, resolves the value on the page, previews it in the block editor and lists the fields the editor offers. It also ships four sources ready to bind: the post meta, the term meta and the author meta of [`pollora/meta`](https://github.com/Pollora/meta), and the options a project lists.

> Part of [Pollora](https://pollora.dev), the Laravel framework for WordPress, where it is installed and wired for you: see [Block Bindings](https://pollora.dev/blocks/block-bindings/). It runs without Laravel too: in a plugin, a Bedrock site or any PHP project.

## Installation

```bash
composer require pollora/block-bindings
```

Requires PHP 8.3+ and WordPress 6.5+. It builds on [`pollora/meta`](https://github.com/Pollora/meta), [`pollora/attributes`](https://github.com/Pollora/attributes), [`pollora/discovery`](https://github.com/Pollora/discovery) and [`pollora/hook`](https://github.com/Pollora/hook).

## Declaring a source

```php
use Pollora\Attributes\BlockBinding;
use Pollora\Attributes\BlockBinding\BindingField;
use Pollora\BlockBinding\Domain\Models\BindingContext;

#[BlockBinding('acme/event', label: 'Event', postTypes: ['event'])]
final class EventBinding
{
    #[BindingField(label: 'Remaining seats')]
    public function remainingSeats(BindingContext $context, SeatCounter $seats): string
    {
        return $seats->left($context->postId ?? 0).' seats left';
    }

    #[BindingField(type: 'url')]
    public function bookingUrl(BindingContext $context): string
    {
        return 'https://example.test/book/'.$context->postId;
    }
}
```

A field returns a `string`, an `int`, a `float`, a `bool`, a `Stringable` (an object with a `toHtml()` method is written as HTML) or `null` to keep what the block holds. A method receives the `BindingContext` (the post, term and block of the context, the arguments of the binding, `post()`, `meta()`) and the services its other typed parameters name. A class with `__invoke` is a single-field source.

## Wiring it outside Pollora

The sources are made by a PSR-11 container: give the resolver one that knows them (the dependencies of your own sources, and the shipped ones you register). Then register the shipped sources and let discovery find yours:

```php
use Pollora\BlockBinding\Application\Services\BindingResolver;
use Pollora\BlockBinding\Application\Services\BindingSourceBuilder;
use Pollora\BlockBinding\Application\Services\BindingSourceRegistry;
use Pollora\BlockBinding\Infrastructure\Adapters\WordPressBindingRegistry;
use Pollora\BlockBinding\Infrastructure\Services\BlockBindingDiscovery;
use Pollora\BlockBinding\Infrastructure\Sources\PostMetaSource;
use Pollora\Discovery\Domain\Models\DiscoveryLocation;
use Pollora\Discovery\Infrastructure\Services\DiscoveryEngine;
use Pollora\Hook\Adapter\Out\WordPress\Action;

$builder = new BindingSourceBuilder;
$sources = new BindingSourceRegistry;
$registry = new WordPressBindingRegistry(new Action, new BindingResolver($container, $visibility, $presenter, $metaAccessor));

$shipped = $builder->build(PostMetaSource::class);   // likewise TermMetaSource, AuthorMetaSource, OptionSource
$sources->add($shipped);
$registry->register($shipped);

(new DiscoveryEngine)
    ->addLocation(new DiscoveryLocation('MyPlugin\\', __DIR__.'/src'))
    ->addDiscovery('block_bindings', new BlockBindingDiscovery($builder, $sources, $registry))
    ->run();
```

`$visibility` (`WordPressContentVisibility`) decides whether the visitor may see a post or a term, and `$presenter` (`WordPressValuePresenter`) escapes and words a value; both are WordPress adapters of the package. The `pollora/option` source reads only the options you pass to `OptionSource`, and the editor learns the fields through `BindingEditorData`, the `ResolveBindingsController` (the preview route) and `WordPressBindingEditorScript`. A method's services are given by `ReflectionMethodInvoker` (the container's, or a class that needs no argument); give the resolver a `MethodInvokerInterface` to inject differently.

The Laravel side stays in the framework: the service provider, `config/block-bindings.php`, `pollora:binding:list` and `pollora:make:binding`, the `pollora:doctor` check, and the injection of method parameters through Laravel's container.

## Testing

```bash
composer test   # unit tests, PHPStan, Pint
```

## License

MIT. See [LICENSE](LICENSE).
