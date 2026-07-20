<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Tests\DependencyInjection;

use Errogaht\NeuronAiBundle\DependencyInjection\NeuronAiExtension;
use Errogaht\NeuronAiBundle\Integration\DoctrineMcp\DoctrineMcpToolProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/** Verifies optional integration wiring before the complete host container is compiled. */
final class NeuronAiExtensionTest extends TestCase
{
    public function testDoctrineMcpReferenceDoesNotDependOnBundleLoadOrder(): void
    {
        // Scenario: Symfony loads NeuronAiExtension before DoctrineMcpExtension has registered its server service.
        $container = new ContainerBuilder();
        (new NeuronAiExtension())->load([[
            'providers' => ['test' => ['type' => 'service', 'service' => 'test.provider']],
            'agents' => [
                'assistant' => [
                    'provider' => 'test',
                    'doctrine_mcp' => ['enabled' => true],
                ],
            ],
        ]], $container);

        $definition = $container->getDefinition(DoctrineMcpToolProvider::class);
        $server = $definition->getArgument(0);

        self::assertInstanceOf(Reference::class, $server);
        self::assertSame('doctrine_mcp.server', (string) $server);
    }
}
