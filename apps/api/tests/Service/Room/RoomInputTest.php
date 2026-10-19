<?php

declare(strict_types=1);

namespace App\Tests\Service\Room;

use App\Service\Room\RoomInput;
use PHPUnit\Framework\TestCase;

final class RoomInputTest extends TestCase
{
    public function testPseudoIsCleaned(): void
    {
        self::assertSame('Marie Curie', RoomInput::pseudo("  Marie \t  Curie \n"));
        self::assertSame('Zoé', RoomInput::pseudo('Zoé'));
    }

    public function testControlAndFormatCharactersAreStripped(): void
    {
        // U+202E (inversion du sens d'écriture) et U+200B (espace sans chasse) : usurpation / invisibles.
        self::assertSame('abc', RoomInput::pseudo("a\u{202E}b\u{200B}c\x07"));
    }

    public function testRejectsEmptyOrNonStringOrTooLong(): void
    {
        self::assertNull(RoomInput::pseudo(''));
        self::assertNull(RoomInput::pseudo("  \n "));
        self::assertNull(RoomInput::pseudo(null));
        self::assertNull(RoomInput::pseudo(['x']));
        self::assertNull(RoomInput::pseudo(42));
        self::assertNull(RoomInput::pseudo(str_repeat('a', RoomInput::PSEUDO_MAX + 1)));
        self::assertSame(str_repeat('a', RoomInput::PSEUDO_MAX), RoomInput::pseudo(str_repeat('a', RoomInput::PSEUDO_MAX)));
    }

    public function testLengthIsCountedInCharactersNotBytes(): void
    {
        self::assertSame(str_repeat('é', RoomInput::NAME_MAX), RoomInput::name(str_repeat('é', RoomInput::NAME_MAX)));
        self::assertNull(RoomInput::name(str_repeat('é', RoomInput::NAME_MAX + 1)));
    }
}
