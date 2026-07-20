# Messenger integration

Messenger execution is optional and disabled by default. When enabled, the bundle registers a serializable message, handler, dispatcher and PSR-6 result store.

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

## Retries and idempotency

Failures are rethrown so the application's Messenger retry strategy remains authoritative. A retry runs the agent again and overwrites the same job status. Tools that cause side effects must implement their own idempotency key or transaction boundary.

## Sensitive data

The prompt is serialized into the Messenger transport. Select and secure the transport accordingly. The bundle does not copy the prompt into its cache result. Successful model output and failure messages are cached until `result_ttl` expires.
