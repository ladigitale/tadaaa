<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Dataset;
use App\Entity\User;
use App\Notification\NotificationEventType;
use App\Repository\DatasetMemberRepository;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;

/**
 * Fan-out Web Push to user devices. Failures never break sync/MCP.
 */
final class PushNotificationDispatcher
{
    public function __construct(
        private readonly PushFeature $push,
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly DatasetMemberRepository $members,
        private readonly NotificationPreferencesService $prefs,
        private readonly PushEndpointValidator $endpointValidator,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array{token: string, urlPath: string, role: string, expiresAt: string} $invite
     */
    public function notifyDatasetInvite(
        User $invitee,
        Dataset $dataset,
        User $inviter,
        array $invite,
    ): void {
        $role = $invite['role'] ?? '';
        $body = $role !== ''
            ? sprintf('%s invited you to “%s” (%s)', $inviter->getEmail(), $dataset->getName(), $role)
            : sprintf('%s invited you to “%s”', $inviter->getEmail(), $dataset->getName());

        $this->sendToUsers(
            [$invitee],
            NotificationEventType::DATASET_INVITE,
            [
                'title' => 'Tadaaa',
                'body' => $body,
                'tag' => 'tada-invite-'.$invite['urlPath'],
                'url' => $invite['urlPath'],
                'type' => NotificationEventType::DATASET_INVITE,
                'baseId' => $this->formatBaseId($dataset),
            ],
        );
    }

    public function notifyMemberJoined(Dataset $dataset, User $member, string $role): void
    {
        $body = $role !== ''
            ? sprintf('%s joined “%s” (%s)', $member->getEmail(), $dataset->getName(), $role)
            : sprintf('%s joined “%s”', $member->getEmail(), $dataset->getName());

        $recipients = $this->datasetRecipients($dataset, except: $member);
        $this->sendToUsers(
            $recipients,
            NotificationEventType::MEMBER_JOINED,
            [
                'title' => 'Tadaaa',
                'body' => $body,
                'tag' => 'tada-join-'.$this->formatBaseId($dataset),
                'url' => '/',
                'type' => NotificationEventType::MEMBER_JOINED,
                'baseId' => $this->formatBaseId($dataset),
            ],
        );
    }

    /**
     * @param list<array{type: string, id: string, text: string}> $events
     */
    public function notifyTodoEvents(Dataset $dataset, User $actor, array $events): void
    {
        if ($events === []) {
            return;
        }

        // Dedupe by id keeping latest type.
        $byId = [];
        foreach ($events as $event) {
            if (!NotificationEventType::isValid($event['type'])) {
                continue;
            }
            $byId[$event['id']] = $event;
        }
        $unique = array_values($byId);
        if ($unique === []) {
            return;
        }

        $recipients = $this->datasetRecipients($dataset, except: $actor);

        // Group by type for preference filtering + one notification per type batch.
        $byType = [];
        foreach ($unique as $event) {
            $byType[$event['type']][] = $event;
        }

        foreach ($byType as $type => $typedEvents) {
            $body = $this->formatTodoBatch($type, $typedEvents);
            if ($body === '') {
                continue;
            }
            $primaryId = $typedEvents[0]['id'];
            $this->sendToUsers(
                $recipients,
                $type,
                [
                    'title' => 'Tadaaa',
                    'body' => $body,
                    'tag' => 'tada-todos-'.$type,
                    'url' => '/tache/item/'.$primaryId,
                    'type' => $type,
                    'baseId' => $this->formatBaseId($dataset),
                ],
            );
        }
    }

    /**
     * @return list<User>
     */
    private function datasetRecipients(Dataset $dataset, ?User $except = null): array
    {
        $users = [$dataset->getOwner()];
        foreach ($this->members->findAllForDataset($dataset) as $member) {
            $users[] = $member->getUser();
        }

        $seen = [];
        $result = [];
        foreach ($users as $user) {
            $id = $user->getId()->toRfc4122();
            if (isset($seen[$id])) {
                continue;
            }
            if ($except !== null && $user->getId()->equals($except->getId())) {
                continue;
            }
            $seen[$id] = true;
            $result[] = $user;
        }

        return $result;
    }

    /**
     * @param list<User> $users
     * @param array<string, mixed> $payload
     */
    private function sendToUsers(array $users, string $type, array $payload): void
    {
        if (!$this->push->isEnabled() || $users === []) {
            return;
        }

        $eligible = [];
        foreach ($users as $user) {
            if ($this->prefs->isEnabled($user, $type)) {
                $eligible[] = $user;
            }
        }
        if ($eligible === []) {
            return;
        }

        $subs = $this->subscriptions->findActiveForUsers($eligible);
        if ($subs === []) {
            return;
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => $this->push->getSubject(),
                    'publicKey' => $this->push->getPublicKey(),
                    'privateKey' => $this->push->getPrivateKey(),
                ],
            ]);
        } catch (\Throwable $exception) {
            $this->logger->warning('Web Push init failed: {message}', [
                'message' => $exception->getMessage(),
            ]);

            return;
        }

        try {
            $json = json_encode($payload, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->logger->warning('Web Push payload encode failed: {message}', [
                'message' => $exception->getMessage(),
            ]);

            return;
        }

        $dirty = false;
        foreach ($subs as $sub) {
            try {
                try {
                    $this->endpointValidator->assertValid($sub->getEndpoint());
                } catch (\Throwable) {
                    $sub->revoke();
                    $dirty = true;
                    $this->logger->warning('Web Push revoked unsafe endpoint for user {user}', [
                        'user' => $sub->getUser()->getId()->toRfc4122(),
                    ]);
                    continue;
                }

                $subscription = Subscription::create([
                    'endpoint' => $sub->getEndpoint(),
                    'keys' => [
                        'p256dh' => $sub->getP256dh(),
                        'auth' => $sub->getAuth(),
                    ],
                ]);
                $report = $webPush->sendOneNotification($subscription, $json);
                if (!$report->isSuccess()) {
                    $code = $report->getResponse()?->getStatusCode();
                    if ($code === 404 || $code === 410) {
                        $sub->revoke();
                        $dirty = true;
                    } else {
                        $this->logger->warning('Web Push send failed for {endpoint}: {reason}', [
                            'endpoint' => $sub->getEndpoint(),
                            'reason' => $report->getReason(),
                        ]);
                    }
                } else {
                    $sub->touch();
                    $dirty = true;
                }
            } catch (\Throwable $exception) {
                $this->logger->warning('Web Push send exception for {endpoint}: {message}', [
                    'endpoint' => $sub->getEndpoint(),
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if ($dirty) {
            try {
                $this->entityManager->flush();
            } catch (\Throwable $exception) {
                $this->logger->warning('Web Push flush failed: {message}', [
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param list<array{type: string, id: string, text: string}> $events
     */
    private function formatTodoBatch(string $type, array $events): string
    {
        $n = count($events);
        $text = $this->truncate($events[0]['text'] ?? '');

        return match ($type) {
            NotificationEventType::TODO_CHECKED => $n === 1
                ? sprintf('Completed: %s', $text)
                : sprintf('%d tasks completed', $n),
            NotificationEventType::TODO_UNCHECKED => $n === 1
                ? sprintf('Reopened: %s', $text)
                : sprintf('%d tasks reopened', $n),
            NotificationEventType::TODO_DELETED => $n === 1
                ? sprintf('Deleted: %s', $text)
                : sprintf('%d tasks deleted', $n),
            NotificationEventType::TODO_CREATED => $n === 1
                ? sprintf('New task: %s', $text)
                : sprintf('%d new tasks', $n),
            default => '',
        };
    }

    private function truncate(string $text, int $max = 60): string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return 'Untitled task';
        }
        if (mb_strlen($trimmed) <= $max) {
            return $trimmed;
        }

        return mb_substr($trimmed, 0, $max - 1).'…';
    }

    private function formatBaseId(Dataset $dataset): string
    {
        return \App\Util\BaseIdParser::format($dataset->getBaseId());
    }
}
