<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Agent;

use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
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

    /**
     * Streams provider chunks while preserving the same completion events,
     * logs and normalized final result as chat().
     *
     * Consumers must iterate the generator to completion before reading its
     * AgentRunResult return value. Tool and reasoning chunks remain typed so
     * HTTP boundaries can explicitly choose which data is safe to expose.
     *
     * @param array<string, mixed> $attributes
     *
     * @return \Generator<int, StreamChunk, mixed, AgentRunResult>
     */
    public function stream(string $agent, string $input, ?string $threadId = null, array $attributes = []): \Generator
    {
        $started = microtime(true);
        $this->events->dispatch(new Event\AgentRunStarted($agent, $threadId));
        try {
            $handler = $this->agents->create($agent, $threadId, $attributes)->stream(new UserMessage($input));
            foreach ($handler->events() as $chunk) {
                if ($chunk instanceof StreamChunk) {
                    yield $chunk;
                }
            }

            $message = $handler->getMessage();
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
