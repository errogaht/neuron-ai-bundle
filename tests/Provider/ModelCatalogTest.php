<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Tests\Provider;

use Errogaht\NeuronAiBundle\Provider\ModelCatalog;
use Errogaht\NeuronAiBundle\Provider\ProviderRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Covers the OpenAI-compatible discovery contract used by private and self-hosted endpoints. */
final class ModelCatalogTest extends TestCase
{
    public function testModelsAreNormalizedSortedAndCredentialsStayOutOfDescription(): void
    {
        // Scenario: a compatible API returns duplicate, unordered model records.
        $config = ['private' => [
            'type' => 'openai_like', 'base_url' => 'https://ai.example/v1', 'key' => 'secret',
            'models_path' => null, 'headers' => [], 'timeout' => 10.0,
        ]];
        $registry = new ProviderRegistry($config, new ServiceLocator([]), 'private');
        $client = new MockHttpClient(new MockResponse('{"data":[{"id":"zeta"},{"id":"alpha"},{"id":"alpha"}]}'));

        self::assertSame(['alpha', 'zeta'], (new ModelCatalog($registry, $client))->models('private'));
        self::assertArrayNotHasKey('key', $registry->describe('private'));
    }
}
