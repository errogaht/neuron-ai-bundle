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
        self::assertFalse($config['messenger']['enabled']);
    }
}
