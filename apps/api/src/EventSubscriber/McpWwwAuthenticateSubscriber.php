<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\OAuthIssuer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * MCP OAuth discovery: unauthenticated /mcp must return 401 + WWW-Authenticate resource_metadata.
 */
final class McpWwwAuthenticateSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly OAuthIssuer $issuer)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', 0]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->getPathInfo() !== '/mcp' && !str_starts_with($request->getPathInfo(), '/mcp/')) {
            return;
        }

        $response = $event->getResponse();
        if ($response->getStatusCode() !== 401) {
            return;
        }

        $response->headers->set('WWW-Authenticate', $this->issuer->wwwAuthenticateHeader());
    }
}
