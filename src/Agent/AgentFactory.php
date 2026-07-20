<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Agent;

use Errogaht\NeuronAiBundle\Provider\ProviderRegistry;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Observability\ObserverInterface;
use Psr\Container\ContainerInterface;

/** Builds non-shared agents and applies static YAML plus dynamic Symfony configurators. */
final class AgentFactory
{
    /**
     * @param array<string, array<string, mixed>>  $config
     * @param iterable<AgentConfiguratorInterface> $configurators
     * @param iterable<ObserverInterface>          $observers
     */
    public function __construct(
        private readonly array $config,
        private readonly ContainerInterface $agents,
        private readonly ContainerInterface $tools,
        private readonly ProviderRegistry $providers,
        private readonly iterable $configurators,
        private readonly iterable $observers,
        private readonly ?string $defaultAgent = null,
    ) {
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->config);
    }

    /** @param array<string, mixed> $attributes */
    public function create(?string $name = null, ?string $threadId = null, array $attributes = []): AgentInterface
    {
        $name ??= $this->defaultAgent;
        if (null === $name || !isset($this->config[$name])) {
            throw new \InvalidArgumentException(\sprintf('Unknown or missing Neuron AI agent "%s".', (string) $name));
        }
        $agent = $this->agents->get($name);
        if (!$agent instanceof AgentInterface) {
            throw new \LogicException(\sprintf('Configured agent "%s" must implement %s.', $name, AgentInterface::class));
        }
        $config = $this->config[$name];
        $agent->setAiProvider($this->providers->get((string) $config['provider']));
        if (null !== $config['instructions']) {
            $agent->setInstructions((string) $config['instructions']);
        }
        if ($agent instanceof Agent) {
            $agent->toolMaxRuns((int) $config['tool_max_runs']);
            $agent->parallelToolCalls((bool) $config['parallel_tool_calls']);
        }
        foreach ((array) $config['tools'] as $toolId) {
            $tool = $this->tools->get((string) $toolId);
            $agent->addTool(\is_object($tool) ? clone $tool : $tool);
        }
        $context = new AgentContext($name, $threadId, $attributes);
        foreach ($this->configurators as $configurator) {
            $configurator->configure($agent, $context);
        }
        if ($agent instanceof Agent) {
            foreach ($this->observers as $observer) {
                $agent->observe($observer);
            }
        }

        return $agent;
    }
}
