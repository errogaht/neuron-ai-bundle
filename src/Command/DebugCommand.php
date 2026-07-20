<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Command;

use Errogaht\NeuronAiBundle\Agent\AgentFactory;
use Errogaht\NeuronAiBundle\Provider\ProviderRegistry;
use Errogaht\NeuronAiBundle\Rag\EmbeddingProviderRegistry;
use Errogaht\NeuronAiBundle\Rag\RagIndexer;
use Errogaht\NeuronAiBundle\Rag\VectorStoreRegistry;
use Errogaht\NeuronAiBundle\Workflow\PersistenceRegistry;
use Errogaht\NeuronAiBundle\Workflow\WorkflowFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Shows the compiled integration surface while ensuring provider credentials are redacted. */
#[AsCommand(name: 'neuron-ai:debug', description: 'Show configured Neuron AI providers, agents, RAG, and workflows')]
final class DebugCommand extends Command
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly AgentFactory $agents,
        private readonly EmbeddingProviderRegistry $embeddings,
        private readonly VectorStoreRegistry $vectorStores,
        private readonly RagIndexer $indexer,
        private readonly WorkflowFactory $workflows,
        private readonly PersistenceRegistry $persistence,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = [];
        foreach ($this->providers->names() as $name) {
            $config = $this->providers->describe($name);
            $rows[] = [$name, (string) $config['type'], (string) ($config['model'] ?? ''), (string) ($config['base_url'] ?? '')];
        }
        $io->title('Neuron AI configuration');
        $io->table(['Provider', 'Type', 'Model', 'Base URL'], $rows);
        $io->section('Agents');
        $io->listing($this->agents->names());
        $io->section('RAG');
        $io->table(
            ['Embeddings', 'Type', 'Model'],
            array_map(function (string $name): array {
                $config = $this->embeddings->describe($name);

                return [$name, (string) $config['type'], (string) ($config['model'] ?? '')];
            }, $this->embeddings->names()),
        );
        $io->table(
            ['Vector store', 'Type'],
            array_map(function (string $name): array {
                $config = $this->vectorStores->describe($name);

                return [$name, (string) $config['type']];
            }, $this->vectorStores->names()),
        );
        $io->writeln('Pipelines: '.([] === $this->indexer->names() ? '(none)' : implode(', ', $this->indexer->names())));
        $io->section('Workflows');
        $io->listing($this->workflows->names());
        $io->table(
            ['Persistence', 'Type'],
            array_map(function (string $name): array {
                $config = $this->persistence->describe($name);

                return [$name, (string) $config['type']];
            }, $this->persistence->names()),
        );

        return Command::SUCCESS;
    }
}
