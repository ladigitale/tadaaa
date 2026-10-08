<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * CORS ouvert pour la lecture publique des artefacts depuis n'importe quel site
 * (intégration `<script src=".../embed.js">` du viewer Artefacts).
 *
 * Ne change rien aux droits : visibilité, jeton de lien (`k`), lien secret (`rk`),
 * collecte contrôlée et limites de débit restent appliqués par le contrôleur ; le CORS
 * permet seulement à un navigateur de lire ce qu'un `curl` obtient déjà.
 * Jamais d'`Access-Control-Allow-Credentials` ni d'`Authorization` : l'embed est anonyme.
 *
 * Les origines de CORS_ALLOW_ORIGIN (viewer Artefacts, dev) gardent la configuration
 * Nelmio (avec Authorization pour les membres) : ce subscriber passe après et ne
 * touche pas une réponse qui a déjà son en-tête.
 */
final class PublicArtifactCorsSubscriber implements EventSubscriberInterface
{
    private const PATH_PREFIX = '/api/public/artifacts/';

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
        if (!str_starts_with($request->getPathInfo(), self::PATH_PREFIX)) {
            return;
        }

        $origin = $request->headers->get('Origin');
        if ($origin === null || $origin === '') {
            return;
        }

        $response = $event->getResponse();
        if ($response->headers->has('Access-Control-Allow-Origin')) {
            return;
        }

        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Expose-Headers', 'ETag, Cache-Control, Retry-After');

        if ($request->getMethod() === 'OPTIONS') {
            // POST : uniquement l'envoi en collecte contrôlée (…/collections/{name}/records).
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, If-None-Match');
            $response->headers->set('Access-Control-Max-Age', '3600');
            if ($response->getStatusCode() === Response::HTTP_NO_CONTENT) {
                $response->setContent('');
            }
        }
    }
}
