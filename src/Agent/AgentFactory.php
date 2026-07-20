<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Agent;

use Errogaht\NeuronAiBundle\Integration\DoctrineMcp\DoctrineMcpToolProvider;
use Errogaht\NeuronAiBundle\Provider\ProviderRegistry;
use Errogaht\NeuronAiBundle\Rag\EmbeddingProviderRegistry;
use Errogaht\NeuronAiBundle\Rag\VectorStoreRegistry;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Observability\ObserverInterface;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;
use NeuronAI\RAG\PreProcessor\PreProcessorInterface;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
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
        private readonly ?EmbeddingProviderRegistry $embeddingProviders = null,
        private readonly ?VectorStoreRegistry $vectorStores = null,
        private readonly ?ContainerInterface $ragComponents = null,
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
        // Class-first agents can own their provider through provider() or constructor injection.
        if (null !== $config['provider']) {
            $agent->setAiProvider($this->providers->get((string) $config['provider']));
        }
        if (null !== $config['instructions']) {
            $agent->setInstructions((string) $config['instructions']);
        }
        if ($agent instanceof Agent) {
            if (null !== $config['tool_max_runs']) {
                $agent->toolMaxRuns((int) $config['tool_max_runs']);
            }
            if (null !== $config['parallel_tool_calls']) {
                $agent->parallelToolCalls((bool) $config['parallel_tool_calls']);
            }
        }
        if (($config['rag']['enabled'] ?? false) === true) {
            $this->configureRag($agent, $config['rag']);
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

    /** @param array<string, mixed> $config */
    private function configureRag(AgentInterface $agent, array $config): void
    {
        if (!$agent instanceof RAG) {
            throw new \LogicException(\sprintf('Agent "%s" enables RAG but does not extend %s.', $agent::class, RAG::class));
        }
        if (null === $this->embeddingProviders || null === $this->vectorStores || null === $this->ragComponents) {
            throw new \LogicException('RAG services are unavailable in the compiled container.');
        }

        $agent->setEmbeddingsProvider($this->embeddingProviders->get((string) $config['embeddings']));
        $agent->setVectorStore($this->vectorStores->fresh((string) $config['vector_store']));
        if (null !== $config['retrieval']) {
            $retrieval = $this->ragComponents->get((string) $config['retrieval']);
            if (!$retrieval instanceof RetrievalInterface) {
                throw new \LogicException(\sprintf('RAG retrieval service "%s" must implement %s.', $config['retrieval'], RetrievalInterface::class));
            }
            // Custom retrieval services own their Symfony scope; use shared: false when they carry per-query state.
            $agent->setRetrieval($retrieval);
        }

        $agent->setPreProcessors($this->ragComponents($config['pre_processors'], PreProcessorInterface::class));
        $agent->setPostProcessors($this->ragComponents($config['post_processors'], PostProcessorInterface::class));
    }

    /**
     * @param class-string<T> $interface
     *
     * @return list<T>
     *
     * @template T of object
     */
    private function ragComponents(mixed $serviceIds, string $interface): array
    {
        $components = [];
        foreach ($this->stringList($serviceIds) as $serviceId) {
            $component = $this->ragComponents?->get($serviceId);
            if (!$component instanceof $interface) {
                throw new \LogicException(\sprintf('RAG service "%s" must implement %s.', $serviceId, $interface));
            }
            $components[] = $component;
        }

        return $components;
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
