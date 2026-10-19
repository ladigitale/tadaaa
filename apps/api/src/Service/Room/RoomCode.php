<?php

declare(strict_types=1);

namespace App\Service\Room;

/**
 * Codes de salon : courts, faciles à dicter ou à taper sur mobile (« bakodu »).
 * Un code est un point de rendez-vous, pas un mot de passe : aucune promesse de sécurité.
 */
final class RoomCode
{
    public const MIN_LENGTH = 3;
    public const MAX_LENGTH = 24;

    private const CONSONANTS = 'bdfgklmnprstvz';
    private const VOWELS = 'aeiou';
    private const ACCENTS = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae',
    ];

    /** Minuscules, sans accents, uniquement a-z0-9 (espaces, tirets et ponctuation ignorés). */
    public static function normalize(string $input): string
    {
        $lower = mb_strtolower(trim($input), 'UTF-8');
        $ascii = strtr($lower, self::ACCENTS);

        return (string) preg_replace('/[^a-z0-9]/', '', $ascii);
    }

    /** Code normalisé valide, ou null s'il est trop court / trop long. */
    public static function parse(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }
        $code = self::normalize($input);
        $length = \strlen($code);

        return $length >= self::MIN_LENGTH && $length <= self::MAX_LENGTH ? $code : null;
    }

    /** Trois syllabes consonne+voyelle (~340 000 combinaisons) : prononçable, sans l/1/O ambigus. */
    public static function generate(int $syllables = 3): string
    {
        $code = '';
        for ($i = 0; $i < $syllables; ++$i) {
            $code .= self::CONSONANTS[random_int(0, \strlen(self::CONSONANTS) - 1)];
            $code .= self::VOWELS[random_int(0, \strlen(self::VOWELS) - 1)];
        }

        return $code;
    }
}
