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
```

The class must implement `NeuronAI\Agent\AgentInterface`. Its constructor is autowired. Every configured agent is non-shared, and every listed tool is cloned before attachment.

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
