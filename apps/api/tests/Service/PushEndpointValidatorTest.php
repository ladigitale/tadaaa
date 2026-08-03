<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PushEndpointValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class PushEndpointValidatorTest extends TestCase
{
    private PushEndpointValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PushEndpointValidator();
    }

    public function testAcceptsFcmEndpoint(): void
    {
        $url = 'https://fcm.googleapis.com/fcm/send/abc123';
        self::assertSame($url, $this->validator->assertValid($url));
    }

    public function testAcceptsMozillaEndpoint(): void
    {
        $url = 'https://updates.push.services.mozilla.com/wpush/v2/xxx';
        self::assertSame($url, $this->validator->assertValid($url));
    }

    public function testRejectsPrivateIp(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->validator->assertValid('https://169.254.169.254/latest/meta-data/');
    }

    public function testRejectsLocalhost(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->validator->assertValid('https://localhost/push');
    }

    public function testRejectsArbitraryHttpsHost(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->validator->assertValid('https://evil.example/hook');
    }

    public function testRejectsHttp(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->validator->assertValid('http://fcm.googleapis.com/fcm/send/x');
    }

    public function testRejectsUserinfo(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->validator->assertValid('https://user:pass@fcm.googleapis.com/x');
    }
}
