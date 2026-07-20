<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Command;

use Errogaht\NeuronAiBundle\Async\AgentJobResultStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Reads an async result without depending on the application's transport or storage details. */
#[AsCommand(name: 'neuron-ai:status', description: 'Read the status or result of an async agent job')]
final class AgentStatusCommand extends Command
{
    public function __construct(private readonly AgentJobResultStoreInterface $results)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('job-id', InputArgument::REQUIRED, 'Job ID returned by neuron-ai:run --async');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->results->get((string) $input->getArgument('job-id'));
        if (null === $result) {
            $io->error('Job not found or its result has expired.');

            return Command::FAILURE;
        }
        $io->writeln(json_encode($result, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}
