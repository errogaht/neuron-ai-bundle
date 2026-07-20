<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Tests\Integration;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Testing\FakeAIProvider;

/** Creates runtime-only Neuron test objects without embedding objects in the compiled container. */
final class TestServiceFactory
{
    public static function provider(): FakeAIProvider
    {
        return new FakeAIProvider(new AssistantMessage('Bundle response'));
    }
}
