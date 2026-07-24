<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Dataset;
use App\Entity\User;
use App\Util\BaseIdParser;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Publishes private Mercure updates so other devices / members pull sync.
 * Failures are logged only — sync/MCP must not break if the hub is down.
 */
final class DatasetRealtimePublisher
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
        private readonly MercureFeature $mercure,
    ) {
    }

    public static function topicForDataset(Dataset $dataset): string
    {
        return self::topicForBaseId(BaseIdParser::format($dataset->getBaseId()));
    }

    public static function topicForBaseId(string $baseIdFormatted): string
    {
        return 'https://tadaaa.app/datasets/'.$baseIdFormatted;
    }

    public static function topicForUser(User $user): string
    {
        return 'https://tadaaa.app/users/'.$user->getId()->toRfc4122();
    }

    public function publishDatasetChanged(Dataset $dataset): void
    {
        $this->publish(
            self::topicForDataset($dataset),
            [
                'type' => 'dataset_changed',
                'baseId' => BaseIdParser::format($dataset->getBaseId()),
                'datasetUpdatedAt' => $dataset->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            ],
        );
    }

    /**
     * Notify the dataset owner (user topic) and dataset subscribers that someone joined.
     */
    public function publishMemberJoined(Dataset $dataset, User $member, string $role): void
    {
        $payload = [
            'type' => 'member_joined',
            'baseId' => BaseIdParser::format($dataset->getBaseId()),
            'datasetName' => $dataset->getName(),
            'memberEmail' => $member->getEmail(),
            'memberId' => $member->getId()->toRfc4122(),
            'role' => $role,
        ];

        $this->publish(self::topicForDataset($dataset), $payload);
        $this->publish(self::topicForUser($dataset->getOwner()), $payload);
    }

    /**
     * Push a share invite to the invitee's personal Mercure topic (in-app, if online).
     *
     * @param array{token: string, urlPath: string, role: string, expiresAt: string} $invite
     */
    public function publishDatasetInvite(
        User $invitee,
        Dataset $dataset,
        User $inviter,
        array $invite,
    ): void {
        $this->publish(self::topicForUser($invitee), [
            'type' => 'dataset_invite',
            'token' => $invite['token'],
            'urlPath' => $invite['urlPath'],
            'baseId' => BaseIdParser::format($dataset->getBaseId()),
            'datasetName' => $dataset->getName(),
            'role' => $invite['role'],
            'expiresAt' => $invite['expiresAt'],
            'inviterEmail' => $inviter->getEmail(),
            'inviterId' => $inviter->getId()->toRfc4122(),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function publish(string $topic, array $payload): void
    {
        if (!$this->mercure->isEnabled()) {
            return;
        }

        try {
            $body = json_encode($payload, \JSON_THROW_ON_ERROR);
            $this->hub->publish(new Update($topic, $body, true));
        } catch (\Throwable $exception) {
            $this->logger->warning('Mercure publish failed for {topic}: {message}', [
                'topic' => $topic,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
