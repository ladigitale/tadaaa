<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\GoogleCalendarService;
use App\Service\GoogleOAuthClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/google-calendar')]
final class GoogleCalendarController extends AbstractController
{
    public function __construct(
        private readonly GoogleCalendarService $googleCalendar,
        private readonly GoogleOAuthClient $oauth,
    ) {
    }

    #[Route('/status', name: 'api_gcal_status', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function status(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json($this->googleCalendar->status($user));
    }

    #[Route('/connect', name: 'api_gcal_connect', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function connect(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json($this->googleCalendar->connect($user));
    }

    #[Route('/callback', name: 'api_gcal_callback', methods: ['GET'])]
    public function callback(Request $request): RedirectResponse
    {
        $error = $request->query->getString('error');
        if ($error !== '') {
            return $this->redirect($this->oauth->frontendRedirect('error', $error));
        }
        $code = $request->query->getString('code');
        $state = $request->query->getString('state');
        if ($code === '' || $state === '') {
            return $this->redirect($this->oauth->frontendRedirect('error', 'missing_code'));
        }
        try {
            $this->oauth->handleCallback($code, $state);
        } catch (\Throwable $e) {
            return $this->redirect($this->oauth->frontendRedirect('error', $e->getMessage()));
        }

        return $this->redirect($this->oauth->frontendRedirect('connected'));
    }

    #[Route('/disconnect', name: 'api_gcal_disconnect', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function disconnect(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->googleCalendar->disconnect($user);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/calendars', name: 'api_gcal_calendars', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function calendars(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json(['member' => $this->googleCalendar->listCalendars($user)]);
    }

    #[Route('/bindings', name: 'api_gcal_bindings_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function createBinding(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        /** @var array<string, mixed> $payload */
        $payload = json_decode($request->getContent(), true) ?? [];

        return $this->json(
            ['binding' => $this->googleCalendar->createBinding($user, $payload)],
            Response::HTTP_CREATED,
        );
    }

    #[Route('/bindings/{id}', name: 'api_gcal_bindings_update', methods: ['PATCH'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    public function updateBinding(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        /** @var array<string, mixed> $payload */
        $payload = json_decode($request->getContent(), true) ?? [];

        return $this->json(['binding' => $this->googleCalendar->updateBinding($user, $id, $payload)]);
    }

    #[Route('/bindings/{id}', name: 'api_gcal_bindings_delete', methods: ['DELETE'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    public function deleteBinding(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->googleCalendar->deleteBinding($user, $id);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/sync', name: 'api_gcal_sync', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function syncNow(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        /** @var array{bindingId?: string} $payload */
        $payload = json_decode($request->getContent(), true) ?? [];
        $bindingId = isset($payload['bindingId']) && is_string($payload['bindingId'])
            ? $payload['bindingId']
            : null;

        return $this->json($this->googleCalendar->syncNow($user, $bindingId));
    }

    #[Route('/push', name: 'api_gcal_push', methods: ['POST'])]
    public function push(Request $request): Response
    {
        $channelId = $request->headers->get('X-Goog-Channel-ID', '');
        $token = $request->headers->get('X-Goog-Channel-Token', '');
        if ($channelId !== '') {
            $this->googleCalendar->handlePush($channelId, $token);
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
