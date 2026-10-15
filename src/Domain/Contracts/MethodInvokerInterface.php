<?php

declare(strict_types=1);

namespace Pollora\BlockBinding\Domain\Contracts;

/**
 * Calls the method of a binding source, giving it the parameters it asks for:
 * the binding's context by its type or by the name `$context`, and the services
 * its other typed parameters name. In a Pollora application it is Laravel's
 * container; without Laravel, the reflection invoker of the package.
 */
interface MethodInvokerInterface
{
    /**
     * @param  array<string, mixed>  $parameters  Values given by parameter name or by class name
     */
    public function call(object $instance, string $method, array $parameters): mixed;
}
