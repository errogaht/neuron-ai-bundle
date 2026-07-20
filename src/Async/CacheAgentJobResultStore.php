<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Async;

use Errogaht\NeuronAiBundle\Agent\AgentRunResult;
use Psr\Cache\CacheItemPoolInterface;

/** Persists transport-neutral job state for polling without coupling applications to a database schema. */
final class CacheAgentJobResultStore implements AgentJobResultStoreInterface
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly int $ttl,
    ) {
    }

    public function queued(RunAgentMessage $message): void
    {
        $this->save($message, 'queued');
    }

    public function running(RunAgentMessage $message): void
    {
        $this->save($message, 'running');
    }

    public function succeeded(RunAgentMessage $message, AgentRunResult $result): void
    {
        $this->save($message, 'succeeded', ['result' => $result->jsonSerialize()]);
    }

    public function failed(RunAgentMessage $message, \Throwable $exception): void
    {
        // Store an operational error, never the prompt, stack trace or credentials.
        $this->save($message, 'failed', ['error' => ['class' => $exception::class, 'message' => $exception->getMessage()]]);
    }

    public function get(string $jobId): ?array
    {
        $item = $this->cache->getItem($this->key($jobId));

        return $item->isHit() && \is_array($item->get()) ? $item->get() : null;
    }

    /** @param array<string, mixed> $extra */
    private function save(RunAgentMessage $message, string $status, array $extra = []): void
    {
        $item = $this->cache->getItem($this->key($message->jobId));
        $item->set(array_merge([
            'job_id' => $message->jobId,
            'agent' => $message->agent,
            'thread_id' => $message->threadId,
            'status' => $status,
            'updated_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ], $extra));
        $item->expiresAfter($this->ttl);
        $this->cache->save($item);
    }

    private function key(string $jobId): string
    {
        return 'neuron_ai_job_'.hash('sha256', $jobId);
    }
}
