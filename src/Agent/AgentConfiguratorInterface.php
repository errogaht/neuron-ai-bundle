<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Agent;

use NeuronAI\Agent\AgentInterface;

/** Adds request-specific history, persistence, tools or authorization to a fresh agent. */
interface AgentConfiguratorInterface
{
    public function configure(AgentInterface $agent, AgentContext $context): void;
}
