<?php

declare(strict_types=1);

namespace App\Service\Room;

use App\Entity\ArtifactRoomAccess;

/**
 * Règle d'entrée d'un salon, sans base ni réseau (testable seule).
 *
 * Ordre : code (s'il y en a un) → salon plein → file d'attente / entrée directe.
 */
final class RoomAccessPolicy
{
    public const MAX_PENDING = 10;

    public static function decideJoin(
        ArtifactRoomAccess $access,
        ?string $roomCode,
        ?string $givenCode,
        int $activeCount,
        int $pendingCount,
        int $maxMembers,
    ): JoinDecision {
        if ($roomCode !== null) {
            $given = RoomCode::normalize($givenCode ?? '');
            if ($given === '' || !hash_equals($roomCode, $given)) {
                return JoinDecision::WrongCode;
            }
        }

        if ($activeCount >= $maxMembers) {
            return JoinDecision::Full;
        }

        if ($access === ArtifactRoomAccess::Approval) {
            return $pendingCount >= self::MAX_PENDING ? JoinDecision::TooManyPending : JoinDecision::Pending;
        }

        return JoinDecision::Admit;
    }

    /** Un salon listé sans code est ouvert à tous ; avec un code, il se partage de vive voix → non listé par défaut. */
    public static function defaultListed(?string $code): bool
    {
        return $code === null;
    }
}
