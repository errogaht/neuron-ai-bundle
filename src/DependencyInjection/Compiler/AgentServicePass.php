<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\DependencyInjection\Compiler;

use Errogaht\NeuronAiBundle\Agent\AgentFactory;
use Errogaht\NeuronAiBundle\Agent\Attribute\AsNeuronAgent;
use NeuronAI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

/** Adds class-owned agent services to the same named runtime registry as YAML-owned agents. */
final class AgentServicePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(AgentFactory::class)) {
            return;
        }

        $factory = $container->getDefinition(AgentFactory::class);
        $config = $factory->getArgument(0);
        $locator = $factory->getArgument(1);
        if (!\is_array($config) || !$locator instanceof ServiceLocatorArgument) {
            throw new \LogicException('The Neuron agent factory has an unexpected container definition.');
        }
        $services = $locator->getValues();

        foreach ($container->findTaggedServiceIds(AsNeuronAgent::TAG) as $serviceId => $tags) {
            $agentDefinition = $container->getDefinition($serviceId);
            $agentClass = $agentDefinition->getClass() ?? $serviceId;
            if (!is_a($agentClass, AgentInterface::class, true)) {
                throw new InvalidArgumentException(\sprintf('Attributed Neuron agent service "%s" must implement %s.', $serviceId, AgentInterface::class));
            }
            $agentDefinition->setShared(false);
            foreach ($tags as $tag) {
                $name = $tag['name'] ?? null;
                if (!\is_string($name) || '' === trim($name)) {
                    throw new InvalidArgumentException(\sprintf('The "%s" tag on service "%s" requires a non-empty name.', AsNeuronAgent::TAG, $serviceId));
                }
                if (isset($config[$name])) {
                    throw new InvalidArgumentException(\sprintf('Neuron agent name "%s" is registered more than once.', $name));
                }

                // Null provider/instructions preserve the class-owned provider(), instructions(), and tools() methods.
                $config[$name] = [
                    'class' => $agentClass,
                    'provider' => null,
                    'instructions' => null,
                    'tools' => [],
                    'tool_max_runs' => null,
                    'parallel_tool_calls' => null,
                    'doctrine_mcp' => ['enabled' => false, 'only' => [], 'exclude' => []],
                ];
                $services[$name] = new Reference($serviceId);
                $this->registerRuntimeService($container, $name);
            }
        }

        $defaultAgent = $factory->getArgument(6);
        if (null !== $defaultAgent && (!\is_string($defaultAgent) || !isset($config[$defaultAgent]))) {
            throw new InvalidArgumentException(\sprintf('Unknown neuron_ai.default_agent "%s".', (string) $defaultAgent));
        }

        $factory->setArgument(0, $config);
        $locator->setValues($services);
    }

    private function registerRuntimeService(ContainerBuilder $container, string $name): void
    {
        $id = 'neuron_ai.configured_agent.'.$name;
        $container->setDefinition($id, (new Definition(AgentInterface::class))
            ->setFactory([new Reference(AgentFactory::class), 'create'])
            ->setArguments([$name])
            ->setShared(false));
        $container->registerAliasForArgument($id, AgentInterface::class, $this->argumentName($name));
    }

    private function argumentName(string $name): string
    {
        return lcfirst(ContainerBuilder::camelize($name)).'Agent';
    }
}
