<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\ArtifactAppIcon;
use App\Service\ArtifactDataService;
use App\Service\ArtifactPageImage;
use App\Service\ArtifactService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/public/artifacts')]
final class PublicArtifactController extends AbstractController
{
    public function __construct(
        private readonly ArtifactService $artifacts,
        private readonly ArtifactDataService $artifactData,
        private readonly ArtifactPageImage $pageImage,
        #[Autowire(service: 'limiter.artifacts_public')]
        private readonly RateLimiterFactoryInterface $artifactsPublicLimiter,
        #[Autowire(service: 'limiter.artifacts_intake')]
        private readonly RateLimiterFactoryInterface $artifactsIntakeLimiter,
    ) {
    }

    #[Route('/{slug}/collections/{name}', name: 'api_public_artifact_collection', methods: ['GET'], requirements: ['slug' => '[a-z0-9][a-z0-9-]{2,63}', 'name' => '[a-z][a-z0-9_]{0,63}'])]
    public function collection(string $slug, string $name, Request $request): JsonResponse
    {
        $this->consumeRate($request);
        $user = $this->getUser();
        $readKey = $request->query->getString('rk') ?: null;
        $response = $this->json(['member' => $this->artifactData->readPublic(
            $slug,
            $name,
            $request->query->getString('k') ?: null,
            $readKey,
            $user instanceof User ? $user : null,
            $request->query->getString('sort') ?: null,
            $request->query->has('limit') ? max(1, $request->query->getInt('limit')) : null,
        )]);
        // Lecture par lien secret ou membre : jamais en cache partagé.
        $response->headers->set('Cache-Control', $readKey !== null || $user instanceof User ? 'private, no-store' : 'public, max-age=30');

        return $response;
    }

    /**
     * Envoi anonyme dans une collecte contrôlée (schéma + session ouverte + quota).
     * Corps : {"data": {...}, "code": "1234"?}
     */
    #[Route('/{slug}/collections/{name}/records', name: 'api_public_artifact_intake', methods: ['POST'], requirements: ['slug' => '[a-z0-9][a-z0-9-]{2,63}', 'name' => '[a-z][a-z0-9_]{0,63}'])]
    public function intake(string $slug, string $name, Request $request): JsonResponse
    {
        $limit = $this->artifactsIntakeLimiter->create($request->getClientIp() ?: 'anon')->consume(1);
        if (!$limit->isAccepted()) {
            $retry = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($retry, 'Trop d’envois, réessayez dans quelques secondes.');
        }
        if (\strlen($request->getContent()) > 4096) {
            return $this->json(['error' => 'Envoi trop volumineux.'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        /** @var mixed $body */
        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            return $this->json(['error' => 'JSON attendu.'], Response::HTTP_BAD_REQUEST);
        }
        $code = $body['code'] ?? null;
        $result = $this->artifactData->createIntakeRecord(
            $slug,
            $name,
            $request->query->getString('k') ?: null,
            $body['data'] ?? null,
            \is_string($code) || \is_int($code) ? (string) $code : null,
            $request->getClientIp() ?: 'anon',
            ($user = $this->getUser()) instanceof User ? $user : null,
        );
        $response = $this->json($result, Response::HTTP_CREATED);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/{slug}', name: 'api_public_artifact_get', methods: ['GET'], requirements: ['slug' => '[a-z0-9][a-z0-9-]{2,63}'])]
    public function get(string $slug, Request $request): JsonResponse
    {
        $this->consumeRate($request);
        $user = $this->getUser();
        $result = $this->artifacts->publicRead(
            $slug,
            $request->query->getString('k') ?: null,
            $user instanceof User ? $user : null,
            $request->query->getBoolean('draft'),
            $request->getSchemeAndHttpHost(),
        );

        // Objets vides du document (`\stdClass`) gardés en `{}`.
        $response = $this->json($result['body'], 200, [], ['preserve_empty_objects' => true]);
        $response->setEtag($result['etag']);
        if ($response->isNotModified($request)) {
            return $response;
        }
        // Brouillon (réservé aux membres) : jamais en cache partagé.
        $response->headers->set('Cache-Control', ($result['body']['draft'] ?? false) ? 'private, no-store' : 'public, max-age=30');
        $response->headers->set('ETag', $result['etag']);

        return $response;
    }

    /**
     * Asset binaire d'un artefact (image, son, modèle 3D, JSON…) : mêmes droits de lecture que l'artefact.
     * Servi avec son type vérifié, `nosniff` et une CSP verrouillée ; `?v=<empreinte>` = cache immuable.
     */
    #[Route('/{slug}/assets/{name}', name: 'api_public_artifact_asset', methods: ['GET'], requirements: ['slug' => '[a-z0-9][a-z0-9-]{2,63}', 'name' => '[a-z0-9][a-z0-9._-]{0,63}'])]
    public function asset(string $slug, string $name, Request $request): Response
    {
        $this->consumeRate($request);
        $user = $this->getUser();
        $asset = $this->artifacts->readPublicAsset(
            $slug,
            $name,
            $request->query->getString('k') ?: null,
            $user instanceof User ? $user : null,
        );

        $response = new Response();
        $response->setEtag($asset['sha256']);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
        $response->headers->set('Cross-Origin-Resource-Policy', 'cross-origin');
        $response->headers->set('Accept-Ranges', 'bytes');
        // URL versionnée (?v=empreinte) d'un artefact public : l'asset ne change plus à cette adresse.
        $immutable = $asset['visibility'] === 'public' && $request->query->has('v') && str_starts_with($asset['sha256'], $request->query->getString('v')) && $request->query->getString('v') !== '';
        $response->headers->set('Cache-Control', $immutable ? 'public, max-age=31536000, immutable' : ($asset['visibility'] === 'public' ? 'public, max-age=60' : 'private, max-age=60'));
        if ($response->isNotModified($request)) {
            return $response;
        }

        $data = $asset['data'];
        $total = \strlen($data);
        $response->headers->set('Content-Type', $asset['contentType']);
        $range = $request->headers->get('Range');
        if ($range !== null && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') { // derniers N octets
                $start = max(0, $total - (int) $m[2]);
                $end = $total - 1;
            } else {
                $start = (int) $m[1];
                $end = $m[2] === '' ? $total - 1 : min((int) $m[2], $total - 1);
            }
            if ($start > $end || $start >= $total) {
                $response->setStatusCode(Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE);
                $response->headers->set('Content-Range', 'bytes */'.$total);

                return $response;
            }
            $response->setStatusCode(Response::HTTP_PARTIAL_CONTENT);
            $response->headers->set('Content-Range', sprintf('bytes %d-%d/%d', $start, $end, $total));
            $response->setContent(substr($data, $start, $end - $start + 1));

            return $response;
        }
        $response->setContent($data);

        return $response;
    }

    /**
     * Manifeste d'installation (PWA) d'un artefact : nom, couleurs et icônes tirés de `document.icon`,
     * `start_url` = la page de l'artefact (avec son jeton de lien). Lu par le viewer (`<link rel="manifest">`).
     */
    #[Route('/{slug}/manifest.webmanifest', name: 'api_public_artifact_manifest', methods: ['GET'], requirements: ['slug' => '[a-z0-9][a-z0-9-]{2,63}'])]
    public function manifest(string $slug, Request $request): JsonResponse
    {
        $this->consumeRate($request);
        $user = $this->getUser();
        $k = $request->query->getString('k') ?: null;
        $result = $this->artifacts->publicRead($slug, $k, $user instanceof User ? $user : null, false, $request->getSchemeAndHttpHost());
        $body = $result['body'];
        $icon = ArtifactAppIcon::fromDocument($body['document'] ?? null);
        $title = trim((string) ($body['title'] ?? $slug)) ?: $slug;
        $start = $this->artifacts->publicUrl($slug, $k);
        $iconUrl = $request->getSchemeAndHttpHost().'/api/public/artifacts/'.rawurlencode($slug).'/icon.svg'.($k ? '?k='.rawurlencode($k) : '');
        $icons = [['src' => $iconUrl, 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any']];
        if ($icon['src'] !== null) {
            array_unshift($icons, ['src' => $icon['src'], 'sizes' => '512x512', 'purpose' => 'any']);
        }
        $manifest = [
            'id' => $this->artifacts->publicUrl($slug),
            'name' => mb_substr($title, 0, 60),
            'short_name' => mb_substr($title, 0, 12),
            'description' => mb_substr((string) ($body['description'] ?? ''), 0, 300),
            'start_url' => $start,
            'scope' => $this->artifacts->publicUrl($slug),
            'display' => 'standalone',
            'background_color' => $icon['background'],
            'theme_color' => $icon['background'],
            'icons' => $icons,
            // Captures d'application (fenêtre d'installation enrichie) : image de page large et format téléphone.
            'screenshots' => [
                ['src' => $this->pageImageUrl($request, $slug, $body, $k), 'sizes' => '1200x630', 'type' => 'image/png', 'form_factor' => 'wide', 'label' => mb_substr($title, 0, 60)],
                ['src' => $this->pageImageUrl($request, $slug, $body, $k, true), 'sizes' => '540x1170', 'type' => 'image/png', 'form_factor' => 'narrow', 'label' => mb_substr($title, 0, 60)],
            ],
        ];
        $response = $this->json($manifest);
        $response->headers->set('Content-Type', 'application/manifest+json');
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Cache-Control', 'public, max-age=300');

        return $response;
    }

    /**
     * Image de page d'un artefact (PNG 1200 × 630, `?f=narrow` : 540 × 1170) : `document.image.src` (redirection),
     * sinon capture du viewer (ou carte icône + titre), rendue une fois par version puis servie du cache.
     */
    #[Route('/{slug}/og.png', name: 'api_public_artifact_page_image', methods: ['GET'], requirements: ['slug' => '[a-z0-9][a-z0-9-]{2,63}'])]
    public function pageImage(string $slug, Request $request): Response
    {
        $this->consumeRate($request);
        $user = $this->getUser();
        $k = $request->query->getString('k') ?: null;
        $body = $this->artifacts->publicRead($slug, $k, $user instanceof User ? $user : null, false, $request->getSchemeAndHttpHost())['body'];
        $narrow = $request->query->getString('f') === 'narrow';
        $image = ArtifactPageImage::fromDocument($body['document'] ?? null);
        if ($image['src'] !== null && !$narrow) {
            $response = new RedirectResponse($image['src'], Response::HTTP_FOUND);
            $response->headers->set('Cache-Control', 'public, max-age=300');

            return $response;
        }
        set_time_limit(120);
        $file = $this->pageImage->png($body, $narrow, $this->artifacts->assetUrlPrefix($slug, $request->getSchemeAndHttpHost()));
        if ($file === null) {
            // Rendu indisponible : image générique du site Artefacts.
            $response = new RedirectResponse($this->siteUrl($slug).'/og.png', Response::HTTP_FOUND);
            $response->headers->set('Cache-Control', 'public, max-age=60');

            return $response;
        }
        $response = new BinaryFileResponse($file);
        $response->headers->set('Content-Type', 'image/png');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Cross-Origin-Resource-Policy', 'cross-origin');
        // L'adresse porte la version (?v=) : une nouvelle version change d'URL.
        $response->headers->set('Cache-Control', ($body['visibility'] ?? '') === 'public' ? 'public, max-age=86400' : 'private, max-age=3600');

        return $response;
    }

    /**
     * Page de partage d'un artefact pour les robots d'aperçu de liens (Slack, WhatsApp, Discord, X…), qui
     * n'exécutent pas le viewer : titre, description et image de page en balises Open Graph. Le site Artefacts
     * y envoie ces robots seulement (Caddy, `deploy/cohost/artifacts.caddy`) ; les visiteurs ont le viewer.
     */
    #[Route('/{slug}/share', name: 'api_public_artifact_share', methods: ['GET'], requirements: ['slug' => '[a-z0-9][a-z0-9-]{2,63}'])]
    public function share(string $slug, Request $request): Response
    {
        $this->consumeRate($request);
        $user = $this->getUser();
        $k = $request->query->getString('k') ?: null;
        $site = $this->siteUrl($slug);
        try {
            $body = $this->artifacts->publicRead($slug, $k, $user instanceof User ? $user : null, false, $request->getSchemeAndHttpHost())['body'];
        } catch (NotFoundHttpException) {
            $body = null;
        }
        $esc = static fn (string $s): string => htmlspecialchars($s, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        if ($body === null) {
            // Artefact privé ou inconnu : rien n'en sort, aperçu générique du site.
            $title = 'Artefacts';
            $description = 'Pages interactives publiées par des agents IA via Tadaaa.';
            $url = $site.'/';
            $image = $site.'/og.png';
            $alt = 'Artefacts';
            $icon = null;
        } else {
            $title = trim((string) ($body['title'] ?? '')) ?: $slug;
            $description = trim((string) ($body['description'] ?? '')) ?: 'Un artefact publié sur Artefacts.';
            $url = $this->artifacts->publicUrl($slug, $k);
            $page = ArtifactPageImage::fromDocument($body['document'] ?? null);
            $image = $page['src'] ?? $this->pageImageUrl($request, $slug, $body, $k);
            $alt = $page['alt'] ?? $title;
            $icon = ArtifactAppIcon::fromDocument($body['document'] ?? null)['src']
                ?? $request->getSchemeAndHttpHost().'/api/public/artifacts/'.rawurlencode($slug).'/icon.svg'.($k ? '?k='.rawurlencode($k) : '');
        }
        $description = mb_strlen($description) > 300 ? rtrim(mb_substr($description, 0, 299)).'…' : $description;
        $meta = [
            ['property', 'og:type', 'website'],
            ['property', 'og:site_name', 'Artefacts'],
            ['property', 'og:title', $title],
            ['property', 'og:description', $description],
            ['property', 'og:url', $url],
            ['property', 'og:image', $image],
            ['property', 'og:image:alt', $alt],
            ['name', 'twitter:card', 'summary_large_image'],
            ['name', 'twitter:title', $title],
            ['name', 'twitter:description', $description],
            ['name', 'twitter:image', $image],
            ['name', 'twitter:image:alt', $alt],
            ['name', 'description', $description],
        ];
        if ($body !== null && ($page['src'] ?? null) === null) {
            $meta[] = ['property', 'og:image:type', 'image/png'];
            $meta[] = ['property', 'og:image:width', '1200'];
            $meta[] = ['property', 'og:image:height', '630'];
        }
        $html = "<!doctype html>\n<html lang=\"fr\">\n<head>\n<meta charset=\"utf-8\">\n<title>".$esc($title)."</title>\n";
        foreach ($meta as [$attr, $name, $content]) {
            $html .= '<meta '.$attr.'="'.$name.'" content="'.$esc($content).'">'."\n";
        }
        $html .= '<link rel="canonical" href="'.$esc($url).'">'."\n";
        if ($icon !== null) {
            $html .= '<link rel="icon" href="'.$esc($icon).'">'."\n";
        }
        $html .= "</head>\n<body>\n<h1>".$esc($title)."</h1>\n<p>".$esc($description)."</p>\n"
            .'<p><a href="'.$esc($url).'">'.$esc($url)."</a></p>\n</body>\n</html>\n";

        $response = new Response($html, $body === null ? Response::HTTP_NOT_FOUND : Response::HTTP_OK);
        $response->headers->set('Content-Type', 'text/html; charset=utf-8');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src https:; base-uri 'none'");
        $response->headers->set('Cache-Control', $k !== null || $user instanceof User ? 'private, max-age=60' : 'public, max-age=300');

        return $response;
    }

    /** URL de l'image de page (versionnée : une nouvelle version change d'adresse et de cache). */
    private function pageImageUrl(Request $request, string $slug, array $body, ?string $k, bool $narrow = false): string
    {
        $q = ['v' => ($body['version'] ?? 0).'-'.substr(sha1((string) ($body['title'] ?? '').'|'.($body['description'] ?? '')), 0, 6)];
        if ($narrow) {
            $q['f'] = 'narrow';
        }
        if ($k !== null) {
            $q['k'] = $k;
        }

        return $request->getSchemeAndHttpHost().'/api/public/artifacts/'.rawurlencode($slug).'/og.png?'.http_build_query($q);
    }

    /** Origine du site Artefacts (sans « / » final). */
    private function siteUrl(string $slug): string
    {
        $url = $this->artifacts->publicUrl($slug);

        return substr($url, 0, -\strlen('/'.rawurlencode($slug)));
    }

    /** Icône SVG d'un artefact (emoji ou lettres sur un fond), pour l'onglet et l'installation. */
    #[Route('/{slug}/icon.svg', name: 'api_public_artifact_icon', methods: ['GET'], requirements: ['slug' => '[a-z0-9][a-z0-9-]{2,63}'])]
    public function icon(string $slug, Request $request): Response
    {
        $this->consumeRate($request);
        $user = $this->getUser();
        $result = $this->artifacts->publicRead($slug, $request->query->getString('k') ?: null, $user instanceof User ? $user : null, false, $request->getSchemeAndHttpHost());
        $body = $result['body'];
        $svg = ArtifactAppIcon::svg(ArtifactAppIcon::fromDocument($body['document'] ?? null), (string) ($body['title'] ?? $slug));
        $response = new Response($svg);
        $response->headers->set('Content-Type', 'image/svg+xml');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'");
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Cross-Origin-Resource-Policy', 'cross-origin');
        $response->headers->set('Cache-Control', 'public, max-age=300');

        return $response;
    }

    #[Route('/{slug}/meta', name: 'api_public_artifact_meta', methods: ['GET'], requirements: ['slug' => '[a-z0-9][a-z0-9-]{2,63}'])]
    public function meta(string $slug, Request $request): JsonResponse
    {
        $this->consumeRate($request);
        $user = $this->getUser();
        $body = $this->artifacts->publicMeta(
            $slug,
            $request->query->getString('k') ?: null,
            $user instanceof User ? $user : null,
        );

        $response = $this->json($body);
        $response->headers->set('Cache-Control', 'public, max-age=60');

        return $response;
    }

    #[Route('/{slug}/collections/{name}/records', name: 'api_public_artifact_intake_options', methods: ['OPTIONS'], requirements: ['slug' => '[a-z0-9][a-z0-9-]{2,63}', 'name' => '[a-z][a-z0-9_]{0,63}'])]
    public function intakeOptions(): Response
    {
        return $this->options();
    }

    #[Route('/{slug}', name: 'api_public_artifact_options', methods: ['OPTIONS'], requirements: ['slug' => '[a-z0-9][a-z0-9-]{2,63}'])]
    public function options(): Response
    {
        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function consumeRate(Request $request): void
    {
        $limiter = $this->artifactsPublicLimiter->create($request->getClientIp() ?: 'anon');
        if (!$limiter->consume(1)->isAccepted()) {
            throw $this->createAccessDeniedException('Trop de requêtes.');
        }
    }
}
