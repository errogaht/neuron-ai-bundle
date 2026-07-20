<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Command;

use Errogaht\NeuronAiBundle\Workflow\Async\WorkflowJobResultStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Reads a queued workflow result without depending on the configured transport. */
#[AsCommand(name: 'neuron-ai:workflow:status', description: 'Read the status or result of an async workflow job')]
final class WorkflowStatusCommand extends Command
{
    public function __construct(private readonly WorkflowJobResultStoreInterface $results)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('job-id', InputArgument::REQUIRED, 'Job ID returned by neuron-ai:workflow:run --async');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->results->get((string) $input->getArgument('job-id'));
        if (null === $result) {
            $io->error('Workflow job not found or its result has expired.');

            return Command::FAILURE;
        }
        $io->writeln(json_encode($result, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}
