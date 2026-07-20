<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Rag;

use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\RAG\Embeddings\CohereEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\GeminiEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\MistralEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\OllamaEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\OpenAILikeEmbeddings;
use NeuronAI\RAG\Embeddings\VoyageEmbeddingsProvider;
use Psr\Container\ContainerInterface;

/** Resolves named embedding providers while keeping credentials inside compiled Symfony configuration. */
final class EmbeddingProviderRegistry
{
    /** @var array<string, EmbeddingsProviderInterface> */
    private array $instances = [];

    /** @param array<string, array<string, mixed>> $config */
    public function __construct(
        private readonly array $config,
        private readonly ContainerInterface $customProviders,
        private readonly ?string $defaultProvider = null,
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

    public function get(?string $name = null): EmbeddingsProviderInterface
    {
        $name ??= $this->defaultProvider;
        if (null === $name || '' === $name) {
            throw new \InvalidArgumentException('No embedding provider was selected and no rag.default_embeddings is configured.');
        }

        return $this->instances[$name] ??= $this->create($name, $this->configuration($name));
    }

    /** @param array<string, mixed> $config */
    private function create(string $name, array $config): EmbeddingsProviderInterface
    {
        $type = (string) $config['type'];
        if ('service' === $type) {
            $serviceId = $this->required($config, 'service', $name);
            $provider = $this->customProviders->get($serviceId);
            if (!$provider instanceof EmbeddingsProviderInterface) {
                throw new \LogicException(\sprintf('Embedding service "%s" must implement %s.', $serviceId, EmbeddingsProviderInterface::class));
            }

            return $provider;
        }

        $client = new GuzzleHttpClient(
            (array) $config['headers'],
            (float) $config['timeout'],
            (float) $config['connect_timeout'],
            null,
            (array) $config['http_options'],
        );
        $key = (string) ($config['key'] ?? '');
        $model = $this->required($config, 'model', $name);
        $dimensions = isset($config['dimensions']) ? (int) $config['dimensions'] : null;

        return match ($type) {
            'openai' => new OpenAIEmbeddingsProvider($key, $model, $dimensions, $client),
            // Neuron 3.15.0 did not yet expose the optional HTTP client on OpenAILikeEmbeddings.
            'openai_like' => new OpenAILikeEmbeddings($this->required($config, 'base_url', $name), $key, $model, $dimensions),
            'ollama' => new OllamaEmbeddingsProvider($model, (string) ($config['base_url'] ?: 'http://localhost:11434/api'), (array) $config['parameters'], $client),
            'gemini' => new GeminiEmbeddingsProvider($key, $model, (array) $config['parameters'], $client),
            'mistral' => new MistralEmbeddingsProvider($key, $model, $dimensions, $client),
            'voyage' => new VoyageEmbeddingsProvider($key, $model, $dimensions, $client),
            'cohere' => new CohereEmbeddingsProvider($key, $model, (array) $config['parameters'], $client),
            default => throw new \LogicException(\sprintf('Unsupported embedding provider type "%s".', $type)),
        };
    }

    /** @return array<string, mixed> */
    private function configuration(string $name): array
    {
        return $this->config[$name] ?? throw new \InvalidArgumentException(\sprintf('Unknown embedding provider "%s".', $name));
    }

    /** @param array<string, mixed> $config */
    private function required(array $config, string $key, string $name): string
    {
        $value = (string) ($config[$key] ?? '');
        if ('' === $value) {
            throw new \InvalidArgumentException(\sprintf('Embedding provider "%s" requires "%s".', $name, $key));
        }

        return $value;
    }
}
