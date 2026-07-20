<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Command;

use Errogaht\NeuronAiBundle\Workflow\Async\AsyncWorkflowDispatcher;
use Errogaht\NeuronAiBundle\Workflow\WorkflowFactory;
use Errogaht\NeuronAiBundle\Workflow\WorkflowRunner;
use NeuronAI\Workflow\WorkflowState;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Provides an operational smoke-test path for default-StartEvent workflows and Messenger dispatch. */
#[AsCommand(name: 'neuron-ai:workflow:run', description: 'Run a configured Neuron workflow')]
final class WorkflowRunCommand extends Command
{
    public function __construct(
        private readonly WorkflowRunner $runner,
        private readonly WorkflowFactory $workflows,
        private readonly ContainerInterface $asyncServices,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('workflow', InputArgument::OPTIONAL, 'Configured workflow name')
            ->addOption('state', null, InputOption::VALUE_REQUIRED, 'Initial state as a JSON object', '{}')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Dispatch through Symfony Messenger');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $workflow = $input->getArgument('workflow');
        $workflow = \is_string($workflow) && '' !== $workflow ? $workflow : null;
        try {
            $workflow = $this->workflows->resolveName($workflow);
            $stateJson = (string) $input->getOption('state');
            $state = json_decode($stateJson, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\InvalidArgumentException|\JsonException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }
        if (!\is_array($state) || !str_starts_with(ltrim($stateJson), '{')) {
            $io->error('--state must be a JSON object.');

            return Command::INVALID;
        }

        if ((bool) $input->getOption('async')) {
            if (!$this->asyncServices->has('dispatcher')) {
                $io->error('Enable neuron_ai.messenger before using --async.');

                return Command::INVALID;
            }
            $dispatcher = $this->asyncServices->get('dispatcher');
            if (!$dispatcher instanceof AsyncWorkflowDispatcher) {
                throw new \LogicException('The async workflow dispatcher service has an invalid type.');
            }
            $io->success('Queued workflow job '.$dispatcher->dispatch($workflow, $state));

            return Command::SUCCESS;
        }

        $io->writeln(json_encode($this->runner->run($workflow, new WorkflowState($state)), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}
