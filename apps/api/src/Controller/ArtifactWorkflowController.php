<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\ArtifactWorkflowService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Workflows d'artefacts (enchaînement d'artefacts-outils) : définition et runs. */
#[Route('/api/artifact-workflows')]
#[IsGranted('ROLE_USER')]
final class ArtifactWorkflowController extends AbstractController
{
    private const JSON_CONTEXT = ['preserve_empty_objects' => true];
    private const SLUG = '[a-z0-9][a-z0-9-]{2,63}';

    public function __construct(private readonly ArtifactWorkflowService $workflows)
    {
    }

    #[Route('', name: 'api_artifact_workflows_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(['member' => $this->workflows->list($this->user())]);
    }

    #[Route('/runs/{runId}', name: 'api_artifact_workflows_run_get', methods: ['GET'], requirements: ['runId' => '[0-9a-fA-F-]{36}'])]
    public function getRun(string $runId): JsonResponse
    {
        return $this->json($this->workflows->getRun($this->user(), $runId), 200, [], self::JSON_CONTEXT);
    }

    #[Route('/runs/{runId}/cancel', name: 'api_artifact_workflows_run_cancel', methods: ['POST'], requirements: ['runId' => '[0-9a-fA-F-]{36}'])]
    public function cancelRun(string $runId): JsonResponse
    {
        return $this->json($this->workflows->cancelRun($this->user(), $runId), 200, [], self::JSON_CONTEXT);
    }

    #[Route('/{slug}', name: 'api_artifact_workflows_get', methods: ['GET'], requirements: ['slug' => self::SLUG])]
    public function get(string $slug): JsonResponse
    {
        return $this->json($this->workflows->get($this->user(), $slug), 200, [], self::JSON_CONTEXT);
    }

    /** Corps : {"definition": {…}}. */
    #[Route('/{slug}', name: 'api_artifact_workflows_save', methods: ['PUT'], requirements: ['slug' => self::SLUG])]
    public function save(string $slug, Request $request): JsonResponse
    {
        $body = json_decode($request->getContent() ?: '{}', false);
        if (!$body instanceof \stdClass || !isset($body->definition)) {
            throw new BadRequestHttpException('JSON attendu : {"definition": {…}}.');
        }

        return $this->json($this->workflows->save($this->user(), $slug, $body->definition), 200, [], self::JSON_CONTEXT);
    }

    #[Route('/{slug}', name: 'api_artifact_workflows_delete', methods: ['DELETE'], requirements: ['slug' => self::SLUG])]
    public function delete(string $slug): Response
    {
        $this->workflows->delete($this->user(), $slug);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /** Corps : {"input": {…}}. */
    #[Route('/{slug}/runs', name: 'api_artifact_workflows_run', methods: ['POST'], requirements: ['slug' => self::SLUG])]
    public function run(string $slug, Request $request): JsonResponse
    {
        $body = json_decode($request->getContent() ?: '{}', false);
        if (!$body instanceof \stdClass) {
            throw new BadRequestHttpException('JSON attendu : {"input": {…}}.');
        }
        $run = $this->workflows->start($this->user(), $slug, $body->input ?? new \stdClass());

        return $this->json($run, 201, [], self::JSON_CONTEXT);
    }

    private function user(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
