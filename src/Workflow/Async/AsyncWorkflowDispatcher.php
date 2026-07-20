<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Workflow\Async;

use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/** Dispatches serializable initial workflow state through the application's Messenger routing. */
final class AsyncWorkflowDispatcher
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly WorkflowJobResultStoreInterface $results,
    ) {
    }

    /** @param array<string, mixed> $state */
    public function dispatch(string $workflow, array $state = []): string
    {
        $message = new RunWorkflowMessage((string) Uuid::v7(), $workflow, $state);
        $this->results->queued($message);
        $this->bus->dispatch($message);

        return $message->jobId;
    }
}
