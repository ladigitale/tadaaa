<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ArtifactFileFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class ArtifactFileFetcherTest extends TestCase
{
    private const URL = 'https://raw.githubusercontent.com/u/r/main/plateau.glb';

    private function fetcher(string $body = "glTF\x02\x00", int $status = 200, string $hosts = ''): ArtifactFileFetcher
    {
        return new ArtifactFileFetcher(new MockHttpClient(new MockResponse($body, ['http_code' => $status])), $hosts);
    }

    public function testBase64EncodesReceivedBytes(): void
    {
        self::assertSame(base64_encode("glTF\x02\x00"), $this->fetcher()->fetch(self::URL, 'base64'));
    }

    public function testTextIsReturnedAsIs(): void
    {
        self::assertSame('abc', $this->fetcher('abc')->fetch(self::URL));
    }

    public function testSha256IsChecked(): void
    {
        $ok = hash('sha256', 'abc');
        self::assertSame('abc', $this->fetcher('abc')->fetch(self::URL, 'text', $ok));
        $this->expectException(BadRequestHttpException::class);
        $this->fetcher('abc')->fetch(self::URL, 'text', str_repeat('0', 64));
    }

    /** @dataProvider badUrls */
    public function testRejectsUnsafeUrls(string $url): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->fetcher()->fetch($url);
    }

    /** @return iterable<string, array{string}> */
    public static function badUrls(): iterable
    {
        yield 'http' => ['http://raw.githubusercontent.com/a'];
        yield 'host' => ['https://evil.example/a'];
        yield 'ip' => ['https://127.0.0.1/a'];
        yield 'userinfo' => ['https://u:p@raw.githubusercontent.com/a'];
        yield 'port' => ['https://raw.githubusercontent.com:8443/a'];
        yield 'suffix trick' => ['https://raw.githubusercontent.com.evil.example/a'];
    }

    public function testCustomHostList(): void
    {
        $f = $this->fetcher('x', 200, 'files.example.org');
        self::assertSame('x', $f->fetch('https://files.example.org/a'));
        $this->expectException(BadRequestHttpException::class);
        $f->fetch(self::URL);
    }

    public function testHttpErrorAndSizeLimit(): void
    {
        try {
            $this->fetcher('x', 404)->fetch(self::URL);
            self::fail('404 attendu en erreur');
        } catch (BadRequestHttpException) {
            self::assertTrue(true);
        }
        $this->expectException(BadRequestHttpException::class);
        $this->fetcher(str_repeat('a', 70000))->fetch(self::URL);
    }
}
