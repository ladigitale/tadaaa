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
                // Sans flux, rien n'arrive avant la fin de la génération (jusqu'à max_tokens).
                'timeout' => 300,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
            $raw = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw self::unreachable($e);
        }
        if ($status >= 400) {
            throw self::failure($status, $data);
        }

        return $data;
    }

    /** @param array<string, mixed> $data corps d'erreur du fournisseur */
    public static function failure(int $status, array $data): LlmUnavailable
    {
        $error = $data['error'] ?? null;
        $message = \is_array($error) ? (string) ($error['message'] ?? '') : (\is_string($error) ? $error : '');
        $hint = match (true) {
            $status === 401 || $status === 403 => ' Vérifie la clé API.',
            $status === 404 => ' Vérifie le modèle et l’URL.',
            $status === 429 => ' Quota ou limite atteinte chez le fournisseur.',
            default => '',
        };

        return new LlmUnavailable(sprintf('Le fournisseur a répondu %d%s.%s', $status, $message !== '' ? ' : '.self::short($message) : '', $hint));
    }

    public static function unreachable(\Throwable $e): LlmUnavailable
    {
        return new LlmUnavailable('Fournisseur injoignable : '.self::short($e->getMessage()), 0, $e);
    }

    public static function short(string $message): string
    {
        return mb_substr(trim($message), 0, 300);
    }
}
