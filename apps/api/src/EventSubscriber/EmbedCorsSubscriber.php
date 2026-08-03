<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\EmbedService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Dynamic CORS for /api/public/embeds/{token} based on EmbedKey.allowedOrigins.
 * Does not widen the global Nelmio CORS_ALLOW_ORIGIN regex.
 */
final class EmbedCorsSubscriber implements EventSubscriberInterface
{
    private const PATH_PREFIX = '/api/public/embeds/';

    public function __construct(private readonly EmbedService $embeds)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onResponse', -10],
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!str_starts_with($path, self::PATH_PREFIX)) {
            return;
        }

        $token = substr($path, \strlen(self::PATH_PREFIX));
        $token = explode('/', $token, 2)[0];
        if (!str_starts_with($token, 'emb_')) {
            return;
        }

        $origin = $request->headers->get('Origin');
        if ($origin === null || $origin === '') {
            return;
        }

        $key = $this->embeds->findUsableByPlainToken($token);
        if ($key === null || !$key->allowsOrigin($origin)) {
            return;
        }

        $response = $event->getResponse();
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Vary', 'Origin');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, If-None-Match');
        $response->headers->set('Access-Control-Max-Age', '3600');
        $response->headers->set('Access-Control-Expose-Headers', 'X-Tadaaa-Embed-Bytes, ETag');

        if ($request->getMethod() === 'OPTIONS' && $response->getStatusCode() === Response::HTTP_NO_CONTENT) {
            $response->setContent('');
        }
    }
}
