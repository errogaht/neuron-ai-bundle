<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Workflow\Event;

/** Carries an operational failure while keeping workflow state out of default logs. */
final class WorkflowRunFailed
{
    public function __construct(
        public readonly string $workflow,
        public readonly string $workflowId,
        public readonly \Throwable $exception,
        public readonly bool $resumed,
    ) {
    }
}
