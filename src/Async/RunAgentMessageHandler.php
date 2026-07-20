<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Async;

use Errogaht\NeuronAiBundle\Agent\AgentRunner;

/** Executes queued work and leaves failures throwable so Messenger retry policies remain authoritative. */
final class RunAgentMessageHandler
{
    public function __construct(
        private readonly AgentRunner $runner,
        private readonly AgentJobResultStoreInterface $results,
    ) {
    }

    public function __invoke(RunAgentMessage $message): void
    {
        $this->results->running($message);
        try {
            $result = $this->runner->chat($message->agent, $message->input, $message->threadId, $message->attributes);
            $this->results->succeeded($message, $result);
        } catch (\Throwable $exception) {
            $this->results->failed($message, $exception);
            throw $exception;
        }
    }
}
