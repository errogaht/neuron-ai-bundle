<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Agent\Event;

/** Signals the start of an application-visible agent execution. */
final class AgentRunStarted
{
    public function __construct(
        public readonly string $agent,
        public readonly ?string $threadId,
    ) {
    }
}
