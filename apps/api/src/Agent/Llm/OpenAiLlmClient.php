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

    public function complete(string $system, array $messages, array $tools): LlmResponse
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
        $data = LlmHttp::postJson($this->http, rtrim($this->baseUrl, '/').'/chat/completions', $headers, $body);

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
        if (\is_string($message['content'] ?? null) && trim($message['content']) !== '') {
            $blocks[] = ['type' => 'text', 'text' => $message['content']];
        }
        foreach (\is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [] as $i => $call) {
            $function = \is_array($call['function'] ?? null) ? $call['function'] : [];
            $json = (string) ($function['arguments'] ?? '{}');
            $args = json_decode($json, true);
            $id = (string) ($call['id'] ?? 'call_'.$i);
            $raw = json_decode($json, false);
            if ($raw instanceof \stdClass) {
                $rawInputs[$id] = $raw;
            }
            $blocks[] = [
                'type' => 'tool_use',
                'id' => $id,
                'name' => (string) ($function['name'] ?? ''),
                'input' => \is_array($args) ? $args : [],
            ];
        }
        $finish = (string) ($choice['finish_reason'] ?? 'stop');

        return new LlmResponse($blocks, match ($finish) {
            'tool_calls' => 'tool_use',
            'length' => 'max_tokens',
            default => 'end_turn',
        }, $rawInputs);
    }
}
