<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Infrastructure\Services;

use Pollora\Attributes\BlockBinding;
use Pollora\BlockBinding\Application\Services\BindingSourceBuilder;
use Pollora\BlockBinding\Application\Services\BindingSourceRegistry;
use Pollora\BlockBinding\Infrastructure\Adapters\WordPressBindingRegistry;
use Pollora\Discovery\Domain\Contracts\DiscoveryInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryLocationInterface;
use Pollora\Discovery\Domain\Contracts\ReflectionCacheInterface;
use Pollora\Discovery\Domain\Services\IsDiscovery;
use Psr\Log\LoggerInterface;
use Spatie\StructureDiscoverer\Data\DiscoveredClass;
use Spatie\StructureDiscoverer\Data\DiscoveredStructure;

/**
 * Discovers the `#[BlockBinding]` classes of the project, its themes, plugins
 * and modules, and registers each as a Block Bindings source.
 *
 * A source that cannot be registered is logged with the class named, and the
 * other sources still register.
 */
final class BlockBindingDiscovery implements DiscoveryInterface
{
    use IsDiscovery;

    public function __construct(
        private readonly BindingSourceBuilder $builder,
        private readonly BindingSourceRegistry $sources,
        private readonly WordPressBindingRegistry $registry,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function discover(DiscoveryLocationInterface $location, DiscoveredStructure $structure, ?ReflectionCacheInterface $reflectionCache = null): void
    {
        if (! $structure instanceof DiscoveredClass || $structure->isAbstract) {
            return;
        }

        foreach ($structure->attributes as $attribute) {
            if ($attribute->class === BlockBinding::class) {
                $this->getItems()->add($location, ['class' => $structure->namespace.'\\'.$structure->name]);

                return;
            }
        }
    }

    public function apply(): void
    {
        foreach ($this->getItems() as $item) {
            /** @var class-string $class */
            $class = $item['class'];

            try {
                $source = $this->builder->build($class);
                $this->sources->add($source);
                $this->registry->register($source);
            } catch (\Throwable $throwable) {
                $this->logger?->error(sprintf('Failed to register the block binding source %s: %s', $class, $throwable->getMessage()), ['exception' => $throwable]);
            }
        }
    }

    public function getIdentifier(): string
    {
        return 'block_bindings';
    }
}
