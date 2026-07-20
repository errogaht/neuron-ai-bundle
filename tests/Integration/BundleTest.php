<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Tests\Integration;

use Errogaht\NeuronAiBundle\Agent\AgentFactory;
use Errogaht\NeuronAiBundle\Agent\AgentRunner;
use Errogaht\NeuronAiBundle\Rag\RagIndexer;
use Errogaht\NeuronAiBundle\Rag\VectorStoreRegistry;
use Errogaht\NeuronAiBundle\Workflow\WorkflowFactory;
use Errogaht\NeuronAiBundle\Workflow\WorkflowRunner;
use NeuronAI\RAG\RAG;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\WorkflowState;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Exercises the complete Symfony container-to-Neuron chat path with no network dependency. */
final class BundleTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testConfiguredAgentCanRunThroughPublicSymfonyService(): void
    {
        // Scenario: application code asks the runner for its default declarative agent.
        $kernel = self::bootKernel(['debug' => false]);

        $runner = $kernel->getContainer()->get(AgentRunner::class);
        self::assertInstanceOf(AgentRunner::class, $runner);
        self::assertSame('Bundle response', $runner->chat('assistant', 'Hello')->content());

        $factory = $kernel->getContainer()->get(AgentFactory::class);
        if (!$factory instanceof AgentFactory) {
            self::fail('The public agent factory has an invalid type.');
        }
        self::assertNotSame($factory->create('assistant'), $factory->create('assistant'));
        $consumer = $kernel->getContainer()->get(TestAgentConsumer::class);
        if (!$consumer instanceof TestAgentConsumer) {
            self::fail('The autowired test consumer has an invalid type.');
        }
        self::assertInstanceOf(TestClassAgent::class, $consumer->agent);
        self::assertNotSame($consumer->agent, $factory->create('assistant'));

        // Scenario: one attributed class owns its prompt and tools, yet remains reusable by class and registry name.
        self::assertContains('class_assistant', $factory->names());
        $classConsumer = $kernel->getContainer()->get(TestClassAgentConsumer::class);
        if (!$classConsumer instanceof TestClassAgentConsumer) {
            self::fail('The class-first agent consumer has an invalid type.');
        }
        self::assertSame('This prompt belongs to the agent class.', $classConsumer->directAgent->resolveInstructions());
        $classTool = $classConsumer->directAgent->getTools()[0];
        self::assertInstanceOf(ToolInterface::class, $classTool);
        self::assertSame('class_greeting', $classTool->getName());
        self::assertInstanceOf(TestClassAgent::class, $classConsumer->classAssistantAgent);
        self::assertNotSame($classConsumer->directAgent, $kernel->getContainer()->get(TestClassAgent::class));
        self::assertSame('Bundle response', $runner->chat('class_assistant', 'Hello from a class')->content());

        // Scenario: a named pipeline embeds application-loader documents into the same store used by a configured RAG agent.
        $indexer = $kernel->getContainer()->get(RagIndexer::class);
        if (!$indexer instanceof RagIndexer) {
            self::fail('The public RAG indexer has an invalid type.');
        }
        $indexResult = $indexer->index('knowledge', reindex: true);
        self::assertSame(2, $indexResult->documents);
        self::assertSame(1, $indexResult->sources);
        $indexer->index('knowledge', reindex: true);
        $ragAgent = $factory->create('rag_assistant');
        self::assertInstanceOf(RAG::class, $ragAgent);
        self::assertInstanceOf(TestEmbeddingProvider::class, $ragAgent->resolveEmbeddingsProvider());
        $matches = $ragAgent->resolveVectorStore()->similaritySearch([10.0, 1.0]);
        // Neuron 3.15 returns an array while newer adapters may return any Traversable implementation.
        self::assertCount(2, \is_array($matches) ? $matches : iterator_to_array($matches));
        $stores = $kernel->getContainer()->get(VectorStoreRegistry::class);
        self::assertInstanceOf(VectorStoreRegistry::class, $stores);
        self::assertNotSame($stores->get('test'), $ragAgent->resolveVectorStore());

        // Scenario: YAML nodes receive initial state, named services stay isolated, and the same graph runs through Messenger.
        $workflows = $kernel->getContainer()->get(WorkflowFactory::class);
        $workflowRunner = $kernel->getContainer()->get(WorkflowRunner::class);
        self::assertInstanceOf(WorkflowFactory::class, $workflows);
        self::assertInstanceOf(WorkflowRunner::class, $workflowRunner);
        self::assertContains('approval', $workflows->names());
        self::assertNotSame($workflows->create('simple'), $workflows->create('simple'));
        $workflowResult = $workflowRunner->run('simple', new WorkflowState(['input' => 'hello']));
        self::assertSame('HELLO', $workflowResult->state->get('processed'));
        $workflowConsumer = $kernel->getContainer()->get(TestWorkflowConsumer::class);
        self::assertInstanceOf(TestWorkflowConsumer::class, $workflowConsumer);
        self::assertInstanceOf(TestWorkflow::class, $workflowConsumer->simpleWorkflow);
        $workflowJob = $workflowConsumer->dispatcher->dispatch('simple', ['input' => 'queued']);
        self::assertSame('QUEUED', $workflowConsumer->results->get($workflowJob)['result']['state']['processed'] ?? null);

        // Scenario: a class-first workflow pauses for approval and a fresh instance resumes from shared persistence.
        $interrupted = $workflowRunner->run('approval');
        self::assertTrue($interrupted->isInterrupted());
        self::assertInstanceOf(ApprovalRequest::class, $interrupted->interrupt);
        $interrupted->interrupt->getAction('continue')?->approve('Approved in integration test');
        $resumed = $workflowRunner->resume('approval', $interrupted->workflowId, $interrupted->interrupt);
        self::assertFalse($resumed->isInterrupted());
        self::assertTrue($resumed->state->get('approved'));

        // Scenario: an untrusted client submits path syntax as a resume token before any persistence read occurs.
        try {
            $workflowRunner->resume('approval', '../other-tenant', new ApprovalRequest('Invalid token'));
            self::fail('Path-like workflow tokens must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('resume token', $exception->getMessage());
        }

        // Scenario: the host routes bundle messages through Messenger and polls the normalized result.
        $async = $kernel->getContainer()->get(TestAsyncConsumer::class);
        if (!$async instanceof TestAsyncConsumer) {
            self::fail('The autowired async consumer has an invalid type.');
        }
        $jobId = $async->dispatcher->dispatch('assistant', 'Queued hello');
        self::assertSame('succeeded', $async->results->get($jobId)['status'] ?? null);

        self::ensureKernelShutdown();
    }
}
