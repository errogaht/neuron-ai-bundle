<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle;

use Errogaht\NeuronAiBundle\Agent\AgentConfiguratorInterface;
use Errogaht\NeuronAiBundle\Agent\Attribute\AsNeuronAgent;
use Errogaht\NeuronAiBundle\DependencyInjection\Compiler\AgentServicePass;
use Errogaht\NeuronAiBundle\DependencyInjection\Compiler\WorkflowServicePass;
use Errogaht\NeuronAiBundle\Workflow\Attribute\AsNeuronWorkflow;
use NeuronAI\Observability\ObserverInterface;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/** Integrates Neuron's stateful runtime through fresh, container-built Symfony services. */
final class NeuronAiBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new AgentServicePass());
        $container->addCompilerPass(new WorkflowServicePass());
        $container->registerAttributeForAutoconfiguration(AsNeuronAgent::class, static function (ChildDefinition $definition, AsNeuronAgent $attribute, \Reflector $reflector): void {
            if (!$reflector instanceof \ReflectionClass) {
                throw new \LogicException('AsNeuronAgent can only configure classes.');
            }
            $shortName = preg_replace('/Agent$/', '', $reflector->getShortName()) ?: $reflector->getShortName();
            $name = $attribute->name ?? ContainerBuilder::underscore($shortName);
            // Neuron agents contain mutable workflow state and must never be shared across requests or jobs.
            $definition->setShared(false)->addTag(AsNeuronAgent::TAG, ['name' => $name]);
        });
        $container->registerAttributeForAutoconfiguration(AsNeuronWorkflow::class, static function (ChildDefinition $definition, AsNeuronWorkflow $attribute, \Reflector $reflector): void {
            if (!$reflector instanceof \ReflectionClass) {
                throw new \LogicException('AsNeuronWorkflow can only configure classes.');
            }
            $shortName = preg_replace('/Workflow$/', '', $reflector->getShortName()) ?: $reflector->getShortName();
            $name = $attribute->name ?? ContainerBuilder::underscore($shortName);
            // Workflow, node, and checkpoint state must remain isolated between HTTP requests and Messenger jobs.
            $definition->setShared(false)->addTag(AsNeuronWorkflow::TAG, ['name' => $name, 'persistence' => $attribute->persistence]);
        });
        $container->registerForAutoconfiguration(AgentConfiguratorInterface::class)->addTag('neuron_ai.agent_configurator');
        $container->registerForAutoconfiguration(ObserverInterface::class)->addTag('neuron_ai.observer');
        // Native nodes retain event, state, checkpoints, and resume feedback during execution.
        $container->registerForAutoconfiguration(NodeInterface::class)->setShared(false);
        // Middleware may retain per-node policy decisions and follows the same execution isolation boundary.
        $container->registerForAutoconfiguration(WorkflowMiddleware::class)->setShared(false);
    }
}
