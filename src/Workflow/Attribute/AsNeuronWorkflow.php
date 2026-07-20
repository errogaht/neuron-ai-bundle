<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Workflow\Attribute;

/** Marks a class-owned Workflow as a named, non-shared Symfony runtime service. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsNeuronWorkflow
{
    public const TAG = 'neuron_ai.workflow';

    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $persistence = null,
    ) {
        if (null !== $name && '' === trim($name)) {
            throw new \InvalidArgumentException('A Neuron workflow name cannot be empty.');
        }
        if (null !== $persistence && '' === trim($persistence)) {
            throw new \InvalidArgumentException('A Neuron workflow persistence name cannot be empty.');
        }
    }
}
