<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ArtifactCollection;
use App\Entity\ArtifactRecord;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Collections en direct : chaque record créé, modifié ou retiré est poussé sur le hub Mercure,
 * pour que les viewers n'aient pas à relire la collection (relecture `refresh` bornée à 5 s,
 * 120 lectures par minute et par IP : une salle de téléphones derrière une même IP s'y heurte).
 *
 * Topic privé par collection, au chemin secret (HMAC de l'id) : on ne s'y abonne qu'avec le JWT
 * remis par {@see credentials()}, après le même contrôle d'accès que la lecture publique.
 *
 * Messages : {"collection": "<nom>", "upsert": [{id, data, createdAt, updatedAt}], "remove": [id]}.
 */
final class ArtifactCollectionLive
{
    public const SUBSCRIBER_TTL = 3600;
    private const TOPIC_BASE = 'https://tadaaa.app/collections/';

    public function __construct(
        private readonly HubInterface $hub,
        private readonly MercureFeature $mercure,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret = '',
    ) {
    }

    public function topic(ArtifactCollection $collection): string
    {
        $id = $collection->getId()->toRfc4122();
        $mac = hash_hmac('sha256', 'collection-live:'.$id, $this->secret, true);

        return self::TOPIC_BASE.$id.'/'.rtrim(strtr(base64_encode(substr($mac, 0, 18)), '+/', '-_'), '=');
    }

    /** @return array{hubUrl: string, token: string, topic: string, expiresIn: int}|null null sans hub */
    public function credentials(ArtifactCollection $collection): ?array
    {
        if (!$this->mercure->isEnabled() || $this->hub->getFactory() === null) {
            return null;
        }
        $topic = $this->topic($collection);
        $token = $this->hub->getFactory()->create(
            [$topic],
            null,
            ['exp' => new \DateTimeImmutable('+'.self::SUBSCRIBER_TTL.' seconds')],
        );

        return ['hubUrl' => $this->hub->getPublicUrl(), 'token' => $token, 'topic' => $topic, 'expiresIn' => self::SUBSCRIBER_TTL];
    }

    /**
     * @param list<ArtifactRecord> $upserted
     * @param list<string>         $removedIds
     */
    public function publish(ArtifactCollection $collection, array $upserted = [], array $removedIds = []): void
    {
        if (($upserted === [] && $removedIds === []) || !$this->mercure->isEnabled()) {
            return;
        }
        $data = [
            'collection' => $collection->getName(),
            'upsert' => array_map(static fn (ArtifactRecord $r): array => self::row($r), $upserted),
            'remove' => array_values($removedIds),
        ];
        try {
            $this->hub->publish(new Update(
                $this->topic($collection),
                (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                true,
                null,
                'records',
            ));
        } catch (\Throwable $e) {
            // Les viewers gardent une relecture de secours.
            $this->logger->warning('Collection en direct : publication Mercure impossible', ['collection' => $collection->getId()->toRfc4122(), 'error' => $e->getMessage()]);
        }
    }

    /** Collection vidée (purge) : les viewers relisent tout. */
    public function publishReset(ArtifactCollection $collection): void
    {
        if (!$this->mercure->isEnabled()) {
            return;
        }
        try {
            $this->hub->publish(new Update(
                $this->topic($collection),
                (string) json_encode(['collection' => $collection->getName(), 'reset' => true]),
                true,
                null,
                'records',
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('Collection en direct : publication Mercure impossible', ['collection' => $collection->getId()->toRfc4122(), 'error' => $e->getMessage()]);
        }
    }

    /** @return array{id: string, data: array<string, mixed>, createdAt: string, updatedAt: string} */
    public static function row(ArtifactRecord $r): array
    {
        return [
            'id' => $r->getId()->toRfc4122(),
            'data' => $r->getData(),
            'createdAt' => $r->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $r->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
