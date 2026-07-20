<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Tests\Integration;

use Errogaht\NeuronAiBundle\Async\AgentJobResultStoreInterface;
use Errogaht\NeuronAiBundle\Async\AsyncAgentDispatcher;

/** Proves both optional Messenger services can be consumed through ordinary autowiring. */
final class TestAsyncConsumer
{
    public function __construct(
        public readonly AsyncAgentDispatcher $dispatcher,
        public readonly AgentJobResultStoreInterface $results,
    ) {
    }
}
