<?php

/**
 * PHP Service Bus (publish-subscribe pattern implementation).
 *
 * @author  Maksim Masiukevich <dev@async-php.com>
 * @license MIT
 * @license https://opensource.org/licenses/MIT
 */

declare(strict_types = 1);

namespace ServiceBus\MessageExecutor;

use function ServiceBus\Common\invokeReflectionMethod;
use Amp\Failure;
use Amp\Promise;
use Psr\Log\LogLevel;
use ServiceBus\Common\Context\ServiceBusContext;
use ServiceBus\Common\MessageExecutor\MessageExecutor;
use ServiceBus\Services\Configuration\DefaultHandlerOptions;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Executing message validation.
 */
final class MessageValidationExecutor implements MessageExecutor
{
    /** @var MessageExecutor */
    private $executor;

    /** @var ValidatorInterface */
    private $validator;

    /** @var DefaultHandlerOptions */
    private $options;

    public function __construct(MessageExecutor $executor, DefaultHandlerOptions $options, ValidatorInterface $validator)
    {
        $this->executor  = $executor;
        $this->options   = $options;
        $this->validator = $validator;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(object $message, ServiceBusContext $context): Promise
    {
        try
        {
            /** @var ConstraintViolationList $violations */
            $violations = $this->validator->validate($message, null, $this->options->validationGroups);
        }
        // @codeCoverageIgnoreStart
        catch (\Throwable $throwable)
        {
            return new Failure($throwable);
        }
        // @codeCoverageIgnoreEnd

        if (\count($violations) !== 0)
        {
            self::bindViolations($violations, $context);

            /** If a validation error event class is specified, then we abort the execution */
            if ($this->options->defaultValidationFailedEvent !== null)
            {
                $context->logContextMessage(
                    'Error validation, sending an error event and stopping message processing',
                    ['eventClass' => $this->options->defaultValidationFailedEvent],
                    LogLevel::DEBUG
                );

                return self::publishViolations((string) $this->options->defaultValidationFailedEvent, $context);
            }
        }

        return ($this->executor)($message, $context);
    }

    /**
     * Publish failed event.
     */
    private static function publishViolations(string $eventClass, ServiceBusContext $context): Promise
    {
        /**
         * @noinspection VariableFunctionsUsageInspection
         *
         * @var \ServiceBus\Services\Contracts\ValidationFailedEvent $event
         */
        $event = \forward_static_call_array([$eventClass, 'create'], [$context->traceId(), $context->violations()]);

        return $context->delivery($event);
    }

    /**
     * Bind violations to context.
     */
    private static function bindViolations(ConstraintViolationList $violations, ServiceBusContext $context): void
    {
        $errors = [];

        /** @var \Symfony\Component\Validator\ConstraintViolation $violation */
        foreach ($violations as $violation)
        {
            $errors[$violation->getPropertyPath()][] = $violation->getMessage();
        }

        try
        {
            invokeReflectionMethod($context, 'validationFailed', $errors);
        }
        // @codeCoverageIgnoreStart
        catch (\Throwable $throwable)
        {
            /** No exceptions can happen */
        }
        // @codeCoverageIgnoreEnd
    }
}
