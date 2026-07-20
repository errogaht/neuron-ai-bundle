<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Tests\Integration;

use Errogaht\NeuronAiBundle\Workflow\Attribute\AsNeuronWorkflow;
use NeuronAI\Workflow\Workflow;

/** Class-first workflow keeps its typed graph in PHP while selecting Symfony-owned persistence by name. */
#[AsNeuronWorkflow('approval', persistence: 'interruptions')]
final class TestApprovalWorkflow extends Workflow
{
    public function __construct(private readonly TestApprovalNode $approval)
    {
        parent::__construct();
    }

    protected function nodes(): array
    {
        return [$this->approval];
    }
}
