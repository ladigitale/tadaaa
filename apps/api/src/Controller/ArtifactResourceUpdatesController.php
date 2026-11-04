<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\ArtifactResourceSync;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ressources de la bibliothèque copiées dans un artefact : état « mise à jour disponible » et application
 * (atelier de l'app Artefacts).
 */
#[Route('/api/artifacts/{slug}/resource-updates', requirements: ['slug' => '[a-z0-9-]+'])]
#[IsGranted('ROLE_USER')]
final class ArtifactResourceUpdatesController extends AbstractController
{
    public function __construct(private readonly ArtifactResourceSync $sync)
    {
    }

    #[Route('', name: 'api_artifact_resource_updates', methods: ['GET'])]
    public function list(string $slug): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json(['member' => $this->sync->updates($user, $slug)]);
    }

    /** Corps JSON optionnel : {files?: [nom…], force?: bool}. */
    #[Route('', name: 'api_artifact_resource_updates_apply', methods: ['POST'])]
    public function apply(string $slug, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $b = $request->getContent() === '' ? [] : json_decode($request->getContent(), true);
        if (!\is_array($b)) {
            throw new BadRequestHttpException('Corps JSON attendu.');
        }
        $only = null;
        if (isset($b['files'])) {
            if (!\is_array($b['files']) || !array_is_list($b['files']) || array_filter($b['files'], static fn ($f) => !\is_string($f)) !== []) {
                throw new BadRequestHttpException('files : liste de noms de fichiers.');
            }
            $only = $b['files'];
        }

        return $this->json($this->sync->apply($user, $slug, $only, ($b['force'] ?? false) === true, $request->getClientIp()));
    }
}
