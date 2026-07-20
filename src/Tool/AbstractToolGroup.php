<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Tool;

use Errogaht\NeuronAiBundle\Tool\Attribute\Tool as ToolAttribute;
use Errogaht\NeuronAiBundle\Tool\Attribute\ToolParameter;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool as NeuronTool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\AbstractToolkit;
use NeuronAI\Tools\ToolProperty;

/**
 * Turns explicitly attributed methods of one autowired Symfony service into a native Neuron toolkit.
 * Only scalar, array and backed-enum parameters are inferred; complex DTOs should use native Tool classes.
 */
abstract class AbstractToolGroup extends AbstractToolkit
{
    /** @return list<ToolInterface> */
    final public function provide(): array
    {
        $tools = [];
        $names = [];
        $reflection = new \ReflectionObject($this);
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(ToolAttribute::class);
            if ([] === $attributes) {
                continue;
            }

            $metadata = $attributes[0]->newInstance();
            $name = $metadata->name ?? self::snakeCase($method->getName());
            if (isset($names[$name])) {
                throw new \LogicException(\sprintf('Tool group %s declares duplicate tool name "%s".', static::class, $name));
            }
            $names[$name] = true;

            $tool = new NeuronTool($name, $metadata->description, $this->properties($method));
            $tool->setCallable($this->callable($method));
            if (null !== $metadata->maxRuns) {
                $tool->setMaxRuns($metadata->maxRuns);
            }
            $tools[] = $tool;
        }

        if ([] === $tools) {
            throw new \LogicException(\sprintf('Tool group %s has no public methods marked with #[Tool].', static::class));
        }

        return $tools;
    }

    /** @return list<ToolProperty> */
    private function properties(\ReflectionMethod $method): array
    {
        $properties = [];
        foreach ($method->getParameters() as $parameter) {
            $attributes = $parameter->getAttributes(ToolParameter::class);
            $metadata = [] !== $attributes ? $attributes[0]->newInstance() : new ToolParameter();
            [$type, $enum] = $this->schema($parameter, $metadata);
            $required = $metadata->required ?? (!$parameter->allowsNull() && !$parameter->isDefaultValueAvailable());
            $properties[] = new ToolProperty(
                $parameter->getName(),
                $type,
                $metadata->description,
                $required,
                [] !== $metadata->enum ? $metadata->enum : $enum,
            );
        }

        return $properties;
    }

    /**
     * @return array{PropertyType, list<string|int>}
     */
    private function schema(\ReflectionParameter $parameter, ToolParameter $metadata): array
    {
        if (null !== $metadata->type) {
            return [$metadata->type, []];
        }

        $type = $parameter->getType();
        if ($type instanceof \ReflectionUnionType) {
            $names = array_values(array_filter(array_map(
                static fn (\ReflectionType $part): ?string => $part instanceof \ReflectionNamedType && 'null' !== $part->getName() ? $part->getName() : null,
                $type->getTypes(),
            )));
            if ([] !== $names && [] === array_diff($names, ['int', 'float'])) {
                return [PropertyType::NUMBER, []];
            }
            throw $this->unsupportedParameter($parameter);
        }
        if (!$type instanceof \ReflectionNamedType) {
            throw $this->unsupportedParameter($parameter);
        }

        $name = $type->getName();
        if (enum_exists($name) && is_subclass_of($name, \BackedEnum::class)) {
            $values = array_map(static fn (\BackedEnum $case): string|int => $case->value, $name::cases());
            $propertyType = \is_int($values[0] ?? null) ? PropertyType::INTEGER : PropertyType::STRING;

            return [$propertyType, $values];
        }

        return [match ($name) {
            'string' => PropertyType::STRING,
            'int' => PropertyType::INTEGER,
            'float' => PropertyType::NUMBER,
            'bool' => PropertyType::BOOLEAN,
            'array' => PropertyType::ARRAY,
            default => throw $this->unsupportedParameter($parameter),
        }, []];
    }

    /**
     * Neuron supplies named arguments and normalizes absent optional values to null.
     * This adapter restores PHP defaults and converts backed-enum values before invocation.
     */
    private function callable(\ReflectionMethod $method): \Closure
    {
        return function (...$arguments) use ($method): mixed {
            $resolved = [];
            foreach ($method->getParameters() as $parameter) {
                $value = $arguments[$parameter->getName()] ?? null;
                if (null === $value && $parameter->isDefaultValueAvailable()) {
                    $value = $parameter->getDefaultValue();
                }
                $type = $parameter->getType();
                if (null !== $value && $type instanceof \ReflectionNamedType) {
                    $class = $type->getName();
                    if (enum_exists($class) && is_subclass_of($class, \BackedEnum::class)) {
                        $value = $class::from($value);
                    }
                }
                $resolved[] = $value;
            }

            return $method->invokeArgs($this, $resolved);
        };
    }

    private function unsupportedParameter(\ReflectionParameter $parameter): \LogicException
    {
        return new \LogicException(\sprintf(
            'Cannot infer tool schema for %s::%s($%s). Use #[ToolParameter(type: ...)] for arrays/scalars or a native Neuron Tool for DTOs.',
            static::class,
            $parameter->getDeclaringFunction()->getName(),
            $parameter->getName(),
        ));
    }

    private static function snakeCase(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }
}
