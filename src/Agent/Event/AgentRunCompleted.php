<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Agent\Event;

use Errogaht\NeuronAiBundle\Agent\AgentRunResult;

/** Carries the normalized result after a successful execution. */
final class AgentRunCompleted
{
    public function __construct(
        public readonly AgentRunResult $result,
        public readonly ?string $threadId,
    ) {
    }
}
