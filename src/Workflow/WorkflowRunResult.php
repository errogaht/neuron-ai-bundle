<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Workflow;

use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\WorkflowState;

/** Stable Symfony-facing outcome for completed and human-interrupted workflow executions. */
final class WorkflowRunResult implements \JsonSerializable
{
    public const COMPLETED = 'completed';
    public const INTERRUPTED = 'interrupted';

    public function __construct(
        public readonly string $workflow,
        public readonly string $workflowId,
        public readonly string $status,
        public readonly WorkflowState $state,
        public readonly float $durationSeconds,
        public readonly ?InterruptRequest $interrupt = null,
    ) {
    }

    public function isInterrupted(): bool
    {
        return self::INTERRUPTED === $this->status;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'workflow' => $this->workflow,
            'workflow_id' => $this->workflowId,
            'status' => $this->status,
            'state' => $this->state->all(),
            'duration_seconds' => $this->durationSeconds,
            'interrupt' => null === $this->interrupt ? null : $this->interrupt->jsonSerialize(),
        ];
    }
}
