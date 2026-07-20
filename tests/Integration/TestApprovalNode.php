<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Tests\Integration;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/** Interrupts once and records trusted approval feedback after persistence-backed resume. */
final class TestApprovalNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        $feedback = $this->interrupt(new ApprovalRequest('Approve the test operation?', [
            new Action('continue', 'Continue'),
        ]));
        if (!$feedback instanceof ApprovalRequest || !$feedback->getAction('continue')?->isApproved()) {
            throw new \LogicException('The workflow must resume with an approved action.');
        }
        $state->set('approved', true);

        return new StopEvent();
    }
}
