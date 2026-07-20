<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Tests\Integration;

use Errogaht\NeuronAiBundle\Workflow\Async\AsyncWorkflowDispatcher;
use Errogaht\NeuronAiBundle\Workflow\Async\WorkflowJobResultStoreInterface;
use NeuronAI\Workflow\WorkflowInterface;

/** Proves named Workflow and optional async services participate in normal Symfony autowiring. */
final class TestWorkflowConsumer
{
    public function __construct(
        public readonly WorkflowInterface $simpleWorkflow,
        public readonly AsyncWorkflowDispatcher $dispatcher,
        public readonly WorkflowJobResultStoreInterface $results,
    ) {
    }
}
