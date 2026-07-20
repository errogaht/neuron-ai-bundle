<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Integration\DoctrineMcp;

use Mcp\Server;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Tools\ToolInterface;

/** Converts the host application's real Doctrine MCP registry into Neuron tools over an isolated in-process protocol session. */
final class DoctrineMcpToolProvider
{
    public function __construct(private readonly Server $server)
    {
    }

    /**
     * @param list<string> $only
     * @param list<string> $exclude
     *
     * @return list<ToolInterface>
     */
    public function tools(array $only = [], array $exclude = []): array
    {
        // A fresh connector owns one isolated MCP session and remains referenced by each callable Neuron tool.
        // This intentionally reuses the configured server instead of bypassing its scopes, actor provider, or audit flow.
        $connector = new McpConnector(['transport' => new DoctrineMcpLoopbackTransport($this->server)]);
        if ([] !== $only) {
            $connector->only($only);
        }
        if ([] !== $exclude) {
            $connector->exclude($exclude);
        }

        return array_values($connector->tools());
    }
}
