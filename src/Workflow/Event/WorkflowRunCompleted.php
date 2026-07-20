<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Workflow\Event;

use Errogaht\NeuronAiBundle\Workflow\WorkflowRunResult;

/** Carries the final workflow state after successful completion. */
final class WorkflowRunCompleted
{
    public function __construct(public readonly WorkflowRunResult $result)
    {
    }
}
