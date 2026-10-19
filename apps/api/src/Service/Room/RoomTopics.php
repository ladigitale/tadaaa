<?php

declare(strict_types=1);

namespace App\Service\Room;

/**
 * Topics Mercure d'un salon. Quatre topics, des droits séparés :
 *
 * - `data`   : messages des participants (abonnement + publication par les membres actifs) ;
 * - `ctl`    : événements serveur (liste des membres, fermeture) — les membres ne peuvent pas y publier,
 *              donc un participant ne peut pas forger un « kick » ou une liste ;
 * - `host`   : demandes d'entrée, lues par l'hôte seul ;
 * - `member` : message personnel (accepté / refusé / expulsé), lu par ce participant seul.
 *
 * `data`, `ctl` et `host` contiennent la clé secrète du salon ; `member` non, pour qu'un invité
 * encore en attente n'apprenne rien des topics du salon.
 */
final class RoomTopics
{
    private const BASE = 'https://tadaaa.app/rooms/';

    public static function data(string $roomId, string $topicKey): string
    {
        return self::BASE.$roomId.'/'.$topicKey.'/data';
    }

    public static function ctl(string $roomId, string $topicKey): string
    {
        return self::BASE.$roomId.'/'.$topicKey.'/ctl';
    }

    public static function host(string $roomId, string $topicKey): string
    {
        return self::BASE.$roomId.'/'.$topicKey.'/host';
    }

    public static function member(string $roomId, string $memberId): string
    {
        return self::BASE.$roomId.'/members/'.$memberId;
    }
}
