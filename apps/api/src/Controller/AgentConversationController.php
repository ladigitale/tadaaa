<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\ConversationHistory;
use App\Entity\AgentConversation;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Historique des conversations de l'agent (reprise dans l'atelier d'Artefacts) :
 * `GET /api/agent/{profile}/threads`, `GET|PATCH|DELETE /api/agent/{profile}/threads/{threadId}`.
 * Toujours limité aux conversations de l'utilisateur connecté.
 */
#[Route('/api/agent/{profile}/threads', requirements: ['profile' => 'tasks|artifacts'])]
#[IsGranted('ROLE_USER')]
final class AgentConversationController extends AbstractController
{
    public function __construct(private readonly ConversationHistory $history)
    {
    }

    #[Route('', name: 'api_agent_threads', methods: ['GET'])]
    public function list(string $profile): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return new JsonResponse(['threads' => array_map(
            static fn (AgentConversation $c): array => self::summary($c),
            $this->history->list($user, $profile),
        )]);
    }

    #[Route('/{threadId}', name: 'api_agent_thread', methods: ['GET'], requirements: ['threadId' => '[A-Za-z0-9_-]{1,100}'])]
    public function show(string $profile, string $threadId): JsonResponse
    {
        $conversation = $this->find($profile, $threadId);
        if ($conversation === null) {
            return new JsonResponse(['detail' => 'Conversation introuvable.'], 404);
        }
        $entries = json_decode($conversation->getTranscript(), true);

        return new JsonResponse([
            ...self::summary($conversation),
            'entries' => \is_array($entries) ? $entries : [],
            'preview' => $conversation->getMeta()['preview'] ?? null,
            'published' => $conversation->getMeta()['published'] ?? null,
        ]);
    }

    #[Route('/{threadId}', name: 'api_agent_thread_rename', methods: ['PATCH'], requirements: ['threadId' => '[A-Za-z0-9_-]{1,100}'])]
    public function rename(Request $request, string $profile, string $threadId): JsonResponse
    {
        $conversation = $this->find($profile, $threadId);
        if ($conversation === null) {
            return new JsonResponse(['detail' => 'Conversation introuvable.'], 404);
        }
        try {
            $title = $request->toArray()['title'] ?? null;
        } catch (\Throwable) {
            $title = null;
        }
        if (!\is_string($title) || trim($title) === '') {
            return new JsonResponse(['detail' => 'Titre requis.'], 400);
        }
        $this->history->rename($conversation, $title);

        return new JsonResponse(self::summary($conversation));
    }

    #[Route('/{threadId}', name: 'api_agent_thread_delete', methods: ['DELETE'], requirements: ['threadId' => '[A-Za-z0-9_-]{1,100}'])]
    public function delete(string $profile, string $threadId): Response
    {
        $conversation = $this->find($profile, $threadId);
        if ($conversation === null) {
            return new JsonResponse(['detail' => 'Conversation introuvable.'], 404);
        }
        /** @var User $user */
        $user = $this->getUser();
        $this->history->delete($user, $conversation);

        return new Response(null, 204);
    }

    private function find(string $profile, string $threadId): ?AgentConversation
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->history->find($user, $profile, $threadId);
    }

    /** @return array<string, mixed> */
    private static function summary(AgentConversation $c): array
    {
        return [
            'threadId' => $c->getThreadId(),
            'title' => $c->getTitle(),
            'artifactSlug' => $c->getArtifactSlug(),
            'createdAt' => $c->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $c->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }
}
