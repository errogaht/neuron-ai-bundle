<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Tests\Integration;

use NeuronAI\Agent\AgentInterface;

/** Proves both class type-hints and Symfony named-agent arguments resolve class-first agents. */
final class TestClassAgentConsumer
{
    public function __construct(
        public readonly TestClassAgent $directAgent,
        public readonly AgentInterface $classAssistantAgent,
    ) {
    }
}
