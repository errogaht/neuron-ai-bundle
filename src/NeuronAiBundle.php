<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle;

use Errogaht\NeuronAiBundle\Agent\AgentConfiguratorInterface;
use NeuronAI\Observability\ObserverInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/** Integrates Neuron's stateful runtime through fresh, container-built Symfony services. */
final class NeuronAiBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->registerForAutoconfiguration(AgentConfiguratorInterface::class)->addTag('neuron_ai.agent_configurator');
        $container->registerForAutoconfiguration(ObserverInterface::class)->addTag('neuron_ai.observer');
    }
}
