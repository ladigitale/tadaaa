<?php

declare(strict_types=1);

namespace App\Service\Room;

/** Nettoyage des textes saisis par des invités anonymes (pseudo, nom de salon). */
final class RoomInput
{
    public const PSEUDO_MAX = 32;
    public const NAME_MAX = 40;

    /** @return string|null texte nettoyé (espaces écrasés, caractères de contrôle retirés), null si vide ou trop long */
    public static function text(mixed $value, int $maxLength): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $clean = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $value);
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));
        if ($clean === '' || mb_strlen($clean, 'UTF-8') > $maxLength) {
            return null;
        }

        return $clean;
    }

    public static function pseudo(mixed $value): ?string
    {
        return self::text($value, self::PSEUDO_MAX);
    }

    public static function name(mixed $value): ?string
    {
        return self::text($value, self::NAME_MAX);
    }
}
