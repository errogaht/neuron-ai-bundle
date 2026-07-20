<?php

declare(strict_types=1);

namespace Errogaht\NeuronAiBundle\Tests\Integration;

use Errogaht\NeuronAiBundle\NeuronAiBundle;
use NeuronAI\Testing\FakeAIProvider;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

/** Minimal host application proving the bundle compiles without Symfony Flex or project fixtures. */
final class TestKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new NeuronAiBundle()];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(static function (ContainerBuilder $container): void {
            // Scenario: a project supplies a custom Neuron provider service and names one agent in YAML-equivalent config.
            $container->loadFromExtension('framework', [
                'secret' => 'test',
                'test' => true,
                'http_client' => ['enabled' => true],
                // Pin cross-version defaults so Symfony 6.4 and newer exercise identical behavior.
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
                'uid' => ['default_uuid_version' => 7, 'time_based_uuid_version' => 7],
            ]);
            $container->register('test.fake_provider', FakeAIProvider::class)
                ->setFactory([TestServiceFactory::class, 'provider']);
            $container->register(TestAgentConsumer::class)
                ->setAutowired(true)
                ->setPublic(true);
            $container->register(TestAsyncConsumer::class)
                ->setAutowired(true)
                ->setPublic(true);
            $container->loadFromExtension('framework', [
                'messenger' => [
                    'transports' => ['ai' => 'sync://'],
                    'routing' => [\Errogaht\NeuronAiBundle\Async\RunAgentMessage::class => 'ai'],
                ],
            ]);
            $container->loadFromExtension('neuron_ai', [
                'default_provider' => 'fake',
                'default_agent' => 'assistant',
                'providers' => ['fake' => ['type' => 'service', 'service' => 'test.fake_provider']],
                'agents' => ['assistant' => ['instructions' => 'Be concise.']],
                'messenger' => ['enabled' => true],
            ]);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/neuron-ai-bundle-test/'.getmypid().'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/neuron-ai-bundle-test/'.getmypid().'/log';
    }
}
