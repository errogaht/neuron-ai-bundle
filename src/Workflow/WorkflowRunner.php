<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Workflow;

use Errogaht\NeuronAiBundle\Workflow\Event\WorkflowRunCompleted;
use Errogaht\NeuronAiBundle\Workflow\Event\WorkflowRunFailed;
use Errogaht\NeuronAiBundle\Workflow\Event\WorkflowRunInterrupted;
use Errogaht\NeuronAiBundle\Workflow\Event\WorkflowRunStarted;
use NeuronAI\Workflow\Events\Event as NativeEvent;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\WorkflowState;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/** Runs native Neuron workflows behind observable run, stream, and resume operations. */
final class WorkflowRunner
{
    public function __construct(
        private readonly WorkflowFactory $workflows,
        private readonly EventDispatcherInterface $events,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(?string $workflow = null, ?WorkflowState $state = null, ?NativeEvent $startEvent = null): WorkflowRunResult
    {
        $stream = $this->stream($workflow, $state, $startEvent);
        foreach ($stream as $event) {
            // Synchronous callers intentionally discard native streaming events.
        }

        return $stream->getReturn();
    }

    /** @return \Generator<int, NativeEvent, mixed, WorkflowRunResult> */
    public function stream(?string $workflow = null, ?WorkflowState $state = null, ?NativeEvent $startEvent = null): \Generator
    {
        return yield from $this->execute($workflow, null, null, $state, $startEvent);
    }

    public function resume(string $workflow, string $resumeToken, InterruptRequest $request): WorkflowRunResult
    {
        $stream = $this->streamResume($workflow, $resumeToken, $request);
        foreach ($stream as $event) {
            // Resume has the same lifecycle as a fresh run; callers can opt into streamResume().
        }

        return $stream->getReturn();
    }

    /** @return \Generator<int, NativeEvent, mixed, WorkflowRunResult> */
    public function streamResume(string $workflow, string $resumeToken, InterruptRequest $request): \Generator
    {
        return yield from $this->execute($workflow, $resumeToken, $request);
    }

    /** @return \Generator<int, NativeEvent, mixed, WorkflowRunResult> */
    private function execute(
        ?string $name,
        ?string $resumeToken,
        ?InterruptRequest $resumeRequest,
        ?WorkflowState $state = null,
        ?NativeEvent $startEvent = null,
    ): \Generator {
        $started = microtime(true);
        $name = $this->workflows->resolveName($name);
        $workflow = $this->workflows->create($name, $resumeToken, $state);
        if (null !== $startEvent) {
            $workflow->setStartEvent($startEvent);
        }
        $workflowId = $workflow->getWorkflowId();
        $resumed = null !== $resumeRequest;
        $this->events->dispatch(new WorkflowRunStarted($name, $workflowId, $resumed));

        try {
            $handler = $workflow->init($resumeRequest);
            foreach ($handler->events() as $event) {
                yield $event;
            }
            $result = new WorkflowRunResult($name, $workflowId, WorkflowRunResult::COMPLETED, $handler->run(), microtime(true) - $started);
            $this->logger->info('neuron_ai.workflow.completed', ['workflow' => $name, 'workflow_id' => $workflowId, 'duration_seconds' => $result->durationSeconds, 'resumed' => $resumed]);
            $this->events->dispatch(new WorkflowRunCompleted($result));

            return $result;
        } catch (WorkflowInterrupt $interrupt) {
            $result = new WorkflowRunResult($name, $interrupt->getWorkflowId(), WorkflowRunResult::INTERRUPTED, $interrupt->getState(), microtime(true) - $started, $interrupt->getRequest());
            $this->logger->notice('neuron_ai.workflow.interrupted', ['workflow' => $name, 'workflow_id' => $result->workflowId, 'duration_seconds' => $result->durationSeconds, 'resumed' => $resumed]);
            $this->events->dispatch(new WorkflowRunInterrupted($result));

            return $result;
        } catch (\Throwable $exception) {
            $this->logger->error('neuron_ai.workflow.failed', ['workflow' => $name, 'workflow_id' => $workflowId, 'exception' => $exception::class, 'message' => $exception->getMessage(), 'resumed' => $resumed]);
            $this->events->dispatch(new WorkflowRunFailed($name, $workflowId, $exception, $resumed));
            throw $exception;
        }
    }
}
