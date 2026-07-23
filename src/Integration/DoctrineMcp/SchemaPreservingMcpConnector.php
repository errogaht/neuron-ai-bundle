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
            $tool->setParameters(['parameters' => $inputSchema]);
        }

        return $tool;
    }
}
