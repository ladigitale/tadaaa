<?php

declare(strict_types=1);

namespace App\Agent\Llm;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** Appel JSON commun aux clients LLM : erreurs traduites en LlmUnavailable lisible. */
final class LlmHttp
{
    /** Secondes sans rien recevoir avant de signaler au client que l'attente continue. */
    public const HEARTBEAT_EVERY = 10.0;
    /** Secondes sans rien recevoir avant d'abandonner (fournisseur muet). */
    public const IDLE_LIMIT = 120.0;

    /**
     * Lit la réponse morceau par morceau. Pendant les silences, appelle `$stream->waiting()`
     * toutes les HEARTBEAT_EVERY secondes ; au-delà de `$idleLimit` sans rien recevoir, abandonne.
     *
     * @param \Closure(string): void $onChunk
     */
    public static function consume(
        HttpClientInterface $http,
        ResponseInterface $response,
        \Closure $onChunk,
        ?LlmStream $stream = null,
        float $idleLimit = self::IDLE_LIMIT,
        float $heartbeat = self::HEARTBEAT_EVERY,
    ): void {
        $silent = 0.0;
        foreach ($http->stream($response, $heartbeat) as $chunk) {
            if ($chunk->isTimeout()) {
                $silent += $heartbeat;
                if ($silent >= $idleLimit) {
                    $response->cancel();
                    throw new LlmUnavailable(sprintf('Le fournisseur ne répond plus (rien reçu depuis %d s).', (int) $silent));
                }
                $stream?->waiting();
                continue;
            }
            if ($chunk->isFirst()) {
                $response->getStatusCode(); // statut « lu » : une erreur 4xx/5xx ne lève pas ici, l'appelant la lit ensuite
            }
            $silent = 0.0;
            $onChunk($chunk->getContent());
        }
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     * @param string|null           $raw  reçoit le corps brut de la réponse
     * @param LlmStream|null        $stream signalé pendant l'attente (génération sans flux)
     *
     * @return array<string, mixed>
     */
    public static function postJson(HttpClientInterface $http, string $url, array $headers, array $body, ?string &$raw = null, ?LlmStream $stream = null): array
    {
        try {
            $response = $http->request('POST', $url, [
                'headers' => $headers,
                'json' => $body,
                // Sans flux, rien n'arrive avant la fin de la génération (jusqu'à max_tokens).
                'timeout' => 300,
                'max_redirects' => 0,
            ]);
            if ($stream !== null) {
                // Sans flux, la réponse n'arrive qu'à la fin : on attend par tranches pour signaler la vie.
                $buffer = '';
                self::consume($http, $response, static function (string $c) use (&$buffer): void {
                    $buffer .= $c;
                }, $stream);
            }
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
