<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Tests\Integration;

use Errogaht\NeuronAiBundle\Agent\AgentFactory;
use Errogaht\NeuronAiBundle\Agent\AgentRunner;
use NeuronAI\Tools\ToolInterface;
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
