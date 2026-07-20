<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Integration\DoctrineMcp;

use Mcp\Server\Transport\BaseTransport;

/**
 * Single-request MCP server transport that preserves its SDK session between Neuron client calls.
 *
 * @extends BaseTransport<null>
 */
final class DoctrineMcpServerTransport extends BaseTransport
{
    private ?string $request = null;

    /** @var list<array<string, mixed>> */
    private array $responses = [];

    public function accept(string $request): void
    {
        if (null !== $this->request) {
            throw new \LogicException('The Doctrine MCP loopback server already has a pending request.');
        }

        $this->request = $request;
    }

    /** @return null */
    public function listen(): mixed
    {
        if (null === $this->request) {
            throw new \LogicException('The Doctrine MCP loopback server has no request to process.');
        }

        $request = $this->request;
        $this->request = null;
        $this->handleMessage($request, $this->sessionId);
        foreach ($this->getOutgoingMessages($this->sessionId) as $message) {
            $this->capture($message['message']);
        }

        return null;
    }

    public function send(string $data, array $context): void
    {
        if (isset($context['session_id'])) {
            $this->sessionId = $context['session_id'];
        }
        $this->capture($data);
    }

    /** Server::run() closes after every request; loopback sessions intentionally survive until the Neuron connector disconnects. */
    public function close(): void
    {
    }

    /** @return array<string, mixed> */
    public function receive(): array
    {
        $response = array_shift($this->responses);
        if (!\is_array($response)) {
            throw new \LogicException('The Doctrine MCP server did not return a response.');
        }

        return $response;
    }

    public function endSession(): void
    {
        $this->handleSessionEnd($this->sessionId);
        $this->sessionId = null;
        $this->responses = [];
    }

    private function capture(string $data): void
    {
        $decoded = json_decode($data, true, 512, \JSON_THROW_ON_ERROR);
        // Neuron's current MCP client is request/response based. Notifications are deliberately
        // ignored here so they cannot be mistaken for the response to the next tool invocation.
        if (\is_array($decoded) && \array_key_exists('id', $decoded)) {
            $this->responses[] = $decoded;
        }
    }
}
