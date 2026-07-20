<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Integration\DoctrineMcp;

use Mcp\Server;
use NeuronAI\MCP\McpException;
use NeuronAI\MCP\McpTransportInterface;

/** Adapts Neuron's client transport to one already-configured PHP MCP server in the same Symfony container. */
final class DoctrineMcpLoopbackTransport implements McpTransportInterface
{
    private readonly DoctrineMcpServerTransport $serverTransport;
    private bool $connected = false;

    public function __construct(private readonly Server $server)
    {
        $this->serverTransport = new DoctrineMcpServerTransport();
    }

    public function connect(): void
    {
        $this->connected = true;
    }

    public function send(array $data): void
    {
        if (!$this->connected) {
            throw new McpException('The in-process Doctrine MCP transport is not connected.');
        }

        $this->serverTransport->accept(json_encode($data, \JSON_THROW_ON_ERROR));
        $this->server->run($this->serverTransport);
    }

    public function receive(): array
    {
        return $this->serverTransport->receive();
    }

    public function disconnect(): void
    {
        if ($this->connected) {
            $this->serverTransport->endSession();
            $this->connected = false;
        }
    }
}
