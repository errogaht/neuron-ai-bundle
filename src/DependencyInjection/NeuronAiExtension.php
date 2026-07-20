<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\DependencyInjection;

use Errogaht\NeuronAiBundle\Agent\AgentFactory;
use Errogaht\NeuronAiBundle\Agent\AgentRunner;
use Errogaht\NeuronAiBundle\Async\AsyncAgentDispatcher;
use Errogaht\NeuronAiBundle\Async\CacheAgentJobResultStore;
use Errogaht\NeuronAiBundle\Async\RunAgentMessageHandler;
use Errogaht\NeuronAiBundle\Command\AgentRunCommand;
use Errogaht\NeuronAiBundle\Command\AgentStatusCommand;
use Errogaht\NeuronAiBundle\Integration\DoctrineMcp\DoctrineMcpToolProvider;
use Errogaht\NeuronAiBundle\Provider\ProviderRegistry;
use Errogaht\NeuronAiBundle\Rag\EmbeddingProviderRegistry;
use Errogaht\NeuronAiBundle\Rag\RagIndexer;
use Errogaht\NeuronAiBundle\Rag\VectorStoreRegistry;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Providers\AIProviderInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

/** Compiles declarative YAML into private, lazy Symfony services with explicit runtime boundaries. */
final class NeuronAiExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        $this->validate($config);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.php');

        $agentReferences = [];
        $toolReferences = [];
        foreach ($config['agents'] as $name => $agentConfig) {
            $id = 'neuron_ai.agent.'.$name;
            $container->setDefinition($id, (new Definition((string) $agentConfig['class']))
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setShared(false));
            $agentReferences[$name] = new Reference($id);
            foreach ($agentConfig['tools'] as $toolId) {
                $toolReferences[(string) $toolId] = new Reference((string) $toolId);
            }
        }

        $providerReferences = [];
        foreach ($config['providers'] as $providerConfig) {
            if ('service' === $providerConfig['type']) {
                $providerReferences[(string) $providerConfig['service']] = new Reference((string) $providerConfig['service']);
            }
        }

        $container->getDefinition(ProviderRegistry::class)->setArguments([
            $config['providers'],
            new ServiceLocatorArgument($providerReferences),
            $config['default_provider'],
        ]);

        $embeddingReferences = [];
        foreach ($config['rag']['embeddings'] as $embeddingConfig) {
            if ('service' === $embeddingConfig['type']) {
                $embeddingReferences[(string) $embeddingConfig['service']] = new Reference((string) $embeddingConfig['service']);
            }
        }
        $container->getDefinition(EmbeddingProviderRegistry::class)->setArguments([
            $config['rag']['embeddings'],
            new ServiceLocatorArgument($embeddingReferences),
            $config['rag']['default_embeddings'],
        ]);

        $vectorStoreReferences = [];
        foreach ($config['rag']['vector_stores'] as $vectorStoreConfig) {
            if ('service' === $vectorStoreConfig['type']) {
                $vectorStoreReferences[(string) $vectorStoreConfig['service']] = new Reference((string) $vectorStoreConfig['service']);
            }
        }
        $container->getDefinition(VectorStoreRegistry::class)->setArguments([
            $config['rag']['vector_stores'],
            new ServiceLocatorArgument($vectorStoreReferences),
            $config['rag']['default_vector_store'],
        ]);

        $loaderReferences = [];
        foreach ($config['rag']['pipelines'] as $pipeline) {
            foreach ($pipeline['loaders'] as $loaderId) {
                $loaderReferences[(string) $loaderId] = new Reference((string) $loaderId);
            }
        }
        $container->getDefinition(RagIndexer::class)->setArguments([
            $config['rag']['pipelines'],
            new Reference(EmbeddingProviderRegistry::class),
            new Reference(VectorStoreRegistry::class),
            new ServiceLocatorArgument($loaderReferences),
        ]);

        $ragComponentReferences = [];
        foreach ($config['agents'] as $agentConfig) {
            if (($agentConfig['rag']['enabled'] ?? false) !== true) {
                continue;
            }
            foreach (['pre_processors', 'post_processors'] as $componentList) {
                foreach ($agentConfig['rag'][$componentList] as $serviceId) {
                    $ragComponentReferences[(string) $serviceId] = new Reference((string) $serviceId);
                }
            }
            if (null !== $agentConfig['rag']['retrieval']) {
                $ragComponentReferences[(string) $agentConfig['rag']['retrieval']] = new Reference((string) $agentConfig['rag']['retrieval']);
            }
        }
        $doctrineMcpEnabled = $this->usesDoctrineMcp($config);
        if ($doctrineMcpEnabled) {
            $this->registerDoctrineMcp($container);
        }
        $integrations = $doctrineMcpEnabled ? ['doctrine_mcp' => new Reference(DoctrineMcpToolProvider::class)] : [];

        $container->getDefinition(AgentFactory::class)->setArguments([
            $config['agents'],
            new ServiceLocatorArgument($agentReferences),
            new ServiceLocatorArgument($toolReferences),
            new Reference(ProviderRegistry::class),
            new TaggedIteratorArgument('neuron_ai.agent_configurator'),
            new TaggedIteratorArgument('neuron_ai.observer'),
            $config['default_agent'],
            new ServiceLocatorArgument($integrations),
            new Reference(EmbeddingProviderRegistry::class),
            new Reference(VectorStoreRegistry::class),
            new ServiceLocatorArgument($ragComponentReferences),
        ]);

        $this->registerNamedRuntimeServices($container, $config);
        $this->registerNamedRagServices($container, $config['rag']);

        if ($config['messenger']['enabled']) {
            $this->registerMessenger($container, $config['messenger']);
        }

        $asyncServices = $config['messenger']['enabled'] ? ['dispatcher' => new Reference(AsyncAgentDispatcher::class)] : [];
        $container->getDefinition(AgentRunCommand::class)->setArgument(2, new ServiceLocatorArgument($asyncServices));
    }

    /** @param array<string, mixed> $config */
    private function usesDoctrineMcp(array $config): bool
    {
        foreach ($config['agents'] as $agent) {
            if (($agent['doctrine_mcp']['enabled'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function registerDoctrineMcp(ContainerBuilder $container): void
    {
        if (!class_exists(\Mcp\Server::class) || !class_exists(\NeuronAI\MCP\McpConnector::class)) {
            throw new InvalidArgumentException('Doctrine MCP integration requires errogaht/doctrine-mcp-bundle and mcp/sdk ^0.7.');
        }
        // Keep this reference lazy: Symfony may load NeuronAiBundle before DoctrineMcpBundle.
        // The normal container reference check still reports a missing bundle during compilation.
        $container->setDefinition(DoctrineMcpToolProvider::class, new Definition(DoctrineMcpToolProvider::class, [
            new Reference('doctrine_mcp.server'),
        ]));
    }

    /**
     * @param array<string, mixed> $config
     *
     * Named aliases follow Symfony's `$name + type` convention, e.g. the
     * `support` agent autowires into `AgentInterface $supportAgent`.
     */
    private function registerNamedRuntimeServices(ContainerBuilder $container, array $config): void
    {
        foreach (array_keys($config['providers']) as $name) {
            $id = 'neuron_ai.configured_provider.'.$name;
            $container->setDefinition($id, (new Definition(AIProviderInterface::class))
                ->setFactory([new Reference(ProviderRegistry::class), 'get'])
                ->setArguments([$name])
                ->setShared(false));
            $container->registerAliasForArgument($id, AIProviderInterface::class, $this->argumentName((string) $name, 'provider'));
        }
        foreach (array_keys($config['agents']) as $name) {
            $id = 'neuron_ai.configured_agent.'.$name;
            $container->setDefinition($id, (new Definition(AgentInterface::class))
                ->setFactory([new Reference(AgentFactory::class), 'create'])
                ->setArguments([$name])
                ->setShared(false));
            $container->registerAliasForArgument($id, AgentInterface::class, $this->argumentName((string) $name, 'agent'));
        }

        if (null !== $config['default_provider']) {
            $container->setAlias(AIProviderInterface::class, 'neuron_ai.configured_provider.'.$config['default_provider']);
        }
        if (null !== $config['default_agent']) {
            $container->setAlias(AgentInterface::class, 'neuron_ai.configured_agent.'.$config['default_agent']);
        }
    }

    private function argumentName(string $name, string $suffix): string
    {
        return lcfirst(ContainerBuilder::camelize($name)).ucfirst($suffix);
    }

    /** @param array<string, mixed> $config */
    private function registerNamedRagServices(ContainerBuilder $container, array $config): void
    {
        foreach (array_keys($config['embeddings']) as $name) {
            $id = 'neuron_ai.configured_embeddings.'.$name;
            $container->setDefinition($id, (new Definition(\NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface::class))
                ->setFactory([new Reference(EmbeddingProviderRegistry::class), 'get'])
                ->setArguments([$name]));
            $container->registerAliasForArgument($id, \NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface::class, $this->argumentName((string) $name, 'embeddings'));
        }
        foreach (array_keys($config['vector_stores']) as $name) {
            $id = 'neuron_ai.configured_vector_store.'.$name;
            $container->setDefinition($id, (new Definition(\NeuronAI\RAG\VectorStore\VectorStoreInterface::class))
                ->setFactory([new Reference(VectorStoreRegistry::class), 'fresh'])
                ->setArguments([$name])
                ->setShared(false));
            $container->registerAliasForArgument($id, \NeuronAI\RAG\VectorStore\VectorStoreInterface::class, $this->argumentName((string) $name, 'vectorStore'));
        }

        if (null !== $config['default_embeddings']) {
            $container->setAlias(\NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface::class, 'neuron_ai.configured_embeddings.'.$config['default_embeddings']);
        }
        if (null !== $config['default_vector_store']) {
            $container->setAlias(\NeuronAI\RAG\VectorStore\VectorStoreInterface::class, 'neuron_ai.configured_vector_store.'.$config['default_vector_store']);
        }
    }

    /** @param array<string, mixed> $config */
    private function validate(array &$config): void
    {
        if (null !== $config['default_provider'] && !isset($config['providers'][$config['default_provider']])) {
            throw new InvalidArgumentException(\sprintf('Unknown neuron_ai.default_provider "%s".', $config['default_provider']));
        }
        // Attribute-registered agents are discovered later by AgentServicePass, which performs the final default check.
        foreach ($config['providers'] as $name => $provider) {
            if ('service' === $provider['type'] && empty($provider['service'])) {
                throw new InvalidArgumentException(\sprintf('Provider "%s" of type service requires the service option.', $name));
            }
        }
        $this->validateRag($config['rag']);
        foreach ($config['agents'] as $name => &$agent) {
            $agent['provider'] ??= $config['default_provider'];
            if (null === $agent['provider'] || !isset($config['providers'][$agent['provider']])) {
                throw new InvalidArgumentException(\sprintf('Agent "%s" requires a configured provider.', $name));
            }
            if (!is_a((string) $agent['class'], AgentInterface::class, true)) {
                throw new InvalidArgumentException(\sprintf('Agent class "%s" must implement %s.', $agent['class'], AgentInterface::class));
            }
            if (($agent['rag']['enabled'] ?? false) === true) {
                if (!is_a((string) $agent['class'], \NeuronAI\RAG\RAG::class, true)) {
                    throw new InvalidArgumentException(\sprintf('RAG agent class "%s" must extend %s.', $agent['class'], \NeuronAI\RAG\RAG::class));
                }
                $agent['rag']['embeddings'] ??= $config['rag']['default_embeddings'];
                $agent['rag']['vector_store'] ??= $config['rag']['default_vector_store'];
                $this->requireRagReference($agent['rag']['embeddings'], $config['rag']['embeddings'], 'embedding provider', (string) $name);
                $this->requireRagReference($agent['rag']['vector_store'], $config['rag']['vector_stores'], 'vector store', (string) $name);
            }
        }
        unset($agent);
    }

    /** @param array<string, mixed> $config */
    private function validateRag(array &$config): void
    {
        if (null !== $config['default_embeddings'] && !isset($config['embeddings'][$config['default_embeddings']])) {
            throw new InvalidArgumentException(\sprintf('Unknown rag.default_embeddings "%s".', $config['default_embeddings']));
        }
        if (null !== $config['default_vector_store'] && !isset($config['vector_stores'][$config['default_vector_store']])) {
            throw new InvalidArgumentException(\sprintf('Unknown rag.default_vector_store "%s".', $config['default_vector_store']));
        }
        foreach ($config['embeddings'] as $name => $embedding) {
            if ('service' === $embedding['type'] && empty($embedding['service'])) {
                throw new InvalidArgumentException(\sprintf('Embedding provider "%s" of type service requires the service option.', $name));
            }
        }
        foreach ($config['vector_stores'] as $name => $store) {
            if ('service' === $store['type'] && empty($store['service'])) {
                throw new InvalidArgumentException(\sprintf('Vector store "%s" of type service requires the service option.', $name));
            }
        }
        foreach ($config['pipelines'] as $name => &$pipeline) {
            $pipeline['embeddings'] ??= $config['default_embeddings'];
            $pipeline['vector_store'] ??= $config['default_vector_store'];
            $this->requireRagReference($pipeline['embeddings'], $config['embeddings'], 'embedding provider', (string) $name);
            $this->requireRagReference($pipeline['vector_store'], $config['vector_stores'], 'vector store', (string) $name);
        }
        unset($pipeline);
    }

    /** @param array<string, mixed> $available */
    private function requireRagReference(mixed $selected, array $available, string $kind, string $owner): void
    {
        if (!\is_string($selected) || '' === $selected || !isset($available[$selected])) {
            throw new InvalidArgumentException(\sprintf('RAG "%s" requires a configured %s.', $owner, $kind));
        }
    }

    /** @param array<string, mixed> $config */
    private function registerMessenger(ContainerBuilder $container, array $config): void
    {
        if (!interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
            throw new InvalidArgumentException('neuron_ai.messenger.enabled requires symfony/messenger.');
        }

        $container->setDefinition(CacheAgentJobResultStore::class, new Definition(CacheAgentJobResultStore::class, [
            new Reference((string) $config['result_cache_pool']),
            (int) $config['result_ttl'],
        ]));
        $container->setAlias(\Errogaht\NeuronAiBundle\Async\AgentJobResultStoreInterface::class, CacheAgentJobResultStore::class);
        $container->setDefinition(AsyncAgentDispatcher::class, (new Definition(AsyncAgentDispatcher::class, [
            new Reference((string) $config['bus']),
            new Reference(\Errogaht\NeuronAiBundle\Async\AgentJobResultStoreInterface::class),
        ]))->setPublic(true));
        $container->setDefinition(RunAgentMessageHandler::class, (new Definition(RunAgentMessageHandler::class, [
            new Reference(AgentRunner::class),
            new Reference(\Errogaht\NeuronAiBundle\Async\AgentJobResultStoreInterface::class),
        ]))->addTag('messenger.message_handler'));
        $container->setDefinition(AgentStatusCommand::class, (new Definition(AgentStatusCommand::class, [
            new Reference(\Errogaht\NeuronAiBundle\Async\AgentJobResultStoreInterface::class),
        ]))->addTag('console.command'));
    }
}
