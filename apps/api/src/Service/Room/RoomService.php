<?php

declare(strict_types=1);

namespace App\Service\Room;

use App\Entity\Artifact;
use App\Entity\ArtifactRoom;
use App\Entity\ArtifactRoomAccess;
use App\Entity\ArtifactRoomMember;
use App\Entity\ArtifactRoomMemberStatus;
use App\Repository\ArtifactRoomMemberRepository;
use App\Repository\ArtifactRoomRepository;
use App\Service\MercureFeature;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

/**
 * Salons éphémères d'un artefact : annuaire, règles d'entrée, invités sans compte, jetons Mercure.
 *
 * Plan de contrôle ici (créer, entrer, accepter, expulser, renouveler) ; plan de données sur le hub :
 * les participants publient directement sur le topic `data` avec le jeton délivré, sans repasser par PHP.
 */
final class RoomService
{
    /** Durée de vie d'un jeton Mercure ; le client le renouvelle (et se signale vivant) toutes les ~4 min. */
    public const TOKEN_TTL_SECONDS = 300;
    public const ROOM_TTL = '+6 hours';
    /** Sans renouvellement de jeton depuis ce délai, un participant est considéré parti. */
    public const STALE_AFTER = '-10 minutes';
    public const MAX_ROOMS_PER_ARTIFACT = 50;
    public const MIN_MEMBERS = 2;
    public const MAX_MEMBERS_CAP = 32;
    public const DEFAULT_MAX_MEMBERS = 8;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArtifactRoomRepository $rooms,
        private readonly ArtifactRoomMemberRepository $members,
        private readonly HubInterface $hub,
        private readonly MercureFeature $mercure,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Salons listés de l'artefact.
     *
     * @return list<array<string, mixed>>
     */
    public function listRooms(Artifact $artifact): array
    {
        $this->assertReady();
        $this->purge();

        $rooms = $this->rooms->findListed($artifact);
        $counts = $this->members->countActiveByRoom($rooms);

        $out = [];
        foreach ($rooms as $room) {
            $out[] = $this->serializeRoom($room, $counts[$room->getId()->toRfc4122()] ?? 0);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $body {name, pseudo, access?: open|approval, code?: string|true, listed?: bool, max?: int}
     *
     * @return array<string, mixed>
     */
    public function createRoom(Artifact $artifact, array $body): array
    {
        $this->assertReady();
        $this->purge();

        $name = RoomInput::name($body['name'] ?? null)
            ?? throw new BadRequestHttpException(sprintf('Nom de salon requis (1 à %d caractères).', RoomInput::NAME_MAX));
        $pseudo = RoomInput::pseudo($body['pseudo'] ?? null)
            ?? throw new BadRequestHttpException(sprintf('Pseudo requis (1 à %d caractères).', RoomInput::PSEUDO_MAX));

        $access = ArtifactRoomAccess::tryFrom(\is_string($body['access'] ?? null) ? $body['access'] : 'open')
            ?? throw new BadRequestHttpException('Accès inconnu (open ou approval).');

        $code = $this->resolveCode($artifact, $body['code'] ?? null);
        $listed = \array_key_exists('listed', $body) ? (bool) $body['listed'] : RoomAccessPolicy::defaultListed($code);

        $max = self::DEFAULT_MAX_MEMBERS;
        if (isset($body['max'])) {
            if (!\is_int($body['max']) || $body['max'] < self::MIN_MEMBERS || $body['max'] > self::MAX_MEMBERS_CAP) {
                throw new BadRequestHttpException(sprintf('max : entier entre %d et %d.', self::MIN_MEMBERS, self::MAX_MEMBERS_CAP));
            }
            $max = $body['max'];
        }

        if ($this->rooms->countForArtifact($artifact) >= self::MAX_ROOMS_PER_ARTIFACT) {
            throw new ConflictHttpException('Trop de salons ouverts sur cet artefact, réessayez plus tard.');
        }

        $room = new ArtifactRoom($artifact, $name, $access, $code, $listed, $max, new \DateTimeImmutable(self::ROOM_TTL));
        $key = self::newMemberKey();
        $host = new ArtifactRoomMember($room, $pseudo, true, ArtifactRoomMemberStatus::Active, hash('sha256', $key));
        $this->em->persist($room);
        $this->em->persist($host);
        $this->em->flush();

        return [
            'room' => $this->serializeRoom($room, 1) + ['code' => $code],
            'member' => $this->serializeMember($host) + ['key' => $key],
            'mercure' => $this->credentials($room, $host),
            'members' => $this->roster($room),
        ];
    }

    /**
     * Entrer dans un salon, par id ou par code.
     *
     * @param array<string, mixed> $body {pseudo, roomId?: uuid, code?: string}
     *
     * @return array<string, mixed>
     */
    public function join(Artifact $artifact, array $body): array
    {
        $this->assertReady();
        $this->purge();

        $pseudo = RoomInput::pseudo($body['pseudo'] ?? null)
            ?? throw new BadRequestHttpException(sprintf('Pseudo requis (1 à %d caractères).', RoomInput::PSEUDO_MAX));

        $givenCode = RoomCode::parse(\is_string($body['code'] ?? null) ? $body['code'] : null);
        $room = null;
        if (isset($body['roomId'])) {
            $id = \is_string($body['roomId']) && Uuid::isValid($body['roomId']) ? Uuid::fromString($body['roomId']) : null;
            $room = $id !== null ? $this->rooms->findOneForArtifact($artifact, $id) : null;
        } elseif ($givenCode !== null) {
            $room = $this->rooms->findOneByCode($artifact, $givenCode);
        } else {
            throw new BadRequestHttpException('roomId ou code requis.');
        }
        if ($room === null) {
            throw new NotFoundHttpException('Salon introuvable.');
        }

        $decision = RoomAccessPolicy::decideJoin(
            $room->getAccess(),
            $room->getCode(),
            $givenCode,
            $this->members->countActive($room),
            $this->members->countPending($room),
            $room->getMaxMembers(),
        );
        match ($decision) {
            JoinDecision::WrongCode => throw new AccessDeniedHttpException('Code incorrect.'),
            JoinDecision::Full => throw new ConflictHttpException('Salon complet.'),
            JoinDecision::TooManyPending => throw new TooManyRequestsHttpException(30, 'Trop de demandes en attente, réessayez dans un instant.'),
            default => null,
        };

        foreach ($this->activeAndPending($room) as $existing) {
            if (mb_strtolower($existing->getPseudo(), 'UTF-8') === mb_strtolower($pseudo, 'UTF-8')) {
                throw new ConflictHttpException('Ce pseudo est déjà pris dans ce salon.');
            }
        }

        $pending = $decision === JoinDecision::Pending;
        $key = self::newMemberKey();
        $member = new ArtifactRoomMember(
            $room,
            $pseudo,
            false,
            $pending ? ArtifactRoomMemberStatus::Pending : ArtifactRoomMemberStatus::Active,
            hash('sha256', $key),
        );
        $this->em->persist($member);
        $this->em->flush();

        if ($pending) {
            $this->publish(
                RoomTopics::host($room->getId()->toRfc4122(), $room->getTopicKey()),
                ['type' => 'join-request', 'member' => $this->serializeMember($member)],
            );
        } else {
            $this->publishRoster($room);
        }

        return [
            'status' => $member->getStatus()->value,
            'room' => $this->serializeRoom($room, $this->members->countActive($room)),
            'member' => $this->serializeMember($member) + ['key' => $key],
            'mercure' => $this->credentials($room, $member),
            'members' => $pending ? [] : $this->roster($room),
        ];
    }

    /**
     * L'hôte accepte ou refuse une demande d'entrée.
     *
     * @return array<string, mixed>
     */
    public function decide(Artifact $artifact, string $roomId, string $hostMemberId, string $key, string $targetId, bool $accept): array
    {
        [$room, $host] = $this->authenticate($artifact, $roomId, $hostMemberId, $key);
        $this->assertHost($host);

        $target = $this->targetMember($room, $targetId);
        if ($target->getStatus() !== ArtifactRoomMemberStatus::Pending) {
            throw new ConflictHttpException('Cette demande n\'est plus en attente.');
        }

        $roomId = $room->getId()->toRfc4122();
        if ($accept) {
            if ($this->members->countActive($room) >= $room->getMaxMembers()) {
                throw new ConflictHttpException('Salon complet.');
            }
            $target->activate()->touch();
            $this->em->flush();
            $this->publish(RoomTopics::member($roomId, $target->getId()->toRfc4122()), ['type' => 'approved']);
            $this->publishRoster($room);

            return ['status' => 'approved', 'members' => $this->roster($room)];
        }

        $targetUuid = $target->getId()->toRfc4122();
        $this->em->remove($target);
        $this->em->flush();
        $this->publish(RoomTopics::member($roomId, $targetUuid), ['type' => 'rejected']);

        return ['status' => 'rejected'];
    }

    /**
     * L'hôte expulse un participant.
     *
     * @return array<string, mixed>
     */
    public function kick(Artifact $artifact, string $roomId, string $hostMemberId, string $key, string $targetId): array
    {
        [$room, $host] = $this->authenticate($artifact, $roomId, $hostMemberId, $key);
        $this->assertHost($host);

        $target = $this->targetMember($room, $targetId);
        if ($target->isHost()) {
            throw new BadRequestHttpException('L\'hôte ne peut pas s\'expulser (fermez le salon).');
        }

        $roomUuid = $room->getId()->toRfc4122();
        $targetUuid = $target->getId()->toRfc4122();
        $this->em->remove($target);
        $this->em->flush();
        // Jeton non révocable côté hub : il expire d'ici TOKEN_TTL_SECONDS ; les clients ignorent cet émetteur d'ici là.
        $this->publish(RoomTopics::member($roomUuid, $targetUuid), ['type' => 'kicked']);
        $this->publish(RoomTopics::ctl($roomUuid, $room->getTopicKey()), ['type' => 'kicked', 'member' => $targetUuid]);
        $this->publishRoster($room);

        return ['status' => 'kicked', 'members' => $this->roster($room)];
    }

    /**
     * Renouvelle le jeton (et sert de signe de vie). Un invité en attente reçoit un jeton limité
     * à son topic personnel, puis le jeton complet une fois accepté.
     *
     * @return array<string, mixed>
     */
    public function refreshToken(Artifact $artifact, string $roomId, string $memberId, string $key): array
    {
        $this->assertReady();
        [$room, $member] = $this->authenticate($artifact, $roomId, $memberId, $key);

        $member->touch();
        if ($member->isHost()) {
            $room->extendUntil(new \DateTimeImmutable(self::ROOM_TTL));
        }
        $this->em->flush();

        return [
            'status' => $member->getStatus()->value,
            'room' => $this->serializeRoom($room, $this->members->countActive($room)),
            'mercure' => $this->credentials($room, $member),
            'members' => $member->isActive() ? $this->roster($room) : [],
        ];
    }

    /**
     * Quitter le salon ; si c'est l'hôte, le salon est fermé pour tous.
     *
     * @return array<string, mixed>
     */
    public function leave(Artifact $artifact, string $roomId, string $memberId, string $key): array
    {
        [$room, $member] = $this->authenticate($artifact, $roomId, $memberId, $key);
        $roomUuid = $room->getId()->toRfc4122();

        if ($member->isHost()) {
            $ctl = RoomTopics::ctl($roomUuid, $room->getTopicKey());
            $this->em->remove($room); // membres supprimés en cascade (clé étrangère)
            $this->em->flush();
            // Le participant chargé pointe vers un salon supprimé : on le détache.
            $this->em->detach($member);
            $this->publish($ctl, ['type' => 'closed']);

            return ['status' => 'closed'];
        }

        $this->em->remove($member);
        $this->em->flush();
        $this->publishRoster($room);

        return ['status' => 'left'];
    }

    // -- internes ---------------------------------------------------------------------------------

    private function assertReady(): void
    {
        if (!$this->mercure->isEnabled()) {
            throw new ServiceUnavailableHttpException(null, 'Salons indisponibles : Mercure désactivé sur cet environnement.');
        }
        if ($this->hub->getFactory() === null) {
            throw new ServiceUnavailableHttpException(null, 'Salons indisponibles : fabrique de jetons Mercure absente.');
        }
    }

    private function purge(): void
    {
        $this->rooms->purge(new \DateTimeImmutable(), new \DateTimeImmutable(self::STALE_AFTER));
    }

    /** @return array{0: ArtifactRoom, 1: ArtifactRoomMember} */
    private function authenticate(Artifact $artifact, string $roomId, string $memberId, string $key): array
    {
        $this->assertReady();
        $this->purge();

        $room = Uuid::isValid($roomId) ? $this->rooms->findOneForArtifact($artifact, Uuid::fromString($roomId)) : null;
        if ($room === null) {
            throw new NotFoundHttpException('Salon introuvable (fermé ou expiré).');
        }
        $member = Uuid::isValid($memberId) ? $this->members->findOneInRoom($room, Uuid::fromString($memberId)) : null;
        if ($member === null || !$member->matchesKey($key)) {
            throw new AccessDeniedHttpException('Participant ou clé invalide.');
        }

        return [$room, $member];
    }

    private function assertHost(ArtifactRoomMember $member): void
    {
        if (!$member->isHost()) {
            throw new AccessDeniedHttpException('Réservé à l\'hôte du salon.');
        }
    }

    private function targetMember(ArtifactRoom $room, string $targetId): ArtifactRoomMember
    {
        $target = Uuid::isValid($targetId) ? $this->members->findOneInRoom($room, Uuid::fromString($targetId)) : null;
        if ($target === null) {
            throw new NotFoundHttpException('Participant introuvable.');
        }

        return $target;
    }

    /** @return list<ArtifactRoomMember> */
    private function activeAndPending(ArtifactRoom $room): array
    {
        /** @var list<ArtifactRoomMember> $all */
        $all = $this->members->findBy(['room' => $room]);

        return $all;
    }

    private function resolveCode(Artifact $artifact, mixed $requested): ?string
    {
        if ($requested === null || $requested === false || $requested === '') {
            return null;
        }

        if ($requested === true) {
            for ($i = 0; $i < 10; ++$i) {
                $candidate = RoomCode::generate();
                if ($this->rooms->findOneByCode($artifact, $candidate) === null) {
                    return $candidate;
                }
            }
            throw new ConflictHttpException('Impossible de générer un code libre, réessayez.');
        }

        $code = RoomCode::parse(\is_string($requested) ? $requested : null);
        if ($code === null) {
            throw new BadRequestHttpException(sprintf('Code : %d à %d lettres ou chiffres.', RoomCode::MIN_LENGTH, RoomCode::MAX_LENGTH));
        }
        if ($this->rooms->findOneByCode($artifact, $code) !== null) {
            throw new ConflictHttpException('Ce code est déjà utilisé par un autre salon.');
        }

        return $code;
    }

    private static function newMemberKey(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> */
    private function serializeRoom(ArtifactRoom $room, int $activeCount): array
    {
        return [
            'id' => $room->getId()->toRfc4122(),
            'name' => $room->getName(),
            'access' => $room->getAccess()->value,
            'hasCode' => $room->getCode() !== null,
            'members' => $activeCount,
            'max' => $room->getMaxMembers(),
            'full' => $activeCount >= $room->getMaxMembers(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeMember(ArtifactRoomMember $member): array
    {
        return [
            'id' => $member->getId()->toRfc4122(),
            'pseudo' => $member->getPseudo(),
            'host' => $member->isHost(),
            'status' => $member->getStatus()->value,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function roster(ArtifactRoom $room): array
    {
        return array_map(fn (ArtifactRoomMember $m): array => [
            'id' => $m->getId()->toRfc4122(),
            'pseudo' => $m->getPseudo(),
            'host' => $m->isHost(),
        ], $this->members->findActive($room));
    }

    private function publishRoster(ArtifactRoom $room): void
    {
        $this->publish(
            RoomTopics::ctl($room->getId()->toRfc4122(), $room->getTopicKey()),
            ['type' => 'roster', 'members' => $this->roster($room)],
        );
    }

    /**
     * Identifiants Mercure d'un participant (droits décidés dans {@see RoomCredentials}).
     *
     * @return array<string, mixed>
     */
    private function credentials(ArtifactRoom $room, ArtifactRoomMember $member): array
    {
        $factory = $this->hub->getFactory();
        if ($factory === null) {
            throw new ServiceUnavailableHttpException(null, 'Salons indisponibles : fabrique de jetons Mercure absente.');
        }

        return RoomCredentials::build($factory, $this->hub->getPublicUrl(), $room, $member);
    }

    /**
     * Publication serveur (événements de contrôle). Un échec est journalisé, jamais remonté :
     * l'action en base est déjà faite et les clients se resynchronisent au renouvellement du jeton.
     *
     * @param array<string, mixed> $payload
     */
    private function publish(string $topic, array $payload): void
    {
        try {
            $this->hub->publish(new Update($topic, json_encode($payload, \JSON_THROW_ON_ERROR), true));
        } catch (\Throwable $exception) {
            $this->logger->warning('Room Mercure publish failed for {topic}: {message}', [
                'topic' => $topic,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
