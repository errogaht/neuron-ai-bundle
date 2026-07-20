<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Command;

use Errogaht\NeuronAiBundle\Agent\AgentFactory;
use Errogaht\NeuronAiBundle\Provider\ProviderRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Shows the compiled integration surface while ensuring provider credentials are redacted. */
#[AsCommand(name: 'neuron-ai:debug', description: 'Show configured Neuron AI providers and agents')]
final class DebugCommand extends Command
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly AgentFactory $agents,
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

        return Command::SUCCESS;
    }
}
