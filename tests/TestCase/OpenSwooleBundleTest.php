<?php

declare(strict_types=1);

namespace ExceptionHandlerBundle\Tests;

use OpenSwooleBundle\DependencyInjection\CompilerPass\DoctrineCleanerSubscriberPass;
use OpenSwooleBundle\DependencyInjection\CompilerPass\LoggerCompilerPass;
use OpenSwooleBundle\DependencyInjection\CompilerPass\SentryPublisherSubscriberPass;
use OpenSwooleBundle\OpenSwooleBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class OpenSwooleBundleTest extends TestCase
{
    public function testBuild()
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
}
