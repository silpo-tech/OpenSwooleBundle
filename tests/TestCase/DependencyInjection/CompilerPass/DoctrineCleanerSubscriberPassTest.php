<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Tests\TestCase\DependencyInjection\CompilerPass;

use OpenSwooleBundle\DependencyInjection\CompilerPass\DoctrineCleanerSubscriberPass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class DoctrineCleanerSubscriberPassTest extends TestCase
{
    public static function providerProcess(): iterable
    {
        yield 'default' => [
            'preDefinitions' => [],
            'expectedAddedDefinitionsCount' => 0,
        ];

        yield 'with doctrine odm' => [
            'preDefinitions' => [
                'doctrine_mongodb' => new Definition(),
            ],
            'expectedAddedDefinitionsCount' => 1,
        ];

        yield 'with doctrine orm and odm' => [
            'preDefinitions' => [
                'doctrine_mongodb' => new Definition(),
                'doctrine' => new Definition(),
            ],
            'expectedAddedDefinitionsCount' => 2,
        ];

        yield 'with doctrine orm and odm and something else' => [
            'preDefinitions' => [
                'doctrine_mongodb' => new Definition(),
                'doctrine' => new Definition(),
                'something_else' => new Definition(),
            ],
            'expectedAddedDefinitionsCount' => 2,
        ];
    }

    #[DataProvider('providerProcess')]
    public function testProcess(array $preDefinitions, int $expectedAddedDefinitionsCount): void
    {
        $containerBuilder = new ContainerBuilder();
        foreach ($preDefinitions as $id => $definition) {
            $containerBuilder->setDefinition($id, $definition);
        }

        $pass = new DoctrineCleanerSubscriberPass();

        $pass->process($containerBuilder);

        self::assertCount($expectedAddedDefinitionsCount + 1 + count($preDefinitions), $containerBuilder->getDefinitions());
    }
}
