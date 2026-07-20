<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Tests\DependencyInjection;

use Errogaht\NeuronAiBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

/** Verifies that the smallest useful Symfony YAML shape receives safe runtime defaults. */
final class ConfigurationTest extends TestCase
{
    public function testProviderAgentAndMessengerDefaultsAreNormalized(): void
    {
        // Scenario: an application defines only endpoint credentials and one agent.
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'providers' => ['main' => ['base_url' => 'https://ai.example/v1', 'key' => 'secret', 'model' => 'model-a']],
            'agents' => ['assistant' => []],
        ]]);

        self::assertSame('openai_like', $config['providers']['main']['type']);
        self::assertSame(60.0, $config['providers']['main']['timeout']);
        self::assertSame([], $config['agents']['assistant']['tools']);
        self::assertFalse($config['agents']['assistant']['doctrine_mcp']['enabled']);
        self::assertSame([], $config['agents']['assistant']['doctrine_mcp']['only']);
        self::assertSame([], $config['agents']['assistant']['doctrine_mcp']['exclude']);
        self::assertFalse($config['agents']['assistant']['rag']['enabled']);
        self::assertSame([], $config['rag']['embeddings']);
        self::assertSame([], $config['rag']['vector_stores']);
        self::assertSame([], $config['rag']['pipelines']);
        self::assertFalse($config['messenger']['enabled']);
    }

    public function testDoctrineMcpCapabilityFiltersAreNormalized(): void
    {
        // Scenario: an agent receives a deliberately restricted subset of the application's MCP registry.
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'agents' => [
                'assistant' => [
                    'doctrine_mcp' => [
                        'enabled' => true,
                        'only' => ['doctrine_get', 'doctrine_update'],
                        'exclude' => ['doctrine_delete'],
                    ],
                ],
            ],
        ]]);

        self::assertTrue($config['agents']['assistant']['doctrine_mcp']['enabled']);
        self::assertSame(['doctrine_get', 'doctrine_update'], $config['agents']['assistant']['doctrine_mcp']['only']);
        self::assertSame(['doctrine_delete'], $config['agents']['assistant']['doctrine_mcp']['exclude']);
    }

    public function testRagComponentsAndPipelineAreNormalized(): void
    {
        // Scenario: one YAML graph provides named embeddings, a vector engine, and a repeatable loader pipeline.
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'rag' => [
                'default_embeddings' => 'knowledge',
                'default_vector_store' => 'knowledge',
                'embeddings' => [
                    'knowledge' => ['type' => 'openai_like', 'base_url' => 'https://ai.example/v1', 'model' => 'embed'],
                ],
                'vector_stores' => [
                    'knowledge' => ['type' => 'qdrant', 'collection_url' => 'http://qdrant/collections/docs'],
                ],
                'pipelines' => [
                    'docs' => ['loaders' => ['app.rag.docs_loader']],
                ],
            ],
            'agents' => [
                'knowledge' => ['class' => \NeuronAI\RAG\RAG::class, 'rag' => ['enabled' => true]],
            ],
        ]]);

        self::assertSame(1024, $config['rag']['vector_stores']['knowledge']['dimensions']);
        self::assertSame(50, $config['rag']['pipelines']['docs']['chunk_size']);
        self::assertTrue($config['agents']['knowledge']['rag']['enabled']);
    }
}
