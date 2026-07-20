<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Workflow\Event;

/** Signals a fresh or resumed workflow execution without exposing its application state. */
final class WorkflowRunStarted
{
    public function __construct(
        public readonly string $workflow,
        public readonly string $workflowId,
        public readonly bool $resumed,
    ) {
    }
}
