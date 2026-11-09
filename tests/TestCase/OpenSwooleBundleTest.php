<?php

declare(strict_types=1);

namespace ExceptionHandlerBundle\Tests;

use OpenSwoole\Coroutine;
use OpenSwooleBundle\DependencyInjection\CompilerPass\DoctrineCleanerSubscriberPass;
use OpenSwooleBundle\DependencyInjection\CompilerPass\LoggerCompilerPass;
use OpenSwooleBundle\DependencyInjection\CompilerPass\SentryPublisherSubscriberPass;
use OpenSwooleBundle\OpenSwooleBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class OpenSwooleBundleTest extends TestCase
{
    public function testBuild(): void
    {
        $containerBuilder = new ContainerBuilder();
        $bundle = new OpenSwooleBundle();
        $bundle->build($containerBuilder);
        $passes = $containerBuilder->getCompiler()->getPassConfig()->getBeforeOptimizationPasses();

        $this->assertContains(
            DoctrineCleanerSubscriberPass::class,
            array_map(static fn (CompilerPassInterface $pass) => $pass::class, $passes),
        );
        $this->assertContains(
            SentryPublisherSubscriberPass::class,
            array_map(static fn (CompilerPassInterface $pass) => $pass::class, $passes),
        );
        $this->assertContains(
            LoggerCompilerPass::class,
            array_map(static fn (CompilerPassInterface $pass) => $pass::class, $passes),
        );
    }

    #[DataProvider('providerBoot')]
    public function testBoot(array $data = [], array $conditions = [], array $expected = []): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('openswoole.use_fiber_context', $data['useFiberContext']);
        $bundle = new OpenSwooleBundle();
        $bundle->setContainer($container);

        $bundle->boot();

        self::assertSame($expected['useFiberContext'], Coroutine::getOptions()['use_fiber_context'] ?? null);
    }

    /**
     * The disabled case must run first: Coroutine::set() is process-global and cannot be reverted.
     */
    public static function providerBoot(): iterable
    {
        yield 'fiber context disabled: coroutine option is not set' => [
            'data' => ['useFiberContext' => false],
            'conditions' => [],
            'expected' => ['useFiberContext' => null],
        ];

        yield 'fiber context enabled: coroutine option is set' => [
            'data' => ['useFiberContext' => true],
            'conditions' => [],
            'expected' => ['useFiberContext' => true],
        ];
    }
}
