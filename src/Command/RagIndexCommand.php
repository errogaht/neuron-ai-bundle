<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Command;

use Errogaht\NeuronAiBundle\Rag\RagIndexer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Exposes repeatable, deployment-friendly knowledge ingestion without embedding application logic in a command. */
#[AsCommand(name: 'neuron-ai:rag:index', description: 'Index documents through a configured RAG pipeline')]
final class RagIndexCommand extends Command
{
    public function __construct(private readonly RagIndexer $indexer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('pipeline', InputArgument::REQUIRED, 'Configured RAG pipeline name')
            ->addOption('reindex', null, InputOption::VALUE_NONE, 'Delete documents with matching source identifiers before indexing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pipeline = (string) $input->getArgument('pipeline');
        $result = $this->indexer->index($pipeline, (bool) $input->getOption('reindex'));
        $io = new SymfonyStyle($input, $output);
        $io->success(\sprintf(
            '%s %d documents from %d sources through "%s".',
            $result->reindexed ? 'Reindexed' : 'Indexed',
            $result->documents,
            $result->sources,
            $result->pipeline,
        ));

        return Command::SUCCESS;
    }
}
