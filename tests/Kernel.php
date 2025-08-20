<?php

declare(strict_types=1);

namespace OpenSwooleBundle\Tests;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

final class Kernel implements HttpKernelInterface, TerminableInterface
{
    public function __construct(
        private ?\Closure $handler = null,
    ) {
    }

    public function handle(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST, bool $catch = true): Response
    {
        if (null === $this->handler) {
            throw new \RuntimeException('Handler not set');
        }

        return ($this->handler)($request, $type, $catch);
    }

    public function terminate(Request $request, Response $response): void
    {
    }

    public function setHandler(callable $handler): void
    {
        $this->handler = $handler;
    }
}
