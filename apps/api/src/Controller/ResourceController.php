<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\ResourceContent;
use App\Service\Resources;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Bibliothèque de ressources (page « Ressources » de l'app Artefacts). Mêmes règles que les outils MCP
 * `search_resources` / `get_resource` / `save_resource` / `delete_resource`.
 */
#[Route('/api/resources')]
#[IsGranted('ROLE_USER')]
final class ResourceController extends AbstractController
{
    private const REF = '(?:mine:[a-z0-9-]+|base:[a-z0-9-]+/[a-z0-9-]+|[a-z0-9-]+)';

    public function __construct(private readonly Resources $resources)
    {
    }

    #[Route('', name: 'api_resources_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $q = $request->query;

        return $this->json(['member' => $this->resources->search(
            $user,
            $q->getString('q'),
            $q->getString('kind') ?: null,
            $q->getString('tag') ?: null,
            $q->getBoolean('base', true),
            $q->getInt('limit', 50),
            $q->getInt('offset', 0),
        )]);
    }

    /** Fiche + versions (sans contenu). */
    #[Route('/{ref}', name: 'api_resources_get', methods: ['GET'], requirements: ['ref' => self::REF])]
    public function get(string $ref): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $meta = $this->resources->read($user, $ref)['meta'];

        return $this->json(['resource' => $meta, 'versions' => $meta['source'] === 'mine' ? $this->resources->versions($user, (string) $meta['slug']) : []]);
    }

    /** Contenu brut (`?version=N` pour une version précédente), servi en pièce jointe : jamais interprété par le navigateur. */
    #[Route('/{ref}/content', name: 'api_resources_content', methods: ['GET'], requirements: ['ref' => self::REF])]
    public function content(string $ref, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $res = $this->resources->read($user, $ref, $request->query->has('version') ? $request->query->getInt('version') : null);
        $type = ResourceContent::isText((string) $res['meta']['kind'], (string) ($res['meta']['contentType'] ?? 'text/plain')) ? 'text/plain; charset=UTF-8' : (string) ($res['meta']['contentType'] ?? 'application/octet-stream');

        return new Response($res['data'], 200, [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^a-z0-9._-]/', '-', (string) $res['meta']['slug']).'"',
        ]);
    }

    /**
     * Crée ou met à jour. JSON : {kind, name, slug?, description?, tags?, prompt?, from?, filename?} et
     * `content` (texte) ou `contentBase64`.
     */
    #[Route('', name: 'api_resources_save', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $b = json_decode($request->getContent(), true);
        if (!\is_array($b)) {
            throw new BadRequestHttpException('Corps JSON attendu.');
        }
        $text = \is_string($b['content'] ?? null) && $b['content'] !== '';
        $b64 = \is_string($b['contentBase64'] ?? null) && $b['contentBase64'] !== '';
        if ($text === $b64) {
            throw new BadRequestHttpException('Fournir exactement une source : content ou contentBase64.');
        }
        $bytes = $text ? (string) $b['content'] : base64_decode((string) $b['contentBase64'], true);
        if ($bytes === false) {
            throw new BadRequestHttpException('contentBase64 invalide.');
        }

        return $this->json($this->resources->save($user, [
            'kind' => (string) ($b['kind'] ?? ''),
            'name' => (string) ($b['name'] ?? ''),
            'slug' => \is_string($b['slug'] ?? null) ? $b['slug'] : null,
            'description' => \is_string($b['description'] ?? null) ? $b['description'] : null,
            'tags' => $b['tags'] ?? [],
            'prompt' => \is_string($b['prompt'] ?? null) ? $b['prompt'] : null,
            'from' => \is_string($b['from'] ?? null) ? $b['from'] : null,
            'filename' => \is_string($b['filename'] ?? null) ? $b['filename'] : null,
            'bytes' => $bytes,
        ], $request->getClientIp()));
    }

    #[Route('/{slug}', name: 'api_resources_delete', methods: ['DELETE'], requirements: ['slug' => '[a-z0-9-]+'])]
    public function delete(string $slug, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->resources->delete($user, $slug, $request->getClientIp());

        return $this->json(['ok' => true]);
    }
}
