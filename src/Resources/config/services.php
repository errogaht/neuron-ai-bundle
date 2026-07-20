<?php

declare(strict_types=1);

use Errogaht\NeuronAiBundle\Agent\AgentFactory;
use Errogaht\NeuronAiBundle\Agent\AgentRunner;
use Errogaht\NeuronAiBundle\Command\AgentRunCommand;
use Errogaht\NeuronAiBundle\Command\DebugCommand;
use Errogaht\NeuronAiBundle\Command\ModelsCommand;
use Errogaht\NeuronAiBundle\Command\RagIndexCommand;
use Errogaht\NeuronAiBundle\Command\WorkflowRunCommand;
use Errogaht\NeuronAiBundle\Provider\ModelCatalog;
use Errogaht\NeuronAiBundle\Provider\ProviderRegistry;
use Errogaht\NeuronAiBundle\Rag\EmbeddingProviderRegistry;
use Errogaht\NeuronAiBundle\Rag\RagIndexer;
use Errogaht\NeuronAiBundle\Rag\VectorStoreRegistry;
use Errogaht\NeuronAiBundle\Workflow\PersistenceRegistry;
use Errogaht\NeuronAiBundle\Workflow\WorkflowFactory;
use Errogaht\NeuronAiBundle\Workflow\WorkflowRunner;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_locator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(ProviderRegistry::class)->public();
    $services->set(EmbeddingProviderRegistry::class)->public();
    $services->set(VectorStoreRegistry::class)->public();
    $services->set(RagIndexer::class)->public();
    $services->set(PersistenceRegistry::class)->public();
    $services->set(WorkflowFactory::class)->public();
    $services->set(WorkflowRunner::class)
        ->args([
            service(WorkflowFactory::class),
            service('event_dispatcher'),
            service('logger'),
        ])
        ->public();
    $services->set(AgentFactory::class)->public();
    $services->set(AgentRunner::class)
        ->args([
            service(AgentFactory::class),
            service('event_dispatcher'),
            service('logger'),
        ])
        ->public();
    $services->set(ModelCatalog::class)
        ->args([service(ProviderRegistry::class), service('http_client')]);
    $services->set(ModelsCommand::class)
        ->args([service(ModelCatalog::class), service(ProviderRegistry::class)])
        ->tag('console.command');
    $services->set(DebugCommand::class)
        ->args([
            service(ProviderRegistry::class),
            service(AgentFactory::class),
            service(EmbeddingProviderRegistry::class),
            service(VectorStoreRegistry::class),
            service(RagIndexer::class),
            service(WorkflowFactory::class),
            service(PersistenceRegistry::class),
        ])
        ->tag('console.command');
    $services->set(AgentRunCommand::class)
        ->args([service(AgentRunner::class), service(AgentFactory::class), service_locator([])])
        ->tag('console.command');
    $services->set(RagIndexCommand::class)
        ->args([service(RagIndexer::class)])
        ->tag('console.command');
    $services->set(WorkflowRunCommand::class)
        ->args([service(WorkflowRunner::class), service(WorkflowFactory::class), service_locator([])])
        ->tag('console.command');
};
