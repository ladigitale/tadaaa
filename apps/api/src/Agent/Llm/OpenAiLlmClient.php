<?php

declare(strict_types=1);

namespace App\Agent\Llm;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Chat Completions au format OpenAI (OpenAI, OpenRouter, Mistral, Ollama, LM Studio…).
 * Traduit les messages « content blocks » de l'agent (texte, tool_use, tool_result)
 * vers `messages` / `tool_calls` / rôle `tool`, et la réponse dans l'autre sens.
 */
final class OpenAiLlmClient implements LlmClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl,
        private readonly int $maxTokens = 2048,
    ) {
    }

    public function complete(string $system, array $messages, array $tools, ?LlmStream $stream = null): LlmResponse
    {
        $headers = ['content-type' => 'application/json'];
        if ($this->apiKey !== '') {
            $headers['authorization'] = 'Bearer '.$this->apiKey;
        }
        $body = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages' => self::toOpenAiMessages($system, $messages),
        ];
        if ($tools !== []) {
            $body['tools'] = array_map(static fn (array $t): array => [
                'type' => 'function',
                'function' => ['name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['input_schema']],
            ], $tools);
        }
        $url = rtrim($this->baseUrl, '/').'/chat/completions';
        try {
            $data = LlmHttp::postJson($this->http, $url, $headers, $body, $raw, $stream);
        } catch (LlmUnavailable $e) {
            // Modèles de raisonnement (o1, o3, gpt-5…) : `max_tokens` est refusé au profit de `max_completion_tokens`.
            if (!str_contains($e->getMessage(), 'max_completion_tokens')) {
                throw $e;
            }
            unset($body['max_tokens']);
            $body['max_completion_tokens'] = $this->maxTokens;
            $data = LlmHttp::postJson($this->http, $url, $headers, $body, $raw, $stream);
        }

        return self::fromOpenAiResponse($data);
    }

    /**
     * @param list<array{role: string, content: string|list<array<string, mixed>>}> $messages
     *
     * @return list<array<string, mixed>>
     */
    public static function toOpenAiMessages(string $system, array $messages): array
    {
        $out = [['role' => 'system', 'content' => $system]];
        foreach ($messages as $message) {
            $content = $message['content'];
            if (\is_string($content)) {
                $out[] = ['role' => $message['role'], 'content' => $content];
                continue;
            }
            if ($message['role'] === 'assistant') {
                $text = [];
                $calls = [];
                foreach ($content as $block) {
                    if (($block['type'] ?? null) === 'text') {
                        $text[] = (string) $block['text'];
                    } elseif (($block['type'] ?? null) === 'tool_use') {
                        $calls[] = [
                            'id' => (string) $block['id'],
                            'type' => 'function',
                            'function' => [
                                'name' => (string) $block['name'],
                                'arguments' => json_encode((object) ($block['input'] ?? []), JSON_UNESCAPED_UNICODE),
                            ],
                        ];
                    }
                }
                $entry = ['role' => 'assistant', 'content' => $text === [] ? null : implode("\n", $text)];
                if ($calls !== []) {
                    $entry['tool_calls'] = $calls;
                }
                $out[] = $entry;
                continue;
            }
            // Utilisateur : résultats d'outils (rôle tool) et éventuel texte.
            foreach ($content as $block) {
                if (($block['type'] ?? null) === 'tool_result') {
                    $out[] = [
                        'role' => 'tool',
                        'tool_call_id' => (string) $block['tool_use_id'],
                        'content' => (!empty($block['is_error']) ? '[erreur] ' : '').(string) $block['content'],
                    ];
                } elseif (($block['type'] ?? null) === 'text') {
                    $out[] = ['role' => 'user', 'content' => (string) $block['text']];
                }
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $data */
    public static function fromOpenAiResponse(array $data): LlmResponse
    {
        $choice = \is_array($data['choices'][0] ?? null) ? $data['choices'][0] : [];
        $message = \is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $blocks = [];
        $rawInputs = [];
        $invalid = [];
        $text = self::contentText($message['content'] ?? null);
        if ($text !== '') {
            $blocks[] = ['type' => 'text', 'text' => $text];
        }
        $seen = [];
        foreach (\is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [] as $call) {
            if (!\is_array($call)) {
                continue;
            }
            $function = \is_array($call['function'] ?? null) ? $call['function'] : [];
            // Selon le fournisseur, `arguments` est une chaîne JSON, un objet déjà décodé, ou absent/vide (outil sans paramètre).
            $arguments = $function['arguments'] ?? '';
            $json = \is_string($arguments) ? trim($arguments) : (\is_array($arguments) || $arguments instanceof \stdClass ? json_encode($arguments) : '');
            $json = \in_array($json, ['', 'null', '[]'], true) ? '{}' : (string) $json;
            $args = json_decode($json, true);
            $raw = json_decode($json, false);
            // Id absent ou répété (certains serveurs locaux renvoient « call_0 » à chaque étape) : l'id doit être unique.
            $id = \is_string($call['id'] ?? null) && $call['id'] !== '' ? $call['id'] : '';
            if ($id === '' || isset($seen[$id])) {
                $id = 'call_'.bin2hex(random_bytes(6));
            }
            $seen[$id] = true;
            $name = (string) ($function['name'] ?? '');
            if (!$raw instanceof \stdClass || !\is_array($args) || $name === '') {
                $invalid[] = $id;
            } else {
                $rawInputs[$id] = $raw;
            }
            $blocks[] = [
                'type' => 'tool_use',
                'id' => $id,
                'name' => $name,
                'input' => \is_array($args) ? $args : [],
            ];
        }
        $finish = (string) ($choice['finish_reason'] ?? 'stop');

        return new LlmResponse($blocks, match ($finish) {
            'tool_calls' => 'tool_use',
            'length' => 'max_tokens',
            default => 'end_turn',
        }, $rawInputs, null, $invalid);
    }

    /** `content` est une chaîne, ou (Mistral, certains proxys) une liste de parties `{type: text, text}`. */
    private static function contentText(mixed $content): string
    {
        if (\is_string($content)) {
            return trim($content) === '' ? '' : $content;
        }
        if (!\is_array($content)) {
            return '';
        }
        $parts = [];
        foreach ($content as $part) {
            if (\is_string($part)) {
                $parts[] = $part;
            } elseif (\is_array($part) && \is_string($part['text'] ?? null)) {
                $parts[] = $part['text'];
            }
        }
        $text = implode('', $parts);

        return trim($text) === '' ? '' : $text;
    }
}
