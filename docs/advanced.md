# Advanced Neuron integration

The bundle configures the common graph — provider, agent, instructions and tools — but returns native Neuron objects. Features that are application-specific stay in normal Symfony services.

## Custom agent constructors

Configured agent classes are autowired and non-shared:

```php
final class AnalystAgent extends \NeuronAI\Agent\Agent
{
    public function __construct(private readonly DomainKnowledge $knowledge)
    {
    }
}
```

```yaml
neuron_ai:
    agents:
        analyst:
            class: App\Ai\AnalystAgent
            provider: main
```

The bundle constructs `AnalystAgent`, then applies the configured Neuron provider, instructions, tools and observers.

## Dynamic history and tenant context

An `AgentConfiguratorInterface` runs after static YAML configuration for every fresh agent. Use its `AgentContext` for thread IDs and serializable attributes:

```php
final class HistoryConfigurator implements AgentConfiguratorInterface
{
    public function __construct(private readonly ChatHistoryFactory $histories)
    {
    }

    public function configure(AgentInterface $agent, AgentContext $context): void
    {
        if ($context->threadId !== null) {
            $agent->setChatHistory($this->histories->forThread($context->threadId));
        }
    }
}
```

Keep authorization in trusted application code. Context attributes are routing inputs, not proof that an identity may access a tenant or record.

## RAG, embeddings and vector stores

Register Neuron embedding providers, vector stores, data loaders and RAG dependencies under `services:` as normal Symfony services. Inject them into your RAG agent subclass and reference that class from `neuron_ai.agents`.

This design avoids duplicating Neuron's rapidly evolving adapter configuration and allows third-party adapters to work immediately through autowiring.

## Workflows and persistence

Neuron workflows that are not agents are ordinary services and do not need the agent factory. Autowire their nodes, middleware and persistence implementation directly. Agent subclasses can receive a persistence factory in the constructor or attach request-specific persistence in a configurator.

## MCP

Neuron's MCP connector/toolkit can be registered as a Symfony service and listed in an agent's `tools`. Connection lifecycle and credentials belong in that service. This bundle does not open MCP transports automatically.

## Native observers

Any autoconfigured service implementing `NeuronAI\Observability\ObserverInterface` is attached to every configured `NeuronAI\Agent\Agent` instance. Use explicit service tags and priorities when ordering matters:

```yaml
services:
    App\Ai\SafeObserver:
        tags:
            - { name: neuron_ai.observer, priority: 100 }
```

Observers can see model events. Apply the same data-retention rules as for prompts and responses.

## Custom providers and future adapters

Use provider type `service` for a Neuron provider not represented by a built-in shorthand:

```yaml
services:
    App\Ai\Provider\CloudProvider:
        autowire: true

neuron_ai:
    providers:
        cloud:
            type: service
            service: App\Ai\Provider\CloudProvider
```

This escape hatch is also appropriate for provider factories, custom HTTP signing, rotating credentials and new Neuron releases.
