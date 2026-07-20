<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Workflow\Event;

use Errogaht\NeuronAiBundle\Workflow\WorkflowRunResult;

/** Announces a durable human-in-the-loop boundary without serializing Neuron internals into the event. */
final class WorkflowRunInterrupted
{
    public function __construct(public readonly WorkflowRunResult $result)
    {
    }
}
