<?php

declare(strict_types=1);

namespace App\Tests\Service\Room;

use App\Service\Room\RoomCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RoomCodeTest extends TestCase
{
    #[DataProvider('normalizations')]
    public function testNormalize(string $input, string $expected): void
    {
        self::assertSame($expected, RoomCode::normalize($input));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function normalizations(): iterable
    {
        yield 'already clean' => ['coetdju', 'coetdju'];
        yield 'case and spaces' => ['  CoEt Dju ', 'coetdju'];
        yield 'dashes' => ['coet-dju', 'coetdju'];
        yield 'accents' => ['Été-Çà', 'eteca'];
        yield 'ligature' => ['cœur', 'coeur'];
        yield 'digits kept' => ['Salle 12', 'salle12'];
        yield 'emoji dropped' => ['go🎲', 'go'];
    }

    public function testParseAcceptsRangeOnly(): void
    {
        self::assertNull(RoomCode::parse(null));
        self::assertNull(RoomCode::parse('ab'));
        self::assertSame('abc', RoomCode::parse('abc'));
        self::assertSame(str_repeat('a', RoomCode::MAX_LENGTH), RoomCode::parse(str_repeat('a', RoomCode::MAX_LENGTH)));
        self::assertNull(RoomCode::parse(str_repeat('a', RoomCode::MAX_LENGTH + 1)));
        self::assertNull(RoomCode::parse('---'));
    }

    public function testGeneratedCodesAreSpeakableAndParseable(): void
    {
        for ($i = 0; $i < 200; ++$i) {
            $code = RoomCode::generate();
            self::assertMatchesRegularExpression('/^([bdfgklmnprstvz][aeiou]){3}$/', $code);
            self::assertSame($code, RoomCode::parse($code));
        }
    }
}
