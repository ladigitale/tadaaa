<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Webhook\StandardWebhookSignature;
use PHPUnit\Framework\TestCase;

final class StandardWebhookSignatureTest extends TestCase
{
    /** Vecteur de test de la spécification Standard Webhooks. */
    public function testReferenceVector(): void
    {
        $key = base64_decode('MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw', true);
        self::assertSame(
            'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=',
            StandardWebhookSignature::sign((string) $key, 'msg_p5jXN8AQM9LWM0D4loKWxJek', '1614265330', '{"test": 2432232314}'),
        );
        self::assertSame('whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw', StandardWebhookSignature::secretFor((string) $key));
    }

    public function testHeaders(): void
    {
        $h = StandardWebhookSignature::headers('secret', 'evt_1', '1700000000', '{}');
        self::assertSame('evt_1', $h['webhook-id']);
        self::assertSame('1700000000', $h['webhook-timestamp']);
        self::assertStringStartsWith('v1,', $h['webhook-signature']);
    }
}
