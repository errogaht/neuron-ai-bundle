# Neuron AI Bundle

Symfony-native integration for [Neuron AI](https://docs.neuron-ai.dev/): configure providers and agents in YAML, inject them through autowiring, attach ordinary Symfony services as tools, and optionally run agents through Messenger.

The bundle does not replace Neuron AI. It removes repetitive construction and configuration while leaving the complete Neuron API available for streaming, structured output, RAG, workflows, persistence, human-in-the-loop, MCP and observability.

## Requirements

- PHP 8.1 or newer
- Symfony 6.4, 7.4 or 8.x
- Neuron AI 3.15

## Installation

```bash
composer require errogaht/neuron-ai-bundle
```

If Symfony Flex does not enable the bundle automatically, add it to `config/bundles.php`:

```php
<?php

return [
    // ...
    Errogaht\NeuronAiBundle\NeuronAiBundle::class => ['all' => true],
];
```

## Quick start

Store credentials in `.env.local` or your deployment secret manager:

```dotenv
AI_BASE_URL=https://api.example.com/v1
AI_API_KEY=replace-me
AI_MODEL=my-model
```

Create `config/packages/neuron_ai.yaml`:

```yaml
neuron_ai:
    default_provider: main
    default_agent: assistant

    providers:
        main:
            type: openai_like
            base_url: '%env(AI_BASE_URL)%'
            key: '%env(AI_API_KEY)%'
            model: '%env(AI_MODEL)%'
            parameters:
                temperature: 0.2

    agents:
        assistant:
            instructions: 'You are a concise application assistant.'
```

Use the default configured agent directly:

```php
<?php

namespace App\Service;

use NeuronAI\Agent\AgentInterface;
use NeuronAI\Chat\Messages\UserMessage;

final class AnswerQuestion
{
    public function __construct(private AgentInterface $agent)
    {
    }

    public function __invoke(string $question): ?string
    {
        return $this->agent->chat(new UserMessage($question))->getMessage()->getContent();
    }
}
```

Or use the stable Symfony-facing runner, which dispatches bundle events and returns a serializable result:

```php
use Errogaht\NeuronAiBundle\Agent\AgentRunner;

$result = $runner->chat('assistant', 'Summarize this order.', threadId: 'order-42');
$text = $result->content();
```

## Named autowiring

Each configured provider and agent becomes a non-shared service. The key is converted to Symfony's named-autowiring convention:

```yaml
neuron_ai:
    default_provider: main
    providers:
        main:
            type: openai
            key: '%env(OPENAI_API_KEY)%'
            model: gpt-4.1-mini
    agents:
        support:
            instructions: 'Answer support questions.'
        order_manager:
            instructions: 'Work with orders.'
```

```php
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Providers\AIProviderInterface;

final class ControllerService
{
    public function __construct(
        private AgentInterface $supportAgent,
        private AgentInterface $orderManagerAgent,
        private AIProviderInterface $mainProvider,
    ) {
    }
}
```

This is intentional: Neuron providers, agents and tools accumulate run state. Fresh services prevent prompts, tool inputs or history from leaking into another request.

## Symfony services as tools

Create any Neuron `ToolInterface` or toolkit as a normal autowired Symfony service:

```php
<?php

namespace App\Ai\Tool;

use App\Repository\OrderRepository;
use NeuronAI\Tools\Tool;

final class FindOrderTool extends Tool
{
    public function __construct(private readonly OrderRepository $orders)
    {
        parent::__construct('find_order', 'Find an order visible to the current user.');
    }

    public function __invoke(string $number): array
    {
        // Authorization belongs in the application service/repository boundary.
        return $this->orders->findVisibleSummary($number);
    }
}
```

Attach its service ID to an agent:

```yaml
neuron_ai:
    agents:
        order_manager:
            provider: main
            instructions: 'Use tools to answer questions about orders.'
            tools:
                - App\Ai\Tool\FindOrderTool
            tool_max_runs: 8
            parallel_tool_calls: false
```

Autoconfiguration is not magic discovery: only tools explicitly listed on an agent are exposed to that model. This keeps the capability boundary reviewable.

## Multiple tools in one service

For a cohesive group of operations, extend `AbstractToolGroup` and expose selected public methods with attributes. Constructor dependencies are autowired exactly like any other Symfony service:

```php
<?php

namespace App\Ai\Tool;

use App\Repository\OrderRepository;
use Errogaht\NeuronAiBundle\Tool\AbstractToolGroup;
use Errogaht\NeuronAiBundle\Tool\Attribute\Tool;
use Errogaht\NeuronAiBundle\Tool\Attribute\ToolParameter;

final class OrderTools extends AbstractToolGroup
{
    public function __construct(private readonly OrderRepository $orders)
    {
    }

    #[Tool(description: 'Find an order visible to the current user.')]
    public function findOrder(
        #[ToolParameter(description: 'Public order number')] string $number,
        #[ToolParameter(enum: ['short', 'full'])] string $format = 'short',
    ): array {
        return $this->orders->findVisibleSummary($number, $format);
    }

    #[Tool(name: 'change_delivery_address', description: 'Change delivery before dispatch.', maxRuns: 1)]
    public function changeDeliveryAddress(string $number, string $address): array
    {
        return $this->orders->changeVisibleOrderAddress($number, $address);
    }

    public function guidelines(): ?string
    {
        return 'Always find the order before attempting a change.';
    }
}
```

Connect the whole group with one service ID:

```yaml
neuron_ai:
    agents:
        order_manager:
            provider: main
            tools:
                - App\Ai\Tool\OrderTools
```

The group is a native Neuron toolkit. Method names become `snake_case` unless `name` is provided. Schema types and required fields are inferred from PHP scalar/array types, nullable/default parameters, and backed enums. Use `#[ToolParameter]` for descriptions, explicit `PropertyType`, enum values or a required override. Complex DTO schemas should use a native Neuron `Tool` class.

## Custom agents and all Neuron features

Set `class` to your own `AgentInterface` implementation or subclass. Symfony autowires its constructor, then the bundle applies the configured provider, instructions, tools, configurators and observers:

```yaml
neuron_ai:
    agents:
        analyst:
            class: App\Ai\AnalystAgent
            provider: main
            tools: ['App\Ai\Tool\SearchKnowledgeTool']
```

Because the injected object is the real Neuron agent, its native APIs remain available:

```php
// Streaming
foreach ($analystAgent->stream(new UserMessage($prompt)) as $chunk) {
    // send $chunk to a Symfony StreamedResponse, Mercure, etc.
}

// Structured output
$dto = $analystAgent->structured(new UserMessage($prompt), InvoiceDraft::class);
```

RAG agents, workflow subclasses, persistence, MCP connectors and custom middleware remain ordinary Symfony services. Inject their dependencies in the constructor and select the class in `neuron_ai.agents`. See [Advanced integration](docs/advanced.md).

## Request-specific configuration

Use a configurator when history, tenant, locale, authorization or persistence depends on the current request or queued message:

```php
<?php

namespace App\Ai;

use Errogaht\NeuronAiBundle\Agent\AgentConfiguratorInterface;
use Errogaht\NeuronAiBundle\Agent\AgentContext;
use NeuronAI\Agent\AgentInterface;

final class TenantAgentConfigurator implements AgentConfiguratorInterface
{
    public function configure(AgentInterface $agent, AgentContext $context): void
    {
        // Resolve and attach tenant-scoped history/tools here. Never trust model output as authorization.
    }
}
```

The interface is autoconfigured. Pass context through `AgentFactory::create()` or `AgentRunner::chat()`.

## Console commands

```bash
# Verify compiled configuration without printing API keys
php bin/console neuron-ai:debug

# Query the provider's models endpoint
php bin/console neuron-ai:models main

# Smoke-test an agent
php bin/console neuron-ai:run --agent=assistant 'Say hello'
```

`neuron-ai:models` understands OpenAI-compatible `data[].id`, Ollama `models[].name`, and configurable model-list paths.

## Messenger

Install Messenger only when async execution is needed:

```bash
composer require symfony/messenger
```

```yaml
# config/packages/neuron_ai.yaml
neuron_ai:
    messenger:
        enabled: true
        bus: messenger.default_bus
        result_cache_pool: cache.app
        result_ttl: 86400

# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            async: '%env(MESSENGER_TRANSPORT_DSN)%'
        routing:
            Errogaht\NeuronAiBundle\Async\RunAgentMessage: async
```

```php
use Errogaht\NeuronAiBundle\Async\AsyncAgentDispatcher;

$jobId = $dispatcher->dispatch('assistant', 'Prepare the report', threadId: 'report-42');
```

Run the worker and inspect the cache-backed result:

```bash
php bin/console messenger:consume async -vv
php bin/console neuron-ai:status "$JOB_ID"
php bin/console neuron-ai:run --async --agent=assistant 'Prepare the report'
```

Messenger retries remain controlled by the host application's transport configuration. Prompts are carried in Messenger envelopes but are never stored in the bundle's result cache. See [Messenger integration](docs/messenger.md).

## Provider types

| Type | Purpose |
| --- | --- |
| `openai` | OpenAI Chat Completions |
| `openai_responses` | OpenAI Responses API |
| `openai_like` | OpenAI-compatible Chat Completions with a custom base URL |
| `openai_like_responses` | OpenAI-compatible Responses API with a custom base URL |
| `anthropic` | Anthropic Messages API |
| `ollama` | Local or remote Ollama |
| `service` | Any custom/current/future Neuron `AIProviderInterface` service |

See the complete [configuration reference](docs/configuration.md).

## Events and observability

The runner dispatches:

- `AgentRunStarted`
- `AgentRunCompleted`
- `AgentRunFailed`

Implement Neuron's `ObserverInterface` as an autoconfigured Symfony service to receive native Neuron events. The bundle deliberately logs metadata only and does not log prompts or responses by default.

## Security model

- Keep API keys in environment secrets, never committed YAML.
- Treat tools as capabilities. Expose only the services required by each agent.
- Enforce user, tenant and object authorization inside application services/repositories. A system prompt is not an access-control mechanism.
- Validate tool inputs and structured output before causing side effects.
- Configure Messenger encryption or an appropriate protected transport when prompts contain sensitive data.
- Use a dedicated cache pool with the required retention policy for async results.

## Documentation

- [Configuration reference](docs/configuration.md)
- [Advanced Neuron integration](docs/advanced.md)
- [Messenger integration](docs/messenger.md)
- [Neuron AI documentation](https://docs.neuron-ai.dev/)
- [Neuron providers](https://docs.neuron-ai.dev/neuron-v3/providers/ai-provider)
- [Neuron tools](https://docs.neuron-ai.dev/agent/tools)
- [Neuron structured output](https://docs.neuron-ai.dev/agent/structured-output)
- [Neuron RAG](https://docs.neuron-ai.dev/neuron-v3/rag/rag)
- [Neuron workflows](https://docs.neuron-ai.dev/neuron-v3/workflow/getting-started)

## Development

```bash
composer install
composer check
```

## License

MIT
