<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Command;

use Errogaht\NeuronAiBundle\Agent\AgentFactory;
use Errogaht\NeuronAiBundle\Agent\AgentRunner;
use Errogaht\NeuronAiBundle\Async\AsyncAgentDispatcher;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Provides a smoke-test path for synchronous agents and the optional Messenger transport. */
#[AsCommand(name: 'neuron-ai:run', description: 'Run a configured agent')]
final class AgentRunCommand extends Command
{
    public function __construct(
        private readonly AgentRunner $runner,
        private readonly AgentFactory $agents,
        private readonly ContainerInterface $asyncServices,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('prompt', InputArgument::REQUIRED, 'User prompt')
            ->addOption('agent', 'a', InputOption::VALUE_REQUIRED, 'Configured agent name')
            ->addOption('thread', 't', InputOption::VALUE_REQUIRED, 'Application thread identifier')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Dispatch through Symfony Messenger');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $agent = $input->getOption('agent');
        if (!\is_string($agent) || '' === $agent) {
            $names = $this->agents->names();
            if (1 !== \count($names)) {
                $io->error('Pass --agent. Configured agents: '.implode(', ', $names));

                return Command::INVALID;
            }
            $agent = $names[0];
        }
        $prompt = (string) $input->getArgument('prompt');
        $thread = $input->getOption('thread');
        $thread = \is_string($thread) && '' !== $thread ? $thread : null;

        if ((bool) $input->getOption('async')) {
            if (!$this->asyncServices->has('dispatcher')) {
                $io->error('Enable neuron_ai.messenger before using --async.');

                return Command::INVALID;
            }
            $dispatcher = $this->asyncServices->get('dispatcher');
            if (!$dispatcher instanceof AsyncAgentDispatcher) {
                throw new \LogicException('The async dispatcher service has an invalid type.');
            }
            $io->success('Queued job '.$dispatcher->dispatch($agent, $prompt, $thread));

            return Command::SUCCESS;
        }

        $result = $this->runner->chat($agent, $prompt, $thread);
        $io->writeln($result->content() ?? json_encode($result, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));

        return Command::SUCCESS;
    }
}
