<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Workflow;

use NeuronAI\Observability\ObserverInterface;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowInterface;
use NeuronAI\Workflow\WorkflowState;
use Psr\Container\ContainerInterface;

/** Creates isolated workflow instances while deliberately sharing only their persistence backend. */
final class WorkflowFactory
{
    /**
     * @param array<string, array<string, mixed>> $config
     * @param iterable<ObserverInterface>         $observers
     */
    public function __construct(
        private readonly array $config,
        private readonly ContainerInterface $workflows,
        private readonly ContainerInterface $components,
        private readonly PersistenceRegistry $persistence,
        private readonly iterable $observers,
        private readonly ?string $defaultWorkflow = null,
    ) {
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->config);
    }

    public function resolveName(?string $name = null): string
    {
        $name ??= $this->defaultWorkflow;
        if (null === $name || '' === $name) {
            throw new \InvalidArgumentException('No workflow was selected and no workflow.default is configured.');
        }
        if (!isset($this->config[$name])) {
            throw new \InvalidArgumentException(\sprintf('Unknown workflow "%s".', $name));
        }

        return $name;
    }

    public function create(?string $name = null, ?string $resumeToken = null, ?WorkflowState $state = null): WorkflowInterface
    {
        $name = $this->resolveName($name);
        if (null !== $resumeToken && 1 !== preg_match('/^[A-Za-z0-9_-]{1,200}$/', $resumeToken)) {
            // FilePersistence uses the token in a path; one strict portable format protects every backend uniformly.
            throw new \InvalidArgumentException('A workflow resume token may contain only letters, digits, underscores, and hyphens.');
        }
        $workflow = $this->workflows->get($name);
        if (!$workflow instanceof WorkflowInterface) {
            throw new \LogicException(\sprintf('Workflow service "%s" must implement %s.', $name, WorkflowInterface::class));
        }

        $config = $this->config[$name];
        $persistence = $config['persistence'] ?? null;
        if (null !== $persistence) {
            $workflow->setPersistence($this->persistence->get((string) $persistence), $resumeToken);
        } elseif (null !== $resumeToken) {
            throw new \LogicException(\sprintf('Workflow "%s" cannot resume without configured persistence.', $name));
        }
        if (null !== $state) {
            $workflow->setState($state);
        }
        foreach ((array) ($config['nodes'] ?? []) as $serviceId) {
            $workflow->addNode($this->isolatedNode((string) $serviceId));
        }
        foreach ((array) ($config['middleware'] ?? []) as $serviceId) {
            $workflow->addGlobalMiddleware($this->isolatedMiddleware((string) $serviceId));
        }
        foreach ($this->observers as $observer) {
            $workflow->observe($observer);
        }

        return $workflow;
    }

    private function isolatedNode(string $serviceId): NodeInterface
    {
        $component = $this->components->get($serviceId);
        if (!$component instanceof NodeInterface) {
            throw new \LogicException(\sprintf('Workflow node "%s" must implement %s.', $serviceId, NodeInterface::class));
        }
        if (!(new \ReflectionObject($component))->isCloneable()) {
            throw new \LogicException(\sprintf('Workflow node "%s" must be cloneable for execution isolation.', $serviceId));
        }

        // Nodes carry event, state, and checkpoint data; only an untouched clone may enter a new execution.
        return clone $component;
    }

    private function isolatedMiddleware(string $serviceId): WorkflowMiddleware
    {
        $component = $this->components->get($serviceId);
        if (!$component instanceof WorkflowMiddleware) {
            throw new \LogicException(\sprintf('Workflow middleware "%s" must implement %s.', $serviceId, WorkflowMiddleware::class));
        }
        if (!(new \ReflectionObject($component))->isCloneable()) {
            throw new \LogicException(\sprintf('Workflow middleware "%s" must be cloneable for execution isolation.', $serviceId));
        }

        return clone $component;
    }
}
