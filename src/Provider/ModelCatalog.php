<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Provider;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Reads model catalogs from OpenAI-compatible and Ollama HTTP APIs without constructing an agent. */
final class ModelCatalog
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /** @return list<string> */
    public function models(string $provider): array
    {
        $config = $this->providers->connection($provider);
        $type = (string) $config['type'];
        if ('service' === $type) {
            throw new \InvalidArgumentException(\sprintf('Provider "%s" is service-backed; model discovery requires an HTTP provider configuration.', $provider));
        }

        $baseUrl = $this->baseUrl($type, $config);
        $path = (string) ($config['models_path'] ?? '');
        $path = '' !== $path ? $path : ('ollama' === $type ? '/tags' : '/models');
        $headers = (array) ($config['headers'] ?? []);
        $key = (string) ($config['key'] ?? '');
        if ('' !== $key && 'ollama' !== $type) {
            $headers['Authorization'] ??= 'Bearer '.$key;
        }
        if ('anthropic' === $type && '' !== $key) {
            unset($headers['Authorization']);
            $headers['x-api-key'] ??= $key;
            $headers['anthropic-version'] ??= (string) $config['anthropic_version'];
        }

        $response = $this->httpClient->request('GET', rtrim($baseUrl, '/').'/'.ltrim($path, '/'), [
            'headers' => $headers,
            'timeout' => (float) $config['timeout'],
        ]);
        $payload = $response->toArray();
        $rows = (array) ($payload['data'] ?? $payload['models'] ?? []);
        $models = [];
        foreach ($rows as $row) {
            if (\is_string($row)) {
                $models[] = $row;
                continue;
            }
            if (\is_array($row)) {
                $id = $row['id'] ?? $row['name'] ?? $row['model'] ?? null;
                if (\is_string($id) && '' !== $id) {
                    $models[] = $id;
                }
            }
        }
        sort($models);

        return array_values(array_unique($models));
    }

    /** @param array<string, mixed> $config */
    private function baseUrl(string $type, array $config): string
    {
        $configured = (string) ($config['base_url'] ?? '');
        if ('' !== $configured) {
            return $configured;
        }

        return match ($type) {
            'openai', 'openai_responses' => 'https://api.openai.com/v1',
            'anthropic' => 'https://api.anthropic.com/v1',
            'ollama' => 'http://localhost:11434/api',
            default => throw new \InvalidArgumentException(\sprintf('Provider type "%s" requires base_url for model discovery.', $type)),
        };
    }
}
