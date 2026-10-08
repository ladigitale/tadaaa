<?php

declare(strict_types=1);

namespace App\Agent\Llm;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Appel JSON commun aux clients LLM : erreurs traduites en LlmUnavailable lisible. */
final class LlmHttp
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     * @param string|null           $raw  reçoit le corps brut de la réponse
     *
     * @return array<string, mixed>
     */
    public static function postJson(HttpClientInterface $http, string $url, array $headers, array $body, ?string &$raw = null): array
    {
        try {
            $response = $http->request('POST', $url, [
                'headers' => $headers,
                'json' => $body,
                'timeout' => 120,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
            $raw = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw new LlmUnavailable('Fournisseur injoignable : '.self::short($e->getMessage()), 0, $e);
        }
        if ($status >= 400) {
            $error = $data['error'] ?? null;
            $message = \is_array($error) ? (string) ($error['message'] ?? '') : (\is_string($error) ? $error : '');
            $hint = match (true) {
                $status === 401 || $status === 403 => ' Vérifie la clé API.',
                $status === 404 => ' Vérifie le modèle et l’URL.',
                $status === 429 => ' Quota ou limite atteinte chez le fournisseur.',
                default => '',
            };
            throw new LlmUnavailable(sprintf('Le fournisseur a répondu %d%s.%s', $status, $message !== '' ? ' : '.self::short($message) : '', $hint));
        }

        return $data;
    }

    private static function short(string $message): string
    {
        return mb_substr(trim($message), 0, 300);
    }
}
