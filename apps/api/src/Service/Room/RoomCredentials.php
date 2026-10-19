<?php

declare(strict_types=1);

namespace App\Service\Room;

use App\Entity\ArtifactRoom;
use App\Entity\ArtifactRoomMember;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;

/**
 * Identifiants Mercure d'un participant — c'est ici que se décident ses droits :
 *
 * - en attente : lecture de son topic personnel seulement ; le claim de publication est ABSENT
 *   (`null`, pas une liste vide : « présent mais vide » peut encore autoriser des updates publics) ;
 * - actif : lecture de `data` + `ctl` + son topic personnel, publication sur `data` uniquement
 *   (donc pas sur `ctl` : impossible de forger une liste de membres, un « kick » ou une fermeture) ;
 * - hôte : en plus, lecture de `host` (demandes d'entrée).
 */
final class RoomCredentials
{
    /**
     * @return array{hubUrl: string, token: string, expiresIn: int, topics: array<string, string>, subscribe: list<string>, publish: list<string>}
     */
    public static function build(
        TokenFactoryInterface $factory,
        string $hubUrl,
        ArtifactRoom $room,
        ArtifactRoomMember $member,
        int $ttlSeconds = RoomService::TOKEN_TTL_SECONDS,
    ): array {
        $roomId = $room->getId()->toRfc4122();
        $topics = ['member' => RoomTopics::member($roomId, $member->getId()->toRfc4122())];
        $subscribe = [$topics['member']];
        $publish = null;

        if ($member->isActive()) {
            $topics['data'] = RoomTopics::data($roomId, $room->getTopicKey());
            $topics['ctl'] = RoomTopics::ctl($roomId, $room->getTopicKey());
            $subscribe[] = $topics['data'];
            $subscribe[] = $topics['ctl'];
            $publish = [$topics['data']];
            if ($member->isHost()) {
                $topics['host'] = RoomTopics::host($roomId, $room->getTopicKey());
                $subscribe[] = $topics['host'];
            }
        }

        $token = $factory->create(
            $subscribe,
            $publish,
            ['exp' => new \DateTimeImmutable('+'.$ttlSeconds.' seconds')],
        );

        return [
            'hubUrl' => $hubUrl,
            'token' => $token,
            'expiresIn' => $ttlSeconds,
            'topics' => $topics,
            'subscribe' => $subscribe,
            'publish' => $publish ?? [],
        ];
    }
}
