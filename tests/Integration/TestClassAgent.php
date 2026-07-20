<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Tests\Integration;

use Errogaht\NeuronAiBundle\Agent\Attribute\AsNeuronAgent;
use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ToolInterface;

/** Example of an application-owned agent whose prompt, provider, and tools live in one reusable service class. */
#[AsNeuronAgent('class_assistant')]
final class TestClassAgent extends Agent
{
    public function __construct(
        AIProviderInterface $fakeProvider,
        private readonly TestClassAgentTool $greetingTool,
    ) {
        // Neuron's Workflow constructor initializes the executor and per-instance state.
        parent::__construct();
        $this->setAiProvider($fakeProvider);
    }

    protected function instructions(): string
    {
        return 'This prompt belongs to the agent class.';
    }

    /** @return list<ToolInterface> */
    protected function tools(): array
    {
        return [$this->greetingTool];
    }
}
