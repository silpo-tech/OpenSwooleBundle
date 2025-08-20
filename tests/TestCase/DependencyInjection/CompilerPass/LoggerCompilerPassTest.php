<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Tests\TestCase\DependencyInjection\CompilerPass;

use OpenSwooleBundle\DependencyInjection\CompilerPass\LoggerCompilerPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class LoggerCompilerPassTest extends TestCase
{
    public function testProcess(): void
    {
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setDefinition('monolog.logger_prototype', new Definition());

        $pass = new LoggerCompilerPass();

        $pass->process($containerBuilder);

        self::assertTrue($containerBuilder->getDefinition('monolog.logger_prototype')->hasMethodCall('useLoggingLoopDetection'));
    }
}
