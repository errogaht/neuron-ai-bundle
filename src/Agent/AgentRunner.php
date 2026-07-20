<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Agent;

use NeuronAI\Chat\Messages\UserMessage;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/** Executes chat agents behind one observable Symfony-facing result contract. */
final class AgentRunner
{
    public function __construct(
        private readonly AgentFactory $agents,
        private readonly EventDispatcherInterface $events,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param array<string, mixed> $attributes */
    public function chat(string $agent, string $input, ?string $threadId = null, array $attributes = []): AgentRunResult
    {
        $started = microtime(true);
        $this->events->dispatch(new Event\AgentRunStarted($agent, $threadId));
        try {
            $message = $this->agents->create($agent, $threadId, $attributes)->chat(new UserMessage($input))->getMessage();
            $result = new AgentRunResult($agent, $message->jsonSerialize(), microtime(true) - $started);
            $this->logger->info('neuron_ai.agent.completed', ['agent' => $agent, 'thread_id' => $threadId, 'duration_seconds' => $result->durationSeconds]);
            $this->events->dispatch(new Event\AgentRunCompleted($result, $threadId));

            return $result;
        } catch (\Throwable $exception) {
            $this->logger->error('neuron_ai.agent.failed', ['agent' => $agent, 'thread_id' => $threadId, 'exception' => $exception::class, 'message' => $exception->getMessage()]);
            $this->events->dispatch(new Event\AgentRunFailed($agent, $exception, $threadId));
            throw $exception;
        }
    }
}
