<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Dataset;
use App\Entity\DatasetMember;
use App\Entity\PushSubscription;
use App\Entity\Tag;
use App\Entity\Todo;
use App\Entity\User;
use App\Repository\DatasetMemberRepository;
use App\Repository\PushSubscriptionRepository;
use App\Repository\TagRepository;
use App\Repository\TodoRepository;
use App\Util\BaseIdParser;
use Doctrine\ORM\EntityManagerInterface;

/**
 * GDPR portability pack — no secrets (password hash, JWT, signing secrets, push keys).
 */
final class UserDataExport
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TodoRepository $todos,
        private readonly TagRepository $tags,
        private readonly DatasetMemberRepository $members,
        private readonly PushSubscriptionRepository $pushSubscriptions,
        private readonly WebhookService $webhooks,
        private readonly AccessTokenService $accessTokens,
        private readonly EmbedService $embeds,
        private readonly StorageQuota $storageQuota,
        private readonly BandwidthQuota $bandwidthQuota,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        $ownedDatasets = [];
        foreach ($user->getDatasets() as $dataset) {
            $ownedDatasets[] = $this->serializeOwnedDataset($dataset);
        }

        $memberships = $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(DatasetMember::class, 'm')
            ->andWhere('m.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $membershipOut = [];
        /** @var list<DatasetMember> $memberships */
        foreach ($memberships as $membership) {
            $ds = $membership->getDataset();
            $membershipOut[] = [
                'datasetId' => $ds->getId()->toRfc4122(),
                'datasetName' => $ds->getName(),
                'role' => $membership->getRole()->value,
                'joinedAt' => $membership->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        $pushOut = [];
        foreach ($this->pushSubscriptions->findActiveForUser($user) as $sub) {
            $pushOut[] = $this->serializePush($sub);
        }

        $tokens = [];
        foreach ($this->accessTokens->listForUser($user) as $token) {
            $tokens[] = [
                'id' => $token->getId()->toRfc4122(),
                'name' => $token->getName(),
                'tokenPrefix' => $token->getTokenPrefix(),
                'createdAt' => $token->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'lastUsedAt' => $token->getLastUsedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        return [
            'exportedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'formatVersion' => 1,
            'profile' => [
                'id' => $user->getId()->toRfc4122(),
                'email' => $user->getEmail(),
                'status' => $user->getStatus()->value,
                'roles' => $user->getRoles(),
                'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'emailVerifiedAt' => $user->getEmailVerifiedAt()?->format(\DateTimeInterface::ATOM),
                'termsAcceptedAt' => $user->getTermsAcceptedAt()?->format(\DateTimeInterface::ATOM),
                'linkDetectors' => $user->getLinkDetectors(),
                'notificationPrefs' => $user->getNotificationPrefs(),
                'activeDatasetId' => $user->getActiveDataset()?->getId()->toRfc4122(),
            ],
            'quotas' => [
                'storage' => $this->storageQuota->report($user),
                'bandwidth' => $this->bandwidthQuota->report($user),
            ],
            'ownedDatasets' => $ownedDatasets,
            'memberships' => $membershipOut,
            'webhooks' => array_map(
                $this->webhooks->serialize(...),
                $this->webhooks->listForUser($user),
            ),
            'accessTokens' => $tokens,
            'embedKeys' => array_map(
                $this->embeds->serialize(...),
                $this->embeds->listForUser($user),
            ),
            'pushSubscriptions' => $pushOut,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOwnedDataset(Dataset $dataset): array
    {
        $memberRows = [];
        foreach ($this->members->findAllForDataset($dataset) as $member) {
            $memberRows[] = [
                'userId' => $member->getUser()->getId()->toRfc4122(),
                'email' => $member->getUser()->getEmail(),
                'role' => $member->getRole()->value,
                'joinedAt' => $member->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        /** @var list<Todo> $todos */
        $todos = $this->todos->findBy(['dataset' => $dataset]);
        /** @var list<Tag> $tags */
        $tags = $this->tags->findBy(['dataset' => $dataset]);

        return [
            'id' => $dataset->getId()->toRfc4122(),
            'baseId' => BaseIdParser::format($dataset->getBaseId()),
            'name' => $dataset->getName(),
            'updatedAt' => $dataset->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'members' => $memberRows,
            'todos' => array_map(static fn (Todo $t) => $t->toSyncArray(), $todos),
            'tags' => array_map(static fn (Tag $t) => $t->toSyncArray(), $tags),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePush(PushSubscription $sub): array
    {
        $endpoint = $sub->getEndpoint();
        $host = parse_url($endpoint, \PHP_URL_HOST);

        return [
            'id' => $sub->getId()->toRfc4122(),
            'endpointHost' => is_string($host) && $host !== '' ? $host : 'unknown',
            'userAgent' => $sub->getUserAgent(),
            'createdAt' => $sub->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'lastSeenAt' => $sub->getLastSeenAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
