<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Async;

use Errogaht\NeuronAiBundle\Agent\AgentRunResult;

/** Decouples Messenger execution from the cache-backed polling mechanism. */
interface AgentJobResultStoreInterface
{
    public function queued(RunAgentMessage $message): void;

    public function running(RunAgentMessage $message): void;

    public function succeeded(RunAgentMessage $message, AgentRunResult $result): void;

    public function failed(RunAgentMessage $message, \Throwable $exception): void;

    /** @return array<string, mixed>|null */
    public function get(string $jobId): ?array;
}
