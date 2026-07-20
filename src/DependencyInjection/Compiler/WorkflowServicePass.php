<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\DependencyInjection\Compiler;

use Errogaht\NeuronAiBundle\Workflow\Attribute\AsNeuronWorkflow;
use Errogaht\NeuronAiBundle\Workflow\PersistenceRegistry;
use Errogaht\NeuronAiBundle\Workflow\WorkflowFactory;
use NeuronAI\Workflow\WorkflowInterface;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

/** Adds class-owned workflows to the same isolated runtime registry as YAML-owned workflows. */
final class WorkflowServicePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(WorkflowFactory::class)) {
            return;
        }

        $factory = $container->getDefinition(WorkflowFactory::class);
        $config = $factory->getArgument(0);
        $locator = $factory->getArgument(1);
        if (!\is_array($config) || !$locator instanceof ServiceLocatorArgument) {
            throw new \LogicException('The Neuron workflow factory has an unexpected container definition.');
        }
        $services = $locator->getValues();
        $persistenceConfig = $container->getDefinition(PersistenceRegistry::class)->getArgument(0);
        if (!\is_array($persistenceConfig)) {
            throw new \LogicException('The Neuron persistence registry has an unexpected container definition.');
        }
        $defaultPersistence = $container->getDefinition(PersistenceRegistry::class)->getArgument(2);

        foreach ($container->findTaggedServiceIds(AsNeuronWorkflow::TAG) as $serviceId => $tags) {
            $definition = $container->getDefinition($serviceId);
            $class = $definition->getClass() ?? $serviceId;
            if (!is_a($class, WorkflowInterface::class, true)) {
                throw new InvalidArgumentException(\sprintf('Attributed Neuron workflow service "%s" must implement %s.', $serviceId, WorkflowInterface::class));
            }
            $definition->setShared(false);
            foreach ($tags as $tag) {
                $name = $tag['name'] ?? null;
                $persistence = $tag['persistence'] ?? null;
                $persistence ??= $defaultPersistence;
                if (!\is_string($name) || '' === trim($name)) {
                    throw new InvalidArgumentException(\sprintf('The "%s" tag on service "%s" requires a non-empty name.', AsNeuronWorkflow::TAG, $serviceId));
                }
                if (isset($config[$name])) {
                    throw new InvalidArgumentException(\sprintf('Neuron workflow name "%s" is registered more than once.', $name));
                }
                if (null !== $persistence && (!\is_string($persistence) || !isset($persistenceConfig[$persistence]))) {
                    throw new InvalidArgumentException(\sprintf('Neuron workflow "%s" references unknown persistence "%s".', $name, (string) $persistence));
                }

                $config[$name] = ['class' => $class, 'persistence' => $persistence, 'nodes' => [], 'middleware' => []];
                $services[$name] = new Reference($serviceId);
                $this->registerRuntimeService($container, $name);
            }
        }

        $default = $factory->getArgument(5);
        if (null !== $default && (!\is_string($default) || !isset($config[$default]))) {
            throw new InvalidArgumentException(\sprintf('Unknown neuron_ai.workflow.default "%s".', (string) $default));
        }

        $factory->setArgument(0, $config);
        $locator->setValues($services);
    }

    private function registerRuntimeService(ContainerBuilder $container, string $name): void
    {
        $id = 'neuron_ai.configured_workflow.'.$name;
        $container->setDefinition($id, (new Definition(WorkflowInterface::class))
            ->setFactory([new Reference(WorkflowFactory::class), 'create'])
            ->setArguments([$name])
            ->setShared(false));
        $container->registerAliasForArgument($id, WorkflowInterface::class, lcfirst(ContainerBuilder::camelize($name)).'Workflow');
    }
}
