<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Tests\TestCase\DependencyInjection\CompilerPass;

use OpenSwooleBundle\DependencyInjection\CompilerPass\SentryPublisherSubscriberPass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class SentryPublisherSubscriberPassTest extends TestCase
{
    public static function providerProcess(): iterable
    {
        yield 'default' => [
            'preDefinitions' => [],
            'expectedAddedDefinitionsCount' => 0,
        ];

        yield 'with sentry' => [
            'preDefinitions' => [
                ClientInterface::class => new Definition(),
            ],
            'expectedAddedDefinitionsCount' => 1,
        ];

        yield 'with sentry and something else' => [
            'preDefinitions' => [
                ClientInterface::class => new Definition(),
                'something_else' => new Definition(),
            ],
            'expectedAddedDefinitionsCount' => 1,
        ];
    }

    #[DataProvider('providerProcess')]
    public function testProcess(array $preDefinitions, int $expectedAddedDefinitionsCount): void
    {
        $containerBuilder = new ContainerBuilder();
        foreach ($preDefinitions as $id => $definition) {
            $containerBuilder->setDefinition($id, $definition);
        }

        $pass = new SentryPublisherSubscriberPass();

        $pass->process($containerBuilder);

        self::assertCount($expectedAddedDefinitionsCount + 1 + count($preDefinitions), $containerBuilder->getDefinitions());
    }
}
