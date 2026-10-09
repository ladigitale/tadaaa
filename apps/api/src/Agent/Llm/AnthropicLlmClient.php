<?php

declare(strict_types=1);

namespace App\Agent\Llm;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Messages API d'Anthropic. Construit par AgentSettingsService avec les réglages de
 * l'utilisateur (ou ceux du serveur).
 *
 * - Avec un `LlmStream`, la réponse arrive en flux (SSE) : le texte et le début des
 *   appels d'outils sont signalés tout de suite, sans attendre la fin d'un long document.
 * - Prompt caching : outils + prompt système, puis la conversation jusqu'au dernier
 *   message, sont mis en cache (le catalogue des artefacts n'est pas refacturé à chaque tour).
 */
final class AnthropicLlmClient implements LlmClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $maxTokens = 16000,
        private readonly string $baseUrl = 'https://api.anthropic.com',
    ) {
    }

    public function complete(string $system, array $messages, array $tools, ?LlmStream $stream = null): LlmResponse
    {
        $headers = ['anthropic-version' => '2023-06-01', 'content-type' => 'application/json'];
        if ($this->apiKey !== '') {
            $headers['x-api-key'] = $this->apiKey;
        }
        $body = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'system' => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
            'messages' => self::withCacheBreakpoint($messages),
        ];
        if ($tools !== []) {
            $body['tools'] = $tools;
        }
        $url = rtrim($this->baseUrl, '/').'/v1/messages';

        if ($stream === null) {
            $raw = null;
            $data = LlmHttp::postJson($this->http, $url, $headers, $body, $raw);

            return self::fromMessage($data, \is_string($raw) ? json_decode($raw, false) : null);
        }

        return $this->streamed($url, $headers, $body + ['stream' => true], $stream);
    }

    /**
     * Point de cache sur le dernier bloc du dernier message (copie : l'historique de
     * l'agent n'est pas modifié, sinon les points de cache s'accumuleraient).
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return list<array<string, mixed>>
     */
    public static function withCacheBreakpoint(array $messages): array
    {
        $last = \count($messages) - 1;
        if ($last < 0) {
            return $messages;
        }
        $content = $messages[$last]['content'] ?? null;
        if (\is_string($content)) {
            $content = [['type' => 'text', 'text' => $content]];
        }
        if (!\is_array($content) || $content === []) {
            return $messages;
        }
        $i = \count($content) - 1;
        $block = $content[$i];
        if ($block instanceof \stdClass) {
            $block = clone $block;
            $block->cache_control = (object) ['type' => 'ephemeral'];
        } elseif (\is_array($block)) {
            $block['cache_control'] = ['type' => 'ephemeral'];
        }
        $content[$i] = $block;
        $messages[$last]['content'] = $content;

        return $messages;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     */
    private function streamed(string $url, array $headers, array $body, LlmStream $stream): LlmResponse
    {
        $parser = new AnthropicStreamParser($stream);
        try {
            $response = $this->http->request('POST', $url, [
                'headers' => $headers + ['accept' => 'text/event-stream'],
                'json' => $body,
                // Délai d'inactivité entre deux morceaux, pas durée totale.
                'timeout' => 120,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                $data = json_decode($response->getContent(false), true);
                throw LlmHttp::failure($status, \is_array($data) ? $data : []);
            }
            foreach ($this->http->stream($response) as $chunk) {
                $parser->push($chunk->getContent());
            }
        } catch (ExceptionInterface $e) {
            throw LlmHttp::unreachable($e);
        }

        return $parser->finish();
    }

    /** @param array<string, mixed> $data */
    private static function fromMessage(array $data, mixed $decoded): LlmResponse
    {
        // Même réponse décodée en objets : `{}` reste distinct de `[]` (arguments, historique).
        $rawContent = null;
        $rawInputs = [];
        if ($decoded instanceof \stdClass && \is_array($decoded->content ?? null)) {
            $rawContent = array_values($decoded->content);
            foreach ($rawContent as $block) {
                if ($block instanceof \stdClass && ($block->type ?? null) === 'tool_use' && ($block->input ?? null) instanceof \stdClass) {
                    $rawInputs[(string) $block->id] = $block->input;
                }
            }
        }

        return new LlmResponse(
            \is_array($data['content'] ?? null) ? array_values($data['content']) : [],
            (string) ($data['stop_reason'] ?? 'end_turn'),
            $rawInputs,
            $rawContent,
        );
    }
}
