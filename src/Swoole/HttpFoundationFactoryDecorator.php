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

        // Use static caches to avoid resetting globals on every request
        static $lastTrustedProxies = null;
        static $lastTrustedHosts = null;

        if ($trustedProxies = $serverParams['TRUSTED_PROXIES'] ?? false) {
            $proxies = explode(',', $trustedProxies);
            if ($lastTrustedProxies !== $trustedProxies) {
                $request::setTrustedProxies($proxies, Request::HEADER_X_FORWARDED_AWS_ELB);
                $lastTrustedProxies = $trustedProxies;
            }
        }

        if ($trustedHosts = $serverParams['TRUSTED_HOSTS'] ?? false) {
            $hosts = explode(',', $trustedHosts);
            if ($lastTrustedHosts !== $trustedHosts) {
                $request::setTrustedHosts($hosts);
                $lastTrustedHosts = $trustedHosts;
            }
        }

        return $request;
    }

    public function createResponse(ResponseInterface $psrResponse, bool $streamed = false): Response
    {
        return $this->decorated->createResponse($psrResponse, $streamed);
    }
}
