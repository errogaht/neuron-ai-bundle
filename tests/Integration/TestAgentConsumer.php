<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Tests\Integration;

use NeuronAI\Agent\AgentInterface;

/** Represents ordinary application code that consumes the configured default through autowiring. */
final class TestAgentConsumer
{
    public function __construct(public readonly AgentInterface $agent)
    {
    }
}
