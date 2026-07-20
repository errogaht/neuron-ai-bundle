# Advanced Neuron integration

The bundle supports both configuration-owned and class-owned agents while returning native Neuron objects. Features that are application-specific stay in normal Symfony services.

## Class-owned reusable agents

Use `#[AsNeuronAgent('name')]` when the prompt and tool composition belong to the application class. Override Neuron's native `instructions()` and `tools()` methods, inject the named provider and tool services, and call `parent::__construct()` before configuring the provider. The attributed service is automatically non-shared and is registered in `AgentFactory`, `AgentRunner`, console commands, Messenger and named autowiring.

The name is inferred when omitted: `SupportAgent` becomes `support`, and `OrderManagerAgent` becomes `order_manager`. The service must use Symfony autoconfiguration. Concrete-class injection returns the native class-owned service; factory/runner access additionally applies global agent configurators and observers.

## Configuration-owned custom agent constructors

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

## Tool groups

`AbstractToolGroup` implements Neuron's native `ToolkitInterface`. It is useful when one domain service owns several related operations and separate invokable classes would add boilerplate.

Only public methods carrying `#[Tool]` are exposed. Other public service methods remain invisible to the model. The group supports:

- inferred `string`, `int`, `float`, `bool` and `array` schemas;
- `int|float` as a numeric schema;
- backed enums, including automatic enum values and invocation conversion;
- nullable and default parameters;
- descriptions, explicit schema types, enum overrides and required overrides through `#[ToolParameter]`;
- tool-level `maxRuns`;
- toolkit `guidelines()`, `only()`, `exclude()` and `with()` from Neuron.

```php
final class CustomerTools extends AbstractToolGroup
{
    public function __construct(private CustomerService $customers)
    {
    }

    #[Tool(description: 'Read the current customer profile.')]
    public function profile(): array
    {
        return $this->customers->currentProfile();
    }

    #[Tool(description: 'Update the customer timezone.', maxRuns: 1)]
    public function changeTimezone(
        #[ToolParameter(description: 'IANA timezone identifier')] string $timezone,
    ): array {
        return $this->customers->changeCurrentTimezone($timezone);
    }
}
```

Authorization and tenant filtering must remain inside the injected domain service. Tool attributes describe capabilities to the model; they do not authorize the operation.

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

Built-in embedding providers and vector stores can be configured under `neuron_ai.rag`; every configured component receives a named autowiring alias. Use `type: service` for custom or client-backed Neuron adapters. Named loader pipelines can be executed through `RagIndexer` or `neuron-ai:rag:index`.

Retrieval, pre-processors, post-processors, splitters, readers and application data loaders remain ordinary Symfony services. This keeps custom retrieval policy and tenant filtering in application code while allowing third-party adapters to work immediately through autowiring.

## Workflows and persistence

Use `#[AsNeuronWorkflow]` for a class-owned graph or `neuron_ai.workflow.workflows` when Symfony configuration should append node and middleware services. `WorkflowFactory` always returns a fresh graph, `WorkflowRunner` normalizes completed/interrupted outcomes and exposes native event streaming, and `PersistenceRegistry` deliberately shares only the backend needed by a later resume operation.

The generic runner accepts `WorkflowState` and an optional custom start `Event`. Resume requires the same configured workflow name, the persisted workflow ID, and a concrete `InterruptRequest` reconstructed from validated application input. Store that workflow name/ID pair against an authorized domain record; a resume token is not proof that the caller may approve the operation.

`memory`, `file`, `database`, and custom-service persistence are available through YAML. Neuron's file/database implementations serialize PHP objects. Keep storage private and use a custom `PersistenceInterface` when the application needs encryption, platform-specific SQL, tenant partitioning, locking, or stricter deserialization control.

The optional `RunWorkflowMessage` carries only a workflow name and array state so common Messenger transports can serialize it. Custom start Events and `InterruptRequest` subclasses belong in application-owned message classes because their validation and serializer mapping are domain-specific.

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
