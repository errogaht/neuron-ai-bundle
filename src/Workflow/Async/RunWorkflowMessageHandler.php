<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Workflow\Async;

use Errogaht\NeuronAiBundle\Workflow\WorkflowRunner;
use NeuronAI\Workflow\WorkflowState;

/** Runs queued workflow starts while leaving retry and dead-letter policy to Symfony Messenger. */
final class RunWorkflowMessageHandler
{
    public function __construct(
        private readonly WorkflowRunner $runner,
        private readonly WorkflowJobResultStoreInterface $results,
    ) {
    }

    public function __invoke(RunWorkflowMessage $message): void
    {
        $this->results->running($message);
        try {
            $this->results->succeeded($message, $this->runner->run($message->workflow, new WorkflowState($message->state)));
        } catch (\Throwable $exception) {
            $this->results->failed($message, $exception);
            throw $exception;
        }
    }
}
