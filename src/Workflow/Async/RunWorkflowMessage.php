<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Workflow\Async;

/** Transport-safe workflow start request; custom Events and resume objects stay in application-owned messages. */
final class RunWorkflowMessage
{
    /** @param array<string, mixed> $state */
    public function __construct(
        public readonly string $jobId,
        public readonly string $workflow,
        public readonly array $state = [],
    ) {
    }
}
