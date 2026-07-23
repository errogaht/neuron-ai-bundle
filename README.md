# Neuron AI Bundle

Symfony-native integration for [Neuron AI](https://docs.neuron-ai.dev/): configure providers, agents, RAG and workflow infrastructure in YAML, inject named runtimes through autowiring, attach ordinary Symfony services or a configured Doctrine MCP server as tools, and optionally run agents or workflows through Messenger.

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

For low-latency HTTP or console output, consume typed Neuron stream chunks and
read the normalized final result from the generator return value:

```php
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;

$stream = $runner->stream('assistant', 'Summarize this order.', threadId: 'order-42');
foreach ($stream as $chunk) {
    // Expose only chunk types appropriate for the client boundary. Tool and
    // reasoning chunks may contain implementation details or sensitive data.
    if ($chunk instanceof TextChunk) {
        echo $chunk->content;
    }
}

$result = $stream->getReturn();
```

The generator must be consumed to completion. `stream()` uses the same fresh
agent factory, tool loop, completion/failure events and normalized
`AgentRunResult` contract as `chat()`.

## Named autowiring

Each configured provider, agent, workflow, embedding provider, and vector store receives a Symfony named-autowiring alias. Stateful agents, workflows, and vector-search filters are isolated according to their runtime boundary:

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

## Doctrine MCP Bundle bridge

When the application also uses [Doctrine MCP Bundle](https://github.com/errogaht/doctrine-mcp-bundle), a configured MCP server can be attached to an agent without publishing an HTTP endpoint or specifying an MCP URL:

```bash
composer require errogaht/doctrine-mcp-bundle
```

Configure entities, fields, actor resolution, query scopes and custom MCP tools in `doctrine_mcp` as usual. Then enable the bridge on the required Neuron agent:

```yaml
# config/packages/neuron_ai.yaml
neuron_ai:
    agents:
        order_manager:
            provider: main
            instructions: 'Read and update only orders available through the supplied tools.'
            doctrine_mcp:
                enabled: true
```

That is the complete connection configuration: there is no URL, port, SSE transport, HTTP request or duplicated tool registration. The bundle opens an isolated in-process MCP protocol session against the application's existing `doctrine_mcp.server`. All built-in Doctrine tools and custom tools discovered by Doctrine MCP Bundle are available by default.

Reduce the model-visible capability set per agent with `only` and `exclude`:

```yaml
neuron_ai:
    agents:
        order_reader:
            doctrine_mcp:
                enabled: true
                only:
                    - doctrine_list_entities
                    - doctrine_describe_entity
                    - doctrine_search
                    - doctrine_get

        order_editor:
            doctrine_mcp:
                enabled: true
                exclude:
                    - doctrine_delete
```

`exclude` takes precedence when a name appears in both lists. These lists control which tools the model can see; they are not authorization rules. The same Doctrine MCP handlers still resolve the current actor and execute configured entity scopes, validation, audit and dry-run behavior.

For synchronous HTTP requests, the usual Symfony security context remains available to the Doctrine MCP actor provider. Messenger workers have no authenticated browser session by default: configure a worker-safe `ActorProviderInterface` and restore identity from trusted, validated job context before exposing tenant or user data. Never accept a tenant or actor identifier merely because the model supplied it.

The bridge exposes MCP **tools**, matching Neuron's `McpConnector`. MCP resources and prompts are not converted into tools. No bridge services are registered when `doctrine_mcp.enabled` is false, so Doctrine MCP Bundle remains an optional dependency.

## Class-first reusable agents

An agent can own its prompt and tools entirely in one application class. Mark an autoconfigured Symfony service with `#[AsNeuronAgent]`; it becomes available both by its concrete class and by a stable registry name used by `AgentRunner`, console commands and Messenger:

```php
<?php

namespace App\Ai\Agent;

use App\Ai\Tool\FindOrderTool;
use App\Ai\Tool\OrderTools;
use Errogaht\NeuronAiBundle\Agent\Attribute\AsNeuronAgent;
use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

#[AsNeuronAgent('order_manager')]
final class OrderManagerAgent extends Agent
{
    public function __construct(
        AIProviderInterface $mainProvider,
        private readonly FindOrderTool $findOrder,
        private readonly OrderTools $orders,
    ) {
        // Required whenever a subclass declares its own constructor: Neuron initializes workflow state here.
        parent::__construct();
        $this->setAiProvider($mainProvider);
    }

    protected function instructions(): string
    {
        return <<<'PROMPT'
            You manage orders for the authenticated customer.
            Read the order before proposing a change and never bypass tool authorization.
            PROMPT;
    }

    /** @return list<ToolInterface|ToolkitInterface> */
    protected function tools(): array
    {
        return [$this->findOrder, $this->orders];
    }
}
```

`$mainProvider` uses the named provider autowiring configured under `neuron_ai.providers.main`. Tool constructor dependencies are ordinary Symfony services. No `neuron_ai.agents` entry is required.

Use the concrete service directly:

```php
final class OrderAssistant
{
    public function __construct(private OrderManagerAgent $agent)
    {
    }
}
```

Or use its registered name wherever the bundle lifecycle is useful:

```php
$result = $runner->chat('order_manager', 'Move my order to tomorrow.');
```

Named autowiring also works with `AgentInterface $orderManagerAgent`. The attribute makes every instance non-shared because Neuron agents contain mutable workflow state. If no name is supplied, `OrderManagerAgent` becomes `order_manager`. The class must be covered by Symfony's normal `services.yaml` resource with `autoconfigure: true`.

Class-owned agents preserve their own provider, `instructions()` and `tools()`. Creating them through `AgentFactory` or `AgentRunner` additionally applies bundle configurators and observers. Direct concrete-class injection intentionally gives the native service without request-specific `AgentContext` processing.

An attributed agent may also be the default without duplicating it in `agents`:

```yaml
neuron_ai:
    default_agent: order_manager
```

## RAG and vector stores

RAG needs two separate model-facing components: an **embedding provider** converts text into vectors, and a **vector store** persists and searches those vectors. The chat provider still generates the final answer. The bundle makes all three named Symfony services and wires them into Neuron's native `RAG` class.

```yaml
# config/packages/neuron_ai.yaml
neuron_ai:
    default_provider: main

    providers:
        main:
            type: openai_like
            base_url: '%env(AI_BASE_URL)%'
            key: '%env(AI_API_KEY)%'
            model: '%env(AI_MODEL)%'

    rag:
        default_embeddings: knowledge
        default_vector_store: knowledge

        embeddings:
            knowledge:
                type: openai_like
                base_url: '%env(EMBEDDINGS_BASE_URL)%'
                key: '%env(EMBEDDINGS_API_KEY)%'
                model: '%env(EMBEDDINGS_MODEL)%'
                dimensions: 1024

        vector_stores:
            knowledge:
                type: qdrant
                collection_url: '%env(QDRANT_COLLECTION_URL)%'
                key: '%env(QDRANT_API_KEY)%'
                dimensions: 1024
                top_k: 6

        pipelines:
            application_docs:
                loaders:
                    - App\Ai\Rag\DocumentationLoader
                chunk_size: 50

    agents:
        knowledge:
            class: NeuronAI\RAG\RAG
            provider: main
            instructions: 'Answer from retrieved application documentation. State when the answer is absent.'
            rag:
                enabled: true
```

The embedding dimensions must match the vector collection dimensions. Changing the embedding model or dimensions normally requires rebuilding that collection.

### Data loaders and indexing

A loader is an ordinary autowired Symfony service implementing Neuron's `DataLoaderInterface`. It can read Doctrine entities, APIs, CMS content, files or any application data:

```php
<?php

namespace App\Ai\Rag;

use App\Entity\Article;
use App\Repository\ArticleRepository;
use NeuronAI\RAG\DataLoader\DataLoaderInterface;
use NeuronAI\RAG\Document;

final class DocumentationLoader implements DataLoaderInterface
{
    public function __construct(private ArticleRepository $articles)
    {
    }

    public function getDocuments(): array
    {
        return array_map(static function (Article $article): Document {
            $document = new Document($article->getSearchableText());
            $document->sourceType = 'article';
            $document->sourceName = (string) $article->getId();
            $document->metadata = ['tenant' => $article->getTenantId()];

            return $document;
        }, $this->articles->findPublished());
    }
}
```

Run a pipeline manually, during deployment, or from Scheduler/cron:

```bash
php bin/console neuron-ai:rag:index application_docs
php bin/console neuron-ai:rag:index application_docs --reindex
```

`--reindex` deletes each returned `sourceType/sourceName` once before inserting its new chunks. Stable source identifiers are therefore important. Deletion and remote embedding/upsert cannot be made transactionally portable across every engine, so schedule retries and backups according to the selected store. Pipelines are also callable from application code through `RagIndexer::index()`.

### Supported RAG drivers

Embedding types: `openai`, `openai_like`, `ollama`, `gemini`, `mistral`, `voyage`, `cohere`, and `service`.

For compatibility with Neuron 3.15, the built-in `openai_like` embedding driver cannot receive custom HTTP headers or Guzzle options. Select `type: service` when that transport customization is required.

Vector-store types: `memory`, `file`, `qdrant`, `pinecone`, `chroma`, `meilisearch`, `weaviate`, and `service`. Use `service` for every Neuron adapter that requires its own client object, including Elasticsearch, OpenSearch, MariaDB, Typesense and third-party packages:

```yaml
services:
    App\Ai\Rag\ProductVectorStore:
        arguments:
            $client: '@app.elasticsearch_client'
            $index: products

neuron_ai:
    rag:
        vector_stores:
            products:
                type: service
                service: App\Ai\Rag\ProductVectorStore
```

Named autowiring follows the same convention as providers and agents:

```php
public function __construct(
    EmbeddingsProviderInterface $knowledgeEmbeddings,
    VectorStoreInterface $knowledgeVectorStore,
) {
}
```

The default entries autowire without a named argument. Vector stores injected into agents are query-scoped clones, preventing mutable metadata filters from leaking between users; ingestion uses the canonical store. Custom vector-store services must therefore be cloneable. For an in-memory store, index before creating the agent because each agent receives a snapshot. Persistent stores share their external collection normally.

### Class-first RAG agent

`#[AsNeuronAgent]` also works on a Neuron `RAG` subclass. Inject the named components and keep retrieval rules next to the prompt:

```php
#[AsNeuronAgent('knowledge')]
final class KnowledgeAgent extends RAG
{
    public function __construct(
        AIProviderInterface $mainProvider,
        EmbeddingsProviderInterface $knowledgeEmbeddings,
        VectorStoreInterface $knowledgeVectorStore,
    ) {
        parent::__construct();
        $this->setAiProvider($mainProvider);
        $this->setEmbeddingsProvider($knowledgeEmbeddings);
        $this->setVectorStore($knowledgeVectorStore);
    }

    protected function instructions(): string
    {
        return 'Answer only from retrieved tenant-visible documents.';
    }
}
```

For YAML-owned RAG agents, `rag.pre_processors`, `rag.post_processors`, and `rag.retrieval` accept Symfony service IDs implementing the corresponding native Neuron interfaces.

Metadata filters are part of the authorization boundary. Derive tenant/user filters from trusted Symfony security context, never from model arguments, and apply them before similarity search. A vector database must not become a cross-tenant side channel.

## Workflows, persistence, and human-in-the-loop

Neuron workflows keep their typed node/event graph in PHP. Symfony owns construction, named discovery, persistence, middleware, observers, lifecycle events, console execution, and optional Messenger dispatch:

```yaml
# config/packages/neuron_ai.yaml
neuron_ai:
    workflow:
        default: order_processing
        default_persistence: workflow_files

        persistence:
            workflow_files:
                type: file
                directory: '%kernel.project_dir%/var/neuron/workflows'

        workflows:
            order_processing:
                class: App\Ai\Workflow\OrderProcessingWorkflow
                nodes:
                    - App\Ai\Workflow\Node\ReceiveOrder
                    - App\Ai\Workflow\Node\ApproveOrder
                    - App\Ai\Workflow\Node\CompleteOrder
                middleware:
                    - App\Ai\Workflow\AuditMiddleware
```

Configured Workflow, Node, and middleware classes are ordinary autowired services. The factory returns a new Workflow and cloned node/middleware instances for every execution; only the persistence backend is deliberately shared so a later request or worker can resume an interruption.

Named autowiring follows Symfony's argument convention:

```php
use NeuronAI\Workflow\WorkflowInterface;

final class OrderController
{
    public function __construct(private WorkflowInterface $orderProcessingWorkflow)
    {
    }
}
```

### Class-first workflow

Keep graphs that inject agents or domain services entirely in their class:

```php
<?php

namespace App\Ai\Workflow;

use Errogaht\NeuronAiBundle\Workflow\Attribute\AsNeuronWorkflow;
use NeuronAI\Workflow\Workflow;

#[AsNeuronWorkflow('order_processing', persistence: 'workflow_files')]
final class OrderProcessingWorkflow extends Workflow
{
    public function __construct(
        private readonly Node\ReceiveOrder $receive,
        private readonly Node\ApproveOrder $approve,
        private readonly Node\CompleteOrder $complete,
    ) {
        parent::__construct();
    }

    protected function nodes(): array
    {
        return [$this->receive, $this->approve, $this->complete];
    }
}
```

No `workflow.workflows` entry is required for an attributed class. Use `WorkflowRunner`, `WorkflowFactory`, or named `WorkflowInterface` autowiring when the attribute's persistence and global observers must be applied. Direct concrete-class injection intentionally returns the native class-owned service without factory configuration.

### Run, stream, interrupt, and resume

```php
use Errogaht\NeuronAiBundle\Workflow\WorkflowRunner;
use NeuronAI\Workflow\WorkflowState;

$result = $runner->run(
    'order_processing',
    new WorkflowState(['order_id' => 'order-42']),
);

if ($result->isInterrupted()) {
    // Store workflow + workflowId against an authenticated application record.
    $request = $result->interrupt;
}
```

Streaming yields Neuron's native events and returns the normalized result from the Generator:

```php
$stream = $runner->stream('order_processing', new WorkflowState(['order_id' => 'order-42']));
foreach ($stream as $event) {
    // Forward to Mercure, SSE, WebSocket, or a StreamedResponse.
}
$result = $stream->getReturn();
```

After the application validates human feedback and reconstructs the concrete `InterruptRequest`, resume through a fresh workflow instance:

```php
$request = ApprovalRequest::fromArray($validatedPayload);
$result = $runner->resume('order_processing', $workflowId, $request);
```

The runner dispatches `WorkflowRunStarted`, `WorkflowRunCompleted`, `WorkflowRunInterrupted`, and `WorkflowRunFailed`. It logs identifiers, duration, and exception metadata, never workflow state by default.

### Persistence backends

| Type | Configuration | Intended use |
| --- | --- | --- |
| `memory` | none | Tests and resume inside one PHP process only |
| `file` | `directory`; optional `prefix`, `extension`, `create_directory` | Single-host development or workers sharing a protected filesystem |
| `database` | `connection`, optional `table` | A PDO service or Doctrine DBAL connection whose native connection is PDO |
| `service` | `service` | Any custom Neuron `PersistenceInterface`, including platform-specific storage |

For PostgreSQL, distributed locks, encryption, tenant partitioning, or a custom schema, prefer `type: service`. The built-in database adapter follows the SQL behavior of the installed Neuron version.

File and database persistence serialize workflow state, nodes, events, and interrupt requests. Never use a public directory, keep secrets out of state, restrict permitted classes when crossing trust boundaries, and treat resume tokens as application credentials. Always resolve the workflow name and token from an authorized server-side record instead of accepting an arbitrary pair from a client. The runner accepts only portable alphanumeric, underscore, and hyphen tokens, preventing path syntax from reaching file persistence.

### Workflow console and Messenger

```bash
php bin/console neuron-ai:workflow:run order_processing --state='{"order_id":"order-42"}'
php bin/console neuron-ai:workflow:run order_processing --state='{"order_id":"order-42"}' --async
php bin/console neuron-ai:workflow:status "$JOB_ID"
```

The generic Messenger message starts workflows using the default `StartEvent` and JSON-safe state. Custom start Events and resume requests are application types, so dispatch those through an application-owned Messenger message and call `WorkflowRunner` in its handler.

## Custom agents and all Neuron features

For configuration-owned agents, set `class` in YAML. Symfony autowires its constructor, then the bundle applies the configured provider, instructions, tools, configurators and observers:

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

RAG agents, workflow subclasses, persistence, MCP connectors and custom middleware remain ordinary Symfony services. Inject their dependencies in the constructor and select the class in `neuron_ai.agents` or `neuron_ai.workflow.workflows`. See [Advanced integration](docs/advanced.md).

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

# Run a named workflow with initial state
php bin/console neuron-ai:workflow:run order_processing --state='{"order_id":"order-42"}'
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
            Errogaht\NeuronAiBundle\Workflow\Async\RunWorkflowMessage: async
```

```php
use Errogaht\NeuronAiBundle\Async\AsyncAgentDispatcher;

$jobId = $dispatcher->dispatch('assistant', 'Prepare the report', threadId: 'report-42');

// AsyncWorkflowDispatcher uses the same bus/cache configuration.
$workflowJobId = $workflowDispatcher->dispatch('order_processing', ['order_id' => 'order-42']);
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
- `WorkflowRunStarted`
- `WorkflowRunCompleted`
- `WorkflowRunInterrupted`
- `WorkflowRunFailed`

Implement Neuron's `ObserverInterface` as an autoconfigured Symfony service to receive native Neuron events. The bundle deliberately logs metadata only and does not log prompts or responses by default.

## Security model

- Keep API keys in environment secrets, never committed YAML.
- Treat tools as capabilities. Expose only the services required by each agent.
- Enforce user, tenant and object authorization inside application services/repositories. A system prompt is not an access-control mechanism.
- With Doctrine MCP, keep authorization in its actor provider and query scopes; `only`/`exclude` are defense-in-depth capability filters, not access control.
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
