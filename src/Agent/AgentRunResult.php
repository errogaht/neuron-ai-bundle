<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Agent;

/** Stable, serializable boundary for HTTP, console, Messenger and application callers. */
final class AgentRunResult implements \JsonSerializable
{
    /** @param array<string, mixed> $message */
    public function __construct(
        public readonly string $agent,
        public readonly array $message,
        public readonly float $durationSeconds,
    ) {
    }

    public function content(): ?string
    {
        foreach ((array) ($this->message['content'] ?? []) as $block) {
            $type = $block['type'] ?? null;
            $type = $type instanceof \BackedEnum ? $type->value : $type;
            if ('text' === $type) {
                return (string) ($block['content'] ?? $block['text'] ?? '');
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['agent' => $this->agent, 'message' => $this->message, 'duration_seconds' => $this->durationSeconds];
    }
}
