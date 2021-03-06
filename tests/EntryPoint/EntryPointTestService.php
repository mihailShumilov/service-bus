<?php

/**
 * PHP Service Bus (publish-subscribe pattern implementation).
 *
 * @author  Maksim Masiukevich <dev@async-php.com>
 * @license MIT
 * @license https://opensource.org/licenses/MIT
 */

declare(strict_types = 1);

namespace ServiceBus\Tests\EntryPoint;

use ServiceBus\Common\Context\ServiceBusContext;
use ServiceBus\Services\Annotations\CommandHandler;
use function Amp\delay;

/**
 *
 */
final class EntryPointTestService
{
    /**
     * @CommandHandler()
     */
    public function handle(
        EntryPointTestMessage $command,
        ServiceBusContext $context,
        EntryPointTestDependency $dependency
    ): \Generator {
        if ($command->id === 'throw')
        {
            throw new \RuntimeException('ups...');
        }

        if ($command->id === 'await')
        {
            yield delay(3000);

            return;
        }

        $context->logContextMessage('handled');
    }
}
