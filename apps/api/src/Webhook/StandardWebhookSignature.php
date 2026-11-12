<?php

declare(strict_types=1);

namespace App\Webhook;

/**
 * Signature au format Standard Webhooks (https://www.standardwebhooks.com/) : en-têtes
 * `webhook-id`, `webhook-timestamp`, `webhook-signature: v1,<base64 HMAC-SHA256>` calculée sur
 * `<id>.<timestamp>.<corps>`. La clé est le secret du point de terminaison ; les bibliothèques
 * Standard Webhooks l'attendent sous la forme `whsec_<base64(secret)>` ({@see self::secretFor()}).
 * Envoyée en plus de l'en-tête historique X-Tadaaa-Signature.
 */
final class StandardWebhookSignature
{
    /** @return array{webhook-id: string, webhook-timestamp: string, webhook-signature: string} */
    public static function headers(string $secret, string $id, string $timestamp, string $body): array
    {
        return [
            'webhook-id' => $id,
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => self::sign($secret, $id, $timestamp, $body),
        ];
    }

    public static function sign(string $secret, string $id, string $timestamp, string $body): string
    {
        return 'v1,'.base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$body, $secret, true));
    }

    /** Secret à donner à une bibliothèque Standard Webhooks. */
    public static function secretFor(string $secret): string
    {
        return 'whsec_'.base64_encode($secret);
    }
}
