# Configuration reference

All configuration lives under `neuron_ai`.

## Root options

| Option | Type | Default | Meaning |
| --- | --- | --- | --- |
| `default_provider` | string/null | `null` | Provider injected for `AIProviderInterface` and inherited by agents without `provider` |
| `default_agent` | string/null | `null` | Agent injected for `AgentInterface` |
| `providers` | map | `{}` | Named provider definitions |
| `agents` | map | `{}` | Named agent definitions |
| `messenger` | map | disabled | Optional async execution |

Unknown defaults and missing agent providers fail during container compilation.

## Providers

```yaml
neuron_ai:
    providers:
        main:
            type: openai_like
            key: '%env(AI_API_KEY)%'
            base_url: '%env(AI_BASE_URL)%'
            model: '%env(AI_MODEL)%'
            parameters: { temperature: 0.2 }
            strict_response: false
            timeout: 60
            connect_timeout: 10
            headers: { X-Application: my-app }
            http_options: {}
            models_path: /models
```

Common options:

| Option | Applies to | Notes |
| --- | --- | --- |
| `type` | all | See supported types in the README |
| `key` | remote providers | Prefer `%env(...)%` |
| `base_url` | OpenAI-like, Ollama | Required for runtime; optional for built-in OpenAI/Anthropic defaults |
| `model` | all built-ins | Model used for inference |
| `parameters` | all built-ins | Passed unchanged to Neuron |
| `strict_response` | OpenAI families | Enables Neuron strict response handling |
| `headers` | built-ins | Additional HTTP headers |
| `http_options` | built-ins | Additional Guzzle options |
| `models_path` | model discovery | Defaults to `/models`, or `/tags` for Ollama |
| `anthropic_version` | Anthropic | Defaults to `2023-06-01` |
| `max_tokens` | Anthropic | Defaults to `8192` |

### Custom provider service

```yaml
neuron_ai:
    providers:
        custom:
            type: service
            service: App\Ai\Provider\CustomProvider
```

The service must implement `NeuronAI\Providers\AIProviderInterface` and must be cloneable. The clone requirement prevents mutable provider state from crossing requests.

## Agents

```yaml
neuron_ai:
    agents:
        support:
            class: App\Ai\SupportAgent
            provider: main
            instructions: 'Help the customer.'
            tools:
                - App\Ai\Tool\FindOrderTool
            tool_max_runs: 10
            parallel_tool_calls: false
            doctrine_mcp:
                enabled: false
                only: []
                exclude: []
```

The class must implement `NeuronAI\Agent\AgentInterface`. Its constructor is autowired. Every configured agent is non-shared, and every listed tool is cloned before attachment.

Each `tools` item may reference a native `ToolInterface`, a native `ToolkitInterface`, or the bundle's attribute-driven `AbstractToolGroup`. A tool group expands all of its `#[Tool]` methods when Neuron bootstraps the agent.

Agent options:

| Option | Default | Meaning |
| --- | --- | --- |
| `class` | `NeuronAI\Agent\Agent` | Autowired agent implementation |
| `provider` | root default | Named provider used by this agent |
| `instructions` | `null` | Static system instructions |
| `tools` | `[]` | Explicit Symfony tool or toolkit service IDs |
| `tool_max_runs` | `10` | Maximum tool loop iterations |
| `parallel_tool_calls` | `false` | Enable provider parallel tool calls |
| `doctrine_mcp.enabled` | `false` | Attach the configured `doctrine_mcp.server` through an in-process MCP session |
| `doctrine_mcp.only` | `[]` | If non-empty, expose only these MCP tool names |
| `doctrine_mcp.exclude` | `[]` | Hide these MCP tool names; takes precedence over `only` |

`doctrine_mcp.enabled` requires `errogaht/doctrine-mcp-bundle`. It reuses that bundle's complete server registry and security boundaries without an MCP URL. Filters change model visibility only; entity authorization must remain enforced by Doctrine MCP actor and scope providers. See the [Doctrine MCP bridge guide](../README.md#doctrine-mcp-bundle-bridge).

Named aliases are generated as follows:

| Configuration key | Autowired argument |
| --- | --- |
| provider `main` | `AIProviderInterface $mainProvider` |
| agent `support` | `AgentInterface $supportAgent` |
| agent `order_manager` | `AgentInterface $orderManagerAgent` |

## Messenger

| Option | Default | Meaning |
| --- | --- | --- |
| `enabled` | `false` | Registers async services and handler |
| `bus` | `messenger.default_bus` | Bus used for dispatch |
| `result_cache_pool` | `cache.app` | PSR-6 pool used for job status/results |
| `result_ttl` | `86400` | Result lifetime in seconds, minimum 60 |
