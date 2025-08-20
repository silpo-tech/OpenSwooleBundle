<?php

declare(strict_types=1);

namespace OpenSwooleBundle\DependencyInjection\CompilerPass;

use OpenSwooleBundle\EventSubscriber\SentryPublisherSubscriber;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class SentryPublisherSubscriberPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        if ($container->hasDefinition('Sentry\ClientInterface')) {
            $definition = new Definition(SentryPublisherSubscriber::class);
            $definition->addTag('kernel.event_subscriber');
            $definition->setArguments([new Reference('Sentry\ClientInterface')]);
            $container->setDefinition(SentryPublisherSubscriber::class, $definition);
        }
    }
}
