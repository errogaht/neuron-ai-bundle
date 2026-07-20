<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Agent;

use Errogaht\NeuronAiBundle\Integration\DoctrineMcp\DoctrineMcpToolProvider;
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
        private readonly ?ContainerInterface $integrations = null,
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
        if (($config['doctrine_mcp']['enabled'] ?? false) === true) {
            if (null === $this->integrations || !$this->integrations->has('doctrine_mcp')) {
                throw new \LogicException('The agent enables Doctrine MCP, but its in-process bridge is unavailable. Install and enable errogaht/doctrine-mcp-bundle.');
            }
            $bridge = $this->integrations->get('doctrine_mcp');
            if (!$bridge instanceof DoctrineMcpToolProvider) {
                throw new \LogicException('The Doctrine MCP bridge service has an invalid type.');
            }
            // The MCP server remains the authorization boundary; these filters only reduce model-visible capabilities.
            $agent->addTool($bridge->tools(
                $this->stringList($config['doctrine_mcp']['only'] ?? []),
                $this->stringList($config['doctrine_mcp']['exclude'] ?? []),
            ));
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

    /**
     * Symfony's configuration component guarantees a sequence of scalar values; this final
     * normalization gives the optional integration a stable list-of-string boundary.
     *
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (!\is_array($values)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $value): string => (string) $value, $values));
    }
}
