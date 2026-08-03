<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\NotificationPreferencesService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/notification-preferences')]
#[IsGranted('ROLE_USER')]
final class NotificationPreferencesController extends AbstractController
{
    public function __construct(
        private readonly NotificationPreferencesService $prefs,
    ) {
    }

    #[Route('', name: 'api_notification_preferences_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        return $this->json([
            'preferences' => $this->prefs->catalogueWithPrefs($this->user()),
        ]);
    }

    #[Route('', name: 'api_notification_preferences_put', methods: ['PUT'])]
    public function put(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'JSON body required.'], Response::HTTP_BAD_REQUEST);
        }

        $patch = $payload['preferences'] ?? $payload;
        if (!is_array($patch)) {
            return $this->json(['error' => 'preferences object required.'], Response::HTTP_BAD_REQUEST);
        }

        // Accept either {preferences: {type: bool}} or [{type, enabled}]
        $normalized = [];
        if (array_is_list($patch)) {
            foreach ($patch as $row) {
                if (!is_array($row) || !is_string($row['type'] ?? null)) {
                    continue;
                }
                if (!array_key_exists('enabled', $row) || !is_bool($row['enabled'])) {
                    continue;
                }
                $normalized[$row['type']] = $row['enabled'];
            }
        } else {
            foreach ($patch as $type => $enabled) {
                if (is_string($type)) {
                    $normalized[$type] = $enabled;
                }
            }
        }

        if ($normalized === []) {
            return $this->json(['error' => 'No valid preferences provided.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'preferences' => $this->prefs->update($this->user(), $normalized),
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
