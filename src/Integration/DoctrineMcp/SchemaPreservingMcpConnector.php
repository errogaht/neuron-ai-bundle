<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Integration\DoctrineMcp;

use NeuronAI\MCP\McpConnector;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;

/**
 * Keeps the authoritative MCP input schema intact when exposing tools to an LLM.
 *
 * Neuron's generic MCP connector currently rebuilds array and object properties
 * with a shallow representation. That is sufficient for simple scalar tools but
 * removes nested fields, constraints and required lists from aggregate commands.
 * The generated top-level properties are still retained for callable argument
 * binding; only the provider-facing JSON Schema is replaced with the MCP source.
 */
final class SchemaPreservingMcpConnector extends McpConnector
{
    protected function createTool(array $item): ToolInterface
    {
        $tool = parent::createTool($item);
        $inputSchema = $item['inputSchema'] ?? null;

        if ($tool instanceof Tool && \is_array($inputSchema)) {
            $tool->setParameters(['parameters' => $this->providerSchema($inputSchema)]);
        }

        return $tool;
    }

    /**
     * OpenAI-compatible providers require an empty JSON Schema properties map
     * to be encoded as an object (`{}`), while PHP otherwise serializes it as
     * an array (`[]`) and the provider rejects the complete tool collection.
     *
     * @param array<string|int, mixed> $schema
     *
     * @return array<string|int, mixed>
     */
    private function providerSchema(array $schema): array
    {
        foreach ($schema as $key => $value) {
            if ('properties' === $key && [] === $value) {
                $schema[$key] = new \stdClass();
                continue;
            }

            if (\is_array($value)) {
                $schema[$key] = $this->providerSchema($value);
            }
        }

        return $schema;
    }
}
