<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/** Defines named, fail-fast provider and agent configuration with optional async execution. */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('neuron_ai');
        $root = $tree->getRootNode();
        if (!$root instanceof ArrayNodeDefinition) {
            throw new \LogicException('The neuron_ai configuration root must be an array node.');
        }

        $children = $root->children();
        $children->scalarNode('default_provider')->defaultNull();
        $children->scalarNode('default_agent')->defaultNull();

        $rag = $children->arrayNode('rag')->addDefaultsIfNotSet();
        $ragChildren = $rag->children();
        $ragChildren->scalarNode('default_embeddings')->defaultNull();
        $ragChildren->scalarNode('default_vector_store')->defaultNull();

        $embeddings = $ragChildren->arrayNode('embeddings')->useAttributeAsKey('name')->arrayPrototype();
        $embedding = $embeddings->children();
        $embedding->enumNode('type')->values(['openai', 'openai_like', 'ollama', 'gemini', 'mistral', 'voyage', 'cohere', 'service'])->defaultValue('openai_like');
        $embedding->scalarNode('service')->defaultNull();
        $embedding->scalarNode('key')->defaultNull();
        $embedding->scalarNode('model')->defaultNull();
        $embedding->scalarNode('base_url')->defaultNull();
        $embedding->integerNode('dimensions')->min(1)->defaultNull();
        $embedding->variableNode('parameters')->defaultValue([]);
        $embedding->floatNode('timeout')->min(0.1)->defaultValue(60.0);
        $embedding->floatNode('connect_timeout')->min(0.1)->defaultValue(10.0);
        $embedding->variableNode('headers')->defaultValue([]);
        $embedding->variableNode('http_options')->defaultValue([]);

        $vectorStores = $ragChildren->arrayNode('vector_stores')->useAttributeAsKey('name')->arrayPrototype();
        $vectorStore = $vectorStores->children();
        $vectorStore->enumNode('type')->values(['memory', 'file', 'qdrant', 'pinecone', 'chroma', 'meilisearch', 'weaviate', 'service'])->defaultValue('memory');
        $vectorStore->scalarNode('service')->defaultNull();
        $vectorStore->scalarNode('key')->defaultNull();
        $vectorStore->scalarNode('directory')->defaultNull();
        $vectorStore->scalarNode('name')->defaultValue('neuron');
        $vectorStore->scalarNode('extension')->defaultValue('.store');
        $vectorStore->scalarNode('host')->defaultNull();
        $vectorStore->scalarNode('collection')->defaultNull();
        $vectorStore->scalarNode('collection_url')->defaultNull();
        $vectorStore->scalarNode('index_url')->defaultNull();
        $vectorStore->scalarNode('index_uid')->defaultNull();
        $vectorStore->scalarNode('tenant')->defaultValue('default_tenant');
        $vectorStore->scalarNode('database')->defaultValue('default_database');
        $vectorStore->scalarNode('namespace')->defaultValue('__default__');
        $vectorStore->scalarNode('version')->defaultValue('2025-04');
        $vectorStore->scalarNode('embedder')->defaultValue('default');
        $vectorStore->integerNode('top_k')->min(1)->defaultValue(4);
        $vectorStore->integerNode('dimensions')->min(1)->defaultValue(1024);

        $pipelines = $ragChildren->arrayNode('pipelines')->useAttributeAsKey('name')->arrayPrototype();
        $pipeline = $pipelines->children();
        $pipeline->scalarNode('embeddings')->defaultNull();
        $pipeline->scalarNode('vector_store')->defaultNull();
        $loaders = $pipeline->arrayNode('loaders');
        $loaders->scalarPrototype();
        $loaders->isRequired()->requiresAtLeastOneElement();
        $pipeline->integerNode('chunk_size')->min(1)->defaultValue(50);

        $providers = $children->arrayNode('providers')->useAttributeAsKey('name')->arrayPrototype();
        $provider = $providers->children();
        $provider->enumNode('type')->values(['openai', 'openai_responses', 'openai_like', 'openai_like_responses', 'anthropic', 'ollama', 'service'])->defaultValue('openai_like');
        $provider->scalarNode('service')->defaultNull();
        $provider->scalarNode('key')->defaultNull();
        $provider->scalarNode('base_url')->defaultNull();
        $provider->scalarNode('model')->defaultNull();
        $provider->variableNode('parameters')->defaultValue([]);
        $provider->booleanNode('strict_response')->defaultFalse();
        $provider->floatNode('timeout')->min(0.1)->defaultValue(60.0);
        $provider->floatNode('connect_timeout')->min(0.1)->defaultValue(10.0);
        $provider->variableNode('headers')->defaultValue([]);
        $provider->variableNode('http_options')->defaultValue([]);
        $provider->scalarNode('models_path')->defaultNull();
        $provider->scalarNode('anthropic_version')->defaultValue('2023-06-01');
        $provider->integerNode('max_tokens')->min(1)->defaultValue(8192);

        $agents = $children->arrayNode('agents')->useAttributeAsKey('name')->arrayPrototype();
        $agent = $agents->children();
        $agent->scalarNode('class')->defaultValue(\NeuronAI\Agent\Agent::class);
        $agent->scalarNode('provider')->defaultNull();
        $agent->scalarNode('instructions')->defaultNull();
        $tools = $agent->arrayNode('tools');
        $tools->scalarPrototype();
        $tools->defaultValue([]);
        $agent->integerNode('tool_max_runs')->min(1)->defaultValue(10);
        $agent->booleanNode('parallel_tool_calls')->defaultFalse();
        $agentRag = $agent->arrayNode('rag')->canBeEnabled();
        $agentRagChildren = $agentRag->children();
        $agentRagChildren->scalarNode('embeddings')->defaultNull();
        $agentRagChildren->scalarNode('vector_store')->defaultNull();
        $agentRagChildren->scalarNode('retrieval')->defaultNull();
        $preProcessors = $agentRagChildren->arrayNode('pre_processors');
        $preProcessors->scalarPrototype();
        $preProcessors->defaultValue([]);
        $postProcessors = $agentRagChildren->arrayNode('post_processors');
        $postProcessors->scalarPrototype();
        $postProcessors->defaultValue([]);
        $doctrineMcp = $agent->arrayNode('doctrine_mcp')->canBeEnabled();
        $doctrineMcpChildren = $doctrineMcp->children();
        $doctrineOnly = $doctrineMcpChildren->arrayNode('only');
        $doctrineOnly->scalarPrototype();
        $doctrineOnly->defaultValue([]);
        $doctrineExclude = $doctrineMcpChildren->arrayNode('exclude');
        $doctrineExclude->scalarPrototype();
        $doctrineExclude->defaultValue([]);

        $messenger = $children->arrayNode('messenger')->addDefaultsIfNotSet();
        $messengerChildren = $messenger->children();
        $messengerChildren->booleanNode('enabled')->defaultFalse();
        $messengerChildren->scalarNode('bus')->defaultValue('messenger.default_bus');
        $messengerChildren->scalarNode('result_cache_pool')->defaultValue('cache.app');
        $messengerChildren->integerNode('result_ttl')->min(60)->defaultValue(86400);

        return $tree;
    }
}
