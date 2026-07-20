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

        $messenger = $children->arrayNode('messenger')->addDefaultsIfNotSet();
        $messengerChildren = $messenger->children();
        $messengerChildren->booleanNode('enabled')->defaultFalse();
        $messengerChildren->scalarNode('bus')->defaultValue('messenger.default_bus');
        $messengerChildren->scalarNode('result_cache_pool')->defaultValue('cache.app');
        $messengerChildren->integerNode('result_ttl')->min(60)->defaultValue(86400);

        return $tree;
    }
}
