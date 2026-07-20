<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Agent\Event;

/** Carries an execution failure without exposing credentials or prompt content. */
final class AgentRunFailed
{
    public function __construct(
        public readonly string $agent,
        public readonly \Throwable $exception,
        public readonly ?string $threadId,
    ) {
    }
}
