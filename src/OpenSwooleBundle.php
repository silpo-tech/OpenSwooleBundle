<?php

declare(strict_types=1);

namespace OpenSwooleBundle;

use OpenSwooleBundle\DependencyInjection\CompilerPass\DoctrineCleanerSubscriberPass;
use OpenSwooleBundle\DependencyInjection\CompilerPass\LoggerCompilerPass;
use OpenSwooleBundle\DependencyInjection\CompilerPass\SentryPublisherSubscriberPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Class OpenSwooleBundle.
 */
class OpenSwooleBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new DoctrineCleanerSubscriberPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 100);
        $container->addCompilerPass(new SentryPublisherSubscriberPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 100);
        $container->addCompilerPass(new LoggerCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 100);
    }
}
