<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Provider;

use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\Providers\OpenAILikeResponses;
use Psr\Container\ContainerInterface;

/** Creates a fresh provider for each agent so mutable prompts and tools never leak between runs. */
final class ProviderRegistry
{
    /**
     * @param array<string, array<string, mixed>> $config
     */
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

    /**
     * Exposes connection settings only to infrastructure services such as ModelCatalog.
     * Application-facing diagnostics must use describe(), which deliberately removes secrets.
     *
     * @return array<string, mixed>
     */
    public function connection(string $name): array
    {
        return $this->configuration($name);
    }

    public function get(?string $name = null): AIProviderInterface
    {
        $name ??= $this->defaultProvider;
        if (null === $name || '' === $name) {
            throw new \InvalidArgumentException('No Neuron AI provider was selected and no default_provider is configured.');
        }
        $config = $this->configuration($name);
        $type = (string) $config['type'];
        if ('service' === $type) {
            $service = (string) $config['service'];
            $provider = $this->customProviders->get($service);
            if (!$provider instanceof AIProviderInterface) {
                throw new \LogicException(\sprintf('Provider service "%s" must implement %s.', $service, AIProviderInterface::class));
            }

            return clone $provider;
        }

        $client = new GuzzleHttpClient(
            (array) $config['headers'],
            (float) $config['timeout'],
            (float) $config['connect_timeout'],
            null,
            (array) $config['http_options'],
        );
        $key = (string) ($config['key'] ?? '');
        $model = (string) ($config['model'] ?? '');
        $parameters = (array) $config['parameters'];
        $strict = (bool) $config['strict_response'];

        return match ($type) {
            'openai' => new OpenAI($key, $model, $parameters, $strict, $client),
            'openai_responses' => new OpenAIResponses($key, $model, $parameters, $strict, $client),
            'openai_like' => new OpenAILike($this->required($config, 'base_url', $name), $key, $model, $parameters, $strict, $client),
            'openai_like_responses' => new OpenAILikeResponses($this->required($config, 'base_url', $name), $key, $model, $parameters, $strict, $client),
            'anthropic' => new Anthropic($key, $model, (string) $config['anthropic_version'], (int) $config['max_tokens'], $parameters, $client),
            'ollama' => new Ollama($this->required($config, 'base_url', $name), $model, $parameters, $client),
            default => throw new \LogicException(\sprintf('Unsupported provider type "%s".', $type)),
        };
    }

    /** @return array<string, mixed> */
    private function configuration(string $name): array
    {
        return $this->config[$name] ?? throw new \InvalidArgumentException(\sprintf('Unknown Neuron AI provider "%s".', $name));
    }

    /** @param array<string, mixed> $config */
    private function required(array $config, string $key, string $name): string
    {
        $value = (string) ($config[$key] ?? '');
        if ('' === $value) {
            throw new \InvalidArgumentException(\sprintf('Provider "%s" requires "%s".', $name, $key));
        }

        return $value;
    }
}
