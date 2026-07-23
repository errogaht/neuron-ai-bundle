<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Tests\Integration;

use Errogaht\NeuronAiBundle\Integration\DoctrineMcp\DoctrineMcpToolProvider;
use Mcp\Server;
use NeuronAI\Providers\OpenAI\ToolMapper;
use PHPUnit\Framework\TestCase;

/** Exercises the bridge against the official MCP server rather than a transport mock. */
final class DoctrineMcpToolProviderTest extends TestCase
{
    public function testConfiguredMcpToolBecomesAnExecutableNeuronTool(): void
    {
        // Scenario: the host's configured MCP registry is attached directly, and Neuron calls it without an HTTP URL.
        $provider = new DoctrineMcpToolProvider($this->server());

        $tools = $provider->tools();

        self::assertCount(3, $tools);
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

    public function testNestedMcpSchemaRemainsVisibleToOpenAiCompatibleProviders(): void
    {
        // Scenario: an aggregate mutation accepts arrays of structured items, so the model must see every nested field and required constraint.
        $provider = new DoctrineMcpToolProvider($this->server());

        $tools = $provider->tools(only: ['plan_create']);
        $payload = (new ToolMapper())->map($tools);

        self::assertSame(
            [
                'type' => 'object',
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'supplementId' => ['type' => 'integer', 'minimum' => 1],
                                'time' => ['type' => 'string', 'pattern' => '^\\d{2}:\\d{2}$'],
                            ],
                            'required' => ['supplementId', 'time'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['items'],
                'additionalProperties' => false,
            ],
            $payload[0]['function']['parameters'],
        );
    }

    private function server(): Server
    {
        $schema = [
            'type' => 'object',
            'properties' => ['entity' => ['type' => 'string', 'description' => 'Entity alias']],
            'required' => ['entity'],
        ];
        $planSchema = [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'supplementId' => ['type' => 'integer', 'minimum' => 1],
                            'time' => ['type' => 'string', 'pattern' => '^\\d{2}:\\d{2}$'],
                        ],
                        'required' => ['supplementId', 'time'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['items'],
            'additionalProperties' => false,
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
            ->addTool(
                static fn (array $items): array => ['items' => $items],
                'plan_create',
                description: 'Create an aggregate plan.',
                inputSchema: $planSchema,
            )
            ->build();
    }
}
