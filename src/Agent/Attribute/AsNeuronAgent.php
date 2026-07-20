<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Agent\Attribute;

/** Marks an autoconfigured Symfony service as a reusable named Neuron agent. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsNeuronAgent
{
    public const TAG = 'neuron_ai.agent';

    public function __construct(public readonly ?string $name = null)
    {
        if (null !== $name && '' === trim($name)) {
            throw new \InvalidArgumentException('A Neuron agent name cannot be empty.');
        }
    }
}
