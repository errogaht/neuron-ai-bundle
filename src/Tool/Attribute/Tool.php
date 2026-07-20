<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Tool\Attribute;

/** Exposes one public service method as a Neuron tool within an AbstractToolGroup. */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Tool
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $description = null,
        public readonly ?int $maxRuns = null,
    ) {
        if (null !== $maxRuns && $maxRuns < 1) {
            throw new \InvalidArgumentException('A tool maxRuns value must be at least 1.');
        }
    }
}
