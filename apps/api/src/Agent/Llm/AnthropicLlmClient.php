<?php

declare(strict_types=1);

namespace App\Agent\Llm;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Messages API d'Anthropic (sans streaming : un appel par tour d'agent).
 * Clé et modèle par variables d'environnement ; sans clé, l'agent répond une erreur claire.
 */
final class AnthropicLlmClient implements LlmClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(AGENT_LLM_API_KEY)%')]
        private readonly string $apiKey,
        #[Autowire('%env(AGENT_LLM_MODEL)%')]
        private readonly string $model,
        #[Autowire('%env(int:AGENT_LLM_MAX_TOKENS)%')]
        private readonly int $maxTokens = 2048,
        #[Autowire('%env(AGENT_LLM_BASE_URL)%')]
        private readonly string $baseUrl = 'https://api.anthropic.com',
    ) {
    }

    public function complete(string $system, array $messages, array $tools): LlmResponse
    {
        if ($this->apiKey === '') {
            throw new LlmUnavailable('Agent non configuré : AGENT_LLM_API_KEY est vide.');
        }
        $response = $this->http->request('POST', rtrim($this->baseUrl, '/').'/v1/messages', [
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ],
            'json' => [
                'model' => $this->model,
                'max_tokens' => $this->maxTokens,
                'system' => $system,
                'messages' => $messages,
                'tools' => $tools,
            ],
            'timeout' => 120,
        ]);
        $status = $response->getStatusCode();
        $data = $response->toArray(false);
        if ($status >= 400) {
            $message = \is_array($data['error'] ?? null) ? (string) ($data['error']['message'] ?? '') : '';
            throw new LlmUnavailable(sprintf('Le modèle a répondu %d%s.', $status, $message !== '' ? ' : '.$message : ''));
        }

        return new LlmResponse(
            \is_array($data['content'] ?? null) ? array_values($data['content']) : [],
            (string) ($data['stop_reason'] ?? 'end_turn'),
        );
    }
}
