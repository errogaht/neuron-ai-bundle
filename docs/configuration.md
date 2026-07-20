# Configuration reference

All configuration lives under `neuron_ai`.

## Root options

| Option | Type | Default | Meaning |
| --- | --- | --- | --- |
| `default_provider` | string/null | `null` | Provider injected for `AIProviderInterface` and inherited by agents without `provider` |
| `default_agent` | string/null | `null` | Agent injected for `AgentInterface` |
| `providers` | map | `{}` | Named provider definitions |
| `agents` | map | `{}` | Named agent definitions |
| `rag` | map | empty | Named embeddings, vector stores and ingestion pipelines |
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
| `rag.enabled` | `false` | Configure this agent as a native Neuron `RAG` |
| `rag.embeddings` | RAG default | Named embedding provider |
| `rag.vector_store` | RAG default | Named vector store |
| `rag.retrieval` | `null` | Optional custom `RetrievalInterface` service ID |
| `rag.pre_processors` | `[]` | Ordered `PreProcessorInterface` service IDs |
| `rag.post_processors` | `[]` | Ordered `PostProcessorInterface` service IDs |

`doctrine_mcp.enabled` requires `errogaht/doctrine-mcp-bundle`. It reuses that bundle's complete server registry and security boundaries without an MCP URL. Filters change model visibility only; entity authorization must remain enforced by Doctrine MCP actor and scope providers. See the [Doctrine MCP bridge guide](../README.md#doctrine-mcp-bundle-bridge).

Agents marked with `#[AsNeuronAgent('name')]` do not need an `agents` entry. Their provider, prompt and tools remain in the class, while their name is added to the same runtime registry. An attributed name is valid for `default_agent`. See [Class-first reusable agents](../README.md#class-first-reusable-agents).

## RAG

```yaml
neuron_ai:
    rag:
        default_embeddings: knowledge
        default_vector_store: knowledge
        embeddings:
            knowledge:
                type: openai_like
                base_url: '%env(EMBEDDINGS_BASE_URL)%'
                key: '%env(EMBEDDINGS_API_KEY)%'
                model: text-embedding-3-small
                dimensions: 1024
        vector_stores:
            knowledge:
                type: qdrant
                collection_url: '%env(QDRANT_COLLECTION_URL)%'
                key: '%env(QDRANT_API_KEY)%'
                dimensions: 1024
                top_k: 6
        pipelines:
            docs:
                embeddings: knowledge
                vector_store: knowledge
                loaders: [App\Ai\Rag\DocumentationLoader]
                chunk_size: 50
```

### Embedding providers

| Option | Default | Meaning |
| --- | --- | --- |
| `type` | `openai_like` | `openai`, `openai_like`, `ollama`, `gemini`, `mistral`, `voyage`, `cohere`, or `service` |
| `service` | `null` | Custom `EmbeddingsProviderInterface` service ID |
| `key` | `null` | Provider API key; use `%env(...)%` |
| `model` | `null` | Embedding model |
| `base_url` | `null` | Required by `openai_like`; optional Ollama override |
| `dimensions` | `null` | Requested vector dimensions when supported |
| `parameters` | `[]` | Provider-specific parameters/config |
| `timeout` | `60` | HTTP request timeout |
| `connect_timeout` | `10` | HTTP connection timeout |
| `headers` | `[]` | Additional HTTP headers |
| `http_options` | `[]` | Additional Guzzle options |

`headers`, `timeout`, `connect_timeout`, and `http_options` apply to the built-in transports where the installed Neuron version exposes a custom HTTP client. Neuron 3.15 does not expose one for `openai_like`; use `type: service` when that provider needs custom transport settings.

Named argument convention: embedding `knowledge` autowires into `EmbeddingsProviderInterface $knowledgeEmbeddings`. `default_embeddings` creates the unqualified interface alias.

### Vector stores

| Type | Required connection options |
| --- | --- |
| `memory` | none; volatile and intended for tests/session-local knowledge |
| `file` | `directory`; optional `name`, `extension`, `top_k` |
| `qdrant` | `collection_url`; optional `key`, `dimensions`, `top_k` |
| `pinecone` | `key`, `index_url`; optional `namespace`, `version`, `top_k` |
| `chroma` | `collection`; optional `host`, `tenant`, `database`, `key`, `top_k` |
| `meilisearch` | `index_uid`; optional `host`, `key`, `embedder`, `dimensions`, `top_k` |
| `weaviate` | `collection`; optional `host`, `key`, `top_k` |
| `service` | `service` implementing `VectorStoreInterface` |

Named argument convention: store `knowledge` autowires into `VectorStoreInterface $knowledgeVectorStore`. `default_vector_store` creates the unqualified interface alias.

The registry keeps one canonical store for ingestion and returns a clone for each agent/named injection. This prevents request-specific filters from persisting in a long-running worker. External stores still address the same collection. A cloned memory store is a snapshot, so populate it before constructing an agent.

### Pipelines

Each pipeline requires at least one `DataLoaderInterface` service. Missing `embeddings` and `vector_store` inherit the RAG defaults. `chunk_size` controls embedding/upsert batches, not text splitting; configure a Neuron splitter inside the loader service.

```bash
php bin/console neuron-ai:rag:index docs
php bin/console neuron-ai:rag:index docs --reindex
```

The reindex option deletes existing documents for every returned `sourceType/sourceName` pair before adding new chunks. It does not delete unrelated sources.

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
