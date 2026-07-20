<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Tests\Integration;

use Errogaht\NeuronAiBundle\Integration\DoctrineMcp\DoctrineMcpToolProvider;
use Mcp\Server;
use PHPUnit\Framework\TestCase;

/** Exercises the bridge against the official MCP server rather than a transport mock. */
final class DoctrineMcpToolProviderTest extends TestCase
{
    public function testConfiguredMcpToolBecomesAnExecutableNeuronTool(): void
    {
        // Scenario: the host's configured MCP registry is attached directly, and Neuron calls it without an HTTP URL.
        $provider = new DoctrineMcpToolProvider($this->server());

        $tools = $provider->tools();

        self::assertCount(2, $tools);
        self::assertSame('doctrine_get', $tools[0]->getName());
        $tools[0]->setInputs(['entity' => 'Order'])->execute();
        self::assertStringContainsString('Order', $tools[0]->getResult());
    }

    public function testOnlyAndExcludeRestrictModelVisibleCapabilities(): void
    {
        // Scenario: destructive operations remain registered in MCP but are intentionally hidden from this agent.
        $provider = new DoctrineMcpToolProvider($this->server());

        $tools = $provider->tools(only: ['doctrine_get', 'doctrine_delete'], exclude: ['doctrine_delete']);

        self::assertCount(1, $tools);
        self::assertSame('doctrine_get', $tools[0]->getName());
    }

    private function server(): Server
    {
        $schema = [
            'type' => 'object',
            'properties' => ['entity' => ['type' => 'string', 'description' => 'Entity alias']],
            'required' => ['entity'],
        ];

        return Server::builder()
            ->setServerInfo('test-doctrine-mcp', '1.0.0')
            ->addTool(
                static fn (string $entity): array => ['entity' => $entity, 'visible' => true],
                'doctrine_get',
                description: 'Read one visible entity.',
                inputSchema: $schema,
            )
            ->addTool(
                static fn (string $entity): array => ['deleted' => $entity],
                'doctrine_delete',
                description: 'Delete one visible entity.',
                inputSchema: $schema,
            )
            ->build();
    }
}
