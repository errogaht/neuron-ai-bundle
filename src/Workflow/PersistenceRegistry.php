<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Workflow;

use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use Psr\Container\ContainerInterface;

/** Owns shared persistence backends so interrupted workflows can resume in a later service instance. */
final class PersistenceRegistry
{
    /** @var array<string, PersistenceInterface> */
    private array $instances = [];

    /** @param array<string, array<string, mixed>> $config */
    public function __construct(
        private readonly array $config,
        private readonly ContainerInterface $services,
        private readonly ?string $defaultPersistence = null,
    ) {
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->config);
    }

    /** @return array<string, mixed> */
    public function describe(string $name): array
    {
        return $this->configuration($name);
    }

    public function get(?string $name = null): PersistenceInterface
    {
        $name ??= $this->defaultPersistence;
        if (null === $name || '' === $name) {
            throw new \InvalidArgumentException('No persistence backend was selected and no workflow.default_persistence is configured.');
        }

        return $this->instances[$name] ??= $this->create($name, $this->configuration($name));
    }

    /** @param array<string, mixed> $config */
    private function create(string $name, array $config): PersistenceInterface
    {
        $type = (string) $config['type'];
        if ('service' === $type) {
            $serviceId = $this->required($config, 'service', $name);
            $service = $this->services->get($serviceId);
            if (!$service instanceof PersistenceInterface) {
                throw new \LogicException(\sprintf('Workflow persistence service "%s" must implement %s.', $serviceId, PersistenceInterface::class));
            }

            return $service;
        }
        if ('database' === $type) {
            return new DatabasePersistence(
                $this->resolvePdo($this->required($config, 'connection', $name)),
                $this->safeTable((string) $config['table'], $name),
            );
        }
        if ('file' === $type) {
            $directory = $this->required($config, 'directory', $name);
            if (!is_dir($directory) && (bool) $config['create_directory'] && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException(\sprintf('Unable to create workflow persistence directory "%s".', $directory));
            }

            return new FilePersistence($directory, (string) $config['prefix'], (string) $config['extension']);
        }

        return new InMemoryPersistence();
    }

    private function resolvePdo(string $serviceId): \PDO
    {
        $connection = $this->services->get($serviceId);
        if ($connection instanceof \PDO) {
            return $connection;
        }
        if (\is_object($connection) && method_exists($connection, 'getNativeConnection')) {
            $connection = $connection->getNativeConnection();
        }
        if (!$connection instanceof \PDO) {
            throw new \LogicException(\sprintf('Workflow database connection "%s" must be PDO or expose getNativeConnection() returning PDO.', $serviceId));
        }

        return $connection;
    }

    private function safeTable(string $table, string $name): string
    {
        // Neuron interpolates the identifier, so reject user-controlled SQL fragments before construction.
        if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException(\sprintf('Workflow persistence "%s" has an unsafe table name.', $name));
        }

        return $table;
    }

    /** @return array<string, mixed> */
    private function configuration(string $name): array
    {
        return $this->config[$name] ?? throw new \InvalidArgumentException(\sprintf('Unknown workflow persistence "%s".', $name));
    }

    /** @param array<string, mixed> $config */
    private function required(array $config, string $key, string $name): string
    {
        $value = (string) ($config[$key] ?? '');
        if ('' === $value) {
            throw new \InvalidArgumentException(\sprintf('Workflow persistence "%s" requires "%s".', $name, $key));
        }

        return $value;
    }
}
