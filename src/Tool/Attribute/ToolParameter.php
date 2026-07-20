<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Tool\Attribute;

use NeuronAI\Tools\PropertyType;

/** Adds model-facing metadata or an explicit schema type to a tool method parameter. */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class ToolParameter
{
    /** @param list<string|int|float> $enum */
    public function __construct(
        public readonly ?string $description = null,
        public readonly ?PropertyType $type = null,
        public readonly array $enum = [],
        public readonly ?bool $required = null,
    ) {
    }
}
