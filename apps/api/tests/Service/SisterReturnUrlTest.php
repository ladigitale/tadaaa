<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SisterReturnUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SisterReturnUrlTest extends TestCase
{
    private const APP = 'https://app.tadaaa.space';

    #[DataProvider('accepted')]
    public function testAccepted(string $url): void
    {
        self::assertSame($url, SisterReturnUrl::sanitize($url, self::APP));
    }

    public static function accepted(): iterable
    {
        yield 'app sœur' => ['https://artifacts.tadaaa.space/cloud?next=%2Fdemarrer'];
        yield 'domaine parent' => ['https://tadaaa.space/x'];
        yield 'même hôte' => ['https://app.tadaaa.space/x'];
        yield 'localhost' => ['http://localhost:3300/cloud'];
    }

    #[DataProvider('rejected')]
    public function testRejected(?string $url): void
    {
        self::assertNull(SisterReturnUrl::sanitize($url, self::APP));
    }

    public static function rejected(): iterable
    {
        yield 'vide' => [''];
        yield 'null' => [null];
        yield 'autre site' => ['https://evil.example/cloud'];
        yield 'suffixe trompeur' => ['https://eviltadaaa.space/cloud'];
        yield 'hôte trompeur' => ['https://tadaaa.space.evil.example/'];
        yield 'http hors localhost' => ['http://artifacts.tadaaa.space/cloud'];
        yield 'identifiants' => ['https://artifacts.tadaaa.space@evil.example/'];
        yield 'user info' => ['https://user:pw@artifacts.tadaaa.space/'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'relatif' => ['/cloud'];
        yield 'trop long' => ['https://artifacts.tadaaa.space/'.str_repeat('a', 600)];
    }
}
