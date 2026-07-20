<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Tests\Integration;

use NeuronAI\Tools\Tool;

/** Represents an independently reusable Symfony tool injected into a class-first agent. */
final class TestClassAgentTool extends Tool
{
    public function __construct()
    {
        parent::__construct('class_greeting', 'Return a greeting owned by the class-first agent.');
    }

    public function __invoke(): string
    {
        return 'Hello from the injected tool.';
    }
}
