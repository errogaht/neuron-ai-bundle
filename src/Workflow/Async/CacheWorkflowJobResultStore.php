<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Workflow\Async;

use Errogaht\NeuronAiBundle\Workflow\WorkflowRunResult;
use Psr\Cache\CacheItemPoolInterface;

/** Stores workflow job metadata without copying initial application state into status records. */
final class CacheWorkflowJobResultStore implements WorkflowJobResultStoreInterface
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly int $ttl,
    ) {
    }

    public function queued(RunWorkflowMessage $message): void
    {
        $this->save($message, 'queued');
    }

    public function running(RunWorkflowMessage $message): void
    {
        $this->save($message, 'running');
    }

    public function succeeded(RunWorkflowMessage $message, WorkflowRunResult $result): void
    {
        $this->save($message, $result->status, ['result' => $result->jsonSerialize()]);
    }

    public function failed(RunWorkflowMessage $message, \Throwable $exception): void
    {
        $this->save($message, 'failed', ['error' => ['class' => $exception::class, 'message' => $exception->getMessage()]]);
    }

    public function get(string $jobId): ?array
    {
        $item = $this->cache->getItem($this->key($jobId));

        return $item->isHit() && \is_array($item->get()) ? $item->get() : null;
    }

    /** @param array<string, mixed> $extra */
    private function save(RunWorkflowMessage $message, string $status, array $extra = []): void
    {
        $item = $this->cache->getItem($this->key($message->jobId));
        $item->set(array_merge([
            'job_id' => $message->jobId,
            'workflow' => $message->workflow,
            'status' => $status,
            'updated_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ], $extra));
        $item->expiresAfter($this->ttl);
        $this->cache->save($item);
    }

    private function key(string $jobId): string
    {
        return 'neuron_ai_workflow_job_'.hash('sha256', $jobId);
    }
}
