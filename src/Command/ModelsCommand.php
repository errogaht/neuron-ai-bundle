<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Command;

use Errogaht\NeuronAiBundle\Provider\ModelCatalog;
use Errogaht\NeuronAiBundle\Provider\ProviderRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Gives developers a credential-safe way to discover models from the configured endpoint. */
#[AsCommand(name: 'neuron-ai:models', description: 'List models exposed by a configured AI provider')]
final class ModelsCommand extends Command
{
    public function __construct(
        private readonly ModelCatalog $catalog,
        private readonly ProviderRegistry $providers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('provider', InputArgument::OPTIONAL, 'Configured provider name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('provider');
        if (!\is_string($name) || '' === $name) {
            $names = $this->providers->names();
            if (1 !== \count($names)) {
                $io->error('Pass a provider name. Configured providers: '.implode(', ', $names));

                return Command::INVALID;
            }
            $name = $names[0];
        }

        try {
            $models = $this->catalog->models($name);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
        $io->title(\sprintf('Models from "%s"', $name));
        $io->listing($models);
        if ([] === $models) {
            $io->warning('The endpoint returned no recognizable model IDs.');
        }

        return Command::SUCCESS;
    }
}
