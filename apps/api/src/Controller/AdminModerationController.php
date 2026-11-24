<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\ModerationService;
use App\Service\ProdDeploy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Modération de tous les artefacts, réservée au super admin (ROLE_ADMIN + OPS_DEPLOY_USERS) :
 *   GET  /api/admin/moderation?status=open|kept|hidden|deleted   → {items: [...], open}
 *   POST /api/admin/moderation/{id}   {"action": "keep"|"hide"|"delete"}  → dossier mis à jour
 */
#[Route('/api/admin/moderation')]
#[IsGranted('ROLE_ADMIN')]
final class AdminModerationController extends AbstractController
{
    public function __construct(
        private readonly ModerationService $moderation,
        private readonly ProdDeploy $superAdmin,
    ) {
    }

    #[Route('', name: 'api_admin_moderation_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->guard();
        $status = $request->query->getString('status') ?: null;

        return $this->noStore($this->json($this->moderation->queue($status)));
    }

    #[Route('/{id}', name: 'api_admin_moderation_decide', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function decide(string $id, Request $request): JsonResponse
    {
        $user = $this->guard();
        /** @var mixed $in */
        $in = json_decode($request->getContent() ?: '{}', true);
        if (!\is_array($in) || !\is_string($in['action'] ?? null)) {
            throw new BadRequestHttpException('Corps attendu : {"action": "keep" | "hide" | "delete"}.');
        }

        return $this->noStore($this->json($this->moderation->decide($id, $in['action'], $user)));
    }

    private function guard(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->superAdmin->isAllowed($user)) {
            throw new AccessDeniedHttpException('Réservé au super admin.');
        }

        return $user;
    }

    private function noStore(JsonResponse $r): JsonResponse
    {
        $r->headers->set('Cache-Control', 'private, no-store');

        return $r;
    }
}
