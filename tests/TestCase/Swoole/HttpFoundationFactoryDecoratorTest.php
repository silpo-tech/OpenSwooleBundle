<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Tests\TestCase\Swoole;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use OpenSwooleBundle\Swoole\HttpFoundationFactoryDecorator;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;

final class HttpFoundationFactoryDecoratorTest extends TestCase
{
    public function testCreate(): void
    {
        $factory = new HttpFoundationFactory();

        $decorator = new HttpFoundationFactoryDecorator($factory);

        $request = $decorator->createRequest(new ServerRequest(
            'http://localhost',
            'GET',
            serverParams: [
                'TRUSTED_HOSTS' => 'localhost,127.0.0.1',
                'TRUSTED_PROXIES' => '127.0.0.1',
            ],
        ));

        self::assertEquals(['{localhost}i', '{127.0.0.1}i'], $request::getTrustedHosts());
        self::assertEquals(['127.0.0.1'], $request::getTrustedProxies());
    }

    public function testCreateResponse(): void
    {
        $factory = new HttpFoundationFactory();

        $decorator = new HttpFoundationFactoryDecorator($factory);

        $response = $decorator->createResponse(new Response(json_encode(['test' => 'test']), 200));

        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('{"test":"test"}', (string) $response->getContent());
    }
}
