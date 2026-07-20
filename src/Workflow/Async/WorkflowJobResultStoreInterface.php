<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Workflow\Async;

use Errogaht\NeuronAiBundle\Workflow\WorkflowRunResult;

/** Decouples queued workflow execution from its cache-backed polling representation. */
interface WorkflowJobResultStoreInterface
{
    public function queued(RunWorkflowMessage $message): void;

    public function running(RunWorkflowMessage $message): void;

    public function succeeded(RunWorkflowMessage $message, WorkflowRunResult $result): void;

    public function failed(RunWorkflowMessage $message, \Throwable $exception): void;

    /** @return array<string, mixed>|null */
    public function get(string $jobId): ?array;
}
