<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Agent;

/** Carries request-specific identity and metadata into fresh agent configuration. */
final class AgentContext
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        public readonly string $agent,
        public readonly ?string $threadId = null,
        public readonly array $attributes = [],
    ) {
    }
}
