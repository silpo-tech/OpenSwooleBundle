<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Swoole;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class HttpFoundationFactoryDecorator implements HttpFoundationFactoryInterface
{
    public function __construct(
        private HttpFoundationFactoryInterface $decorated,
    ) {
    }

    public function createRequest(ServerRequestInterface $psrRequest, bool $streamed = false): Request
    {
        $request = $this->decorated->createRequest($psrRequest, $streamed);

        $serverParams = $psrRequest->getServerParams();

        if ($trustedProxies = $serverParams['TRUSTED_PROXIES'] ?? false) {
            $request::setTrustedProxies(explode(',', $trustedProxies), Request::HEADER_X_FORWARDED_AWS_ELB);
        }

        if ($trustedHosts = $serverParams['TRUSTED_HOSTS'] ?? false) {
            $request::setTrustedHosts(explode(',', $trustedHosts));
        }

        return $request;
    }

    public function createResponse(ResponseInterface $psrResponse, bool $streamed = false): Response
    {
        return $this->decorated->createResponse($psrResponse, $streamed);
    }
}
