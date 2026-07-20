<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Rag;

use NeuronAI\RAG\VectorStore\ChromaVectorStore;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use NeuronAI\RAG\VectorStore\MeilisearchVectorStore;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\PineconeVectorStore;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\RAG\VectorStore\WeaviateVectorStore;
use Psr\Container\ContainerInterface;

/** Owns canonical vector-store services for indexing and produces isolated clones for RAG query state. */
final class VectorStoreRegistry
{
    /** @var array<string, VectorStoreInterface> */
    private array $instances = [];

    /** @param array<string, array<string, mixed>> $config */
    public function __construct(
        private readonly array $config,
        private readonly ContainerInterface $customStores,
        private readonly ?string $defaultStore = null,
    ) {
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->config);
    }

    /** @return array<string, mixed> */
    public function describe(string $name): array
    {
        $config = $this->configuration($name);
        unset($config['key']);

        return $config;
    }

    /** Returns the canonical instance used by ingestion pipelines. */
    public function get(?string $name = null): VectorStoreInterface
    {
        $name = $this->name($name);

        return $this->instances[$name] ??= $this->create($name, $this->configuration($name));
    }

    /**
     * Returns a query-scoped copy so mutable metadata filters cannot leak between agents.
     * The underlying external database remains shared; MemoryVectorStore gets a snapshot.
     */
    public function fresh(?string $name = null): VectorStoreInterface
    {
        try {
            return clone $this->get($name);
        } catch (\Throwable $exception) {
            throw new \LogicException('A configured vector store must be cloneable so query filters cannot leak between agents.', 0, $exception);
        }
    }

    /** @param array<string, mixed> $config */
    private function create(string $name, array $config): VectorStoreInterface
    {
        $type = (string) $config['type'];
        if ('service' === $type) {
            $serviceId = $this->required($config, 'service', $name);
            $store = $this->customStores->get($serviceId);
            if (!$store instanceof VectorStoreInterface) {
                throw new \LogicException(\sprintf('Vector store service "%s" must implement %s.', $serviceId, VectorStoreInterface::class));
            }

            return $store;
        }

        $topK = (int) $config['top_k'];

        return match ($type) {
            'memory' => new MemoryVectorStore($topK),
            'file' => new FileVectorStore(
                $this->required($config, 'directory', $name),
                $topK,
                (string) $config['name'],
                (string) $config['extension'],
            ),
            'qdrant' => new QdrantVectorStore(
                $this->required($config, 'collection_url', $name),
                $this->nullable($config, 'key'),
                $topK,
                (int) $config['dimensions'],
            ),
            'pinecone' => new PineconeVectorStore(
                $this->required($config, 'key', $name),
                $this->required($config, 'index_url', $name),
                $topK,
                (string) $config['version'],
                (string) $config['namespace'],
            ),
            'chroma' => new ChromaVectorStore(
                $this->required($config, 'collection', $name),
                (string) ($config['host'] ?: 'http://localhost:8000'),
                (string) $config['tenant'],
                (string) $config['database'],
                $this->nullable($config, 'key'),
                $topK,
            ),
            'meilisearch' => new MeilisearchVectorStore(
                $this->required($config, 'index_uid', $name),
                (string) ($config['host'] ?: 'http://localhost:7700'),
                $this->nullable($config, 'key'),
                (string) $config['embedder'],
                $topK,
                (int) $config['dimensions'],
            ),
            'weaviate' => new WeaviateVectorStore(
                $this->required($config, 'collection', $name),
                (string) ($config['host'] ?: 'http://localhost:8080'),
                $this->nullable($config, 'key'),
                $topK,
            ),
            default => throw new \LogicException(\sprintf('Unsupported vector store type "%s".', $type)),
        };
    }

    private function name(?string $name): string
    {
        $name ??= $this->defaultStore;
        if (null === $name || '' === $name) {
            throw new \InvalidArgumentException('No vector store was selected and no rag.default_vector_store is configured.');
        }

        return $name;
    }

    /** @return array<string, mixed> */
    private function configuration(string $name): array
    {
        return $this->config[$name] ?? throw new \InvalidArgumentException(\sprintf('Unknown vector store "%s".', $name));
    }

    /** @param array<string, mixed> $config */
    private function required(array $config, string $key, string $name): string
    {
        $value = (string) ($config[$key] ?? '');
        if ('' === $value) {
            throw new \InvalidArgumentException(\sprintf('Vector store "%s" requires "%s".', $name, $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $config */
    private function nullable(array $config, string $key): ?string
    {
        $value = (string) ($config[$key] ?? '');

        return '' === $value ? null : $value;
    }
}
