<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Async;

use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/** Creates a trackable job before handing it to the application's configured Messenger bus. */
final class AsyncAgentDispatcher
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly AgentJobResultStoreInterface $results,
    ) {
    }

    /** @param array<string, mixed> $attributes */
    public function dispatch(string $agent, string $input, ?string $threadId = null, array $attributes = []): string
    {
        $message = new RunAgentMessage((string) Uuid::v7(), $agent, $input, $threadId, $attributes);
        $this->results->queued($message);
        $this->bus->dispatch($message);

        return $message->jobId;
    }
}
