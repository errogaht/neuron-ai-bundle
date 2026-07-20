# Messenger integration

Messenger execution is optional and disabled by default. When enabled, the bundle registers serializable messages, handlers, dispatchers and PSR-6 result stores for both agents and workflows.

## Flow

1. `AsyncAgentDispatcher` creates a UUID job and records `queued`.
2. The application's Messenger bus routes `RunAgentMessage`.
3. A worker records `running` and executes `AgentRunner`.
4. The result store records `succeeded` with the normalized response, or `failed` with an operational error.
5. Application code polls `AgentJobResultStoreInterface` or uses `neuron-ai:status`.

## Configuration

```yaml
neuron_ai:
    messenger:
        enabled: true
        bus: messenger.default_bus
        result_cache_pool: cache.neuron_ai
        result_ttl: 86400

framework:
    cache:
        pools:
            cache.neuron_ai:
                adapter: cache.adapter.redis
                provider: '%env(REDIS_DSN)%'
    messenger:
        transports:
            ai: '%env(MESSENGER_TRANSPORT_DSN)%'
        routing:
            Errogaht\NeuronAiBundle\Async\RunAgentMessage: ai
            Errogaht\NeuronAiBundle\Workflow\Async\RunWorkflowMessage: ai
```

The cache must be shared between the web process and workers. `cache.adapter.array` is unsuitable outside tests.

## Dispatch and poll

```php
$jobId = $dispatcher->dispatch(
    agent: 'analyst',
    input: 'Analyze the uploaded report.',
    threadId: 'report-42',
    attributes: ['tenant_id' => 'tenant-7'],
);

$status = $results->get($jobId);
```

Possible statuses are `queued`, `running`, `succeeded` and `failed`.

## Workflow dispatch

```php
use Errogaht\NeuronAiBundle\Workflow\Async\AsyncWorkflowDispatcher;
use Errogaht\NeuronAiBundle\Workflow\Async\WorkflowJobResultStoreInterface;

$jobId = $workflowDispatcher->dispatch('order_processing', [
    'order_id' => 'order-42',
]);

$status = $workflowResults->get($jobId);
```

Workflow jobs additionally use `completed` or `interrupted` as their terminal status. An interrupted result contains the workflow ID and the public JSON representation of its request. The bundle does not deserialize approval input automatically: validate it in application code, rebuild the concrete `InterruptRequest`, authorize the workflow record, and call `WorkflowRunner::resume()`.

The generic `RunWorkflowMessage` supports the default `StartEvent` and array state. Use an application message/handler for custom start Events or queued resume operations so the domain owns serializer metadata and authorization.

## Retries and idempotency

Failures are rethrown so the application's Messenger retry strategy remains authoritative. A retry runs the agent or workflow again and overwrites the same job status. Tools and nodes that cause side effects must implement their own idempotency key or transaction boundary.

## Sensitive data

The prompt is serialized into the Messenger transport. Select and secure the transport accordingly. The bundle does not copy the prompt into its cache result. Successful model output and failure messages are cached until `result_ttl` expires.
