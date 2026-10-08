<?php

declare(strict_types=1);

namespace App\Agent\Llm;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Messages API d'Anthropic (sans streaming : un appel par tour d'agent).
 * Construit par LlmClientResolver avec les réglages de l'utilisateur (ou ceux du serveur).
 */
final class AnthropicLlmClient implements LlmClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $maxTokens = 2048,
        private readonly string $baseUrl = 'https://api.anthropic.com',
    ) {
    }

    public function complete(string $system, array $messages, array $tools): LlmResponse
    {
        $headers = ['anthropic-version' => '2023-06-01', 'content-type' => 'application/json'];
        if ($this->apiKey !== '') {
            $headers['x-api-key'] = $this->apiKey;
        }
        $body = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'system' => $system,
            'messages' => $messages,
        ];
        if ($tools !== []) {
            $body['tools'] = $tools;
        }
        $data = LlmHttp::postJson($this->http, rtrim($this->baseUrl, '/').'/v1/messages', $headers, $body);

        return new LlmResponse(
            \is_array($data['content'] ?? null) ? array_values($data['content']) : [],
            (string) ($data['stop_reason'] ?? 'end_turn'),
        );
    }
}
