<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Async;

/** Immutable Messenger envelope; only scalar data crosses transport boundaries. */
final class RunAgentMessage
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        public readonly string $jobId,
        public readonly string $agent,
        public readonly string $input,
        public readonly ?string $threadId = null,
        public readonly array $attributes = [],
    ) {
    }
}
