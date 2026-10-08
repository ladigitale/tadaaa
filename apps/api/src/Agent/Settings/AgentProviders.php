<?php

declare(strict_types=1);

namespace App\Agent\Settings;

/**
 * Fournisseurs proposés dans les réglages. `protocol` choisit le client :
 * `anthropic` (Messages API) ou `openai` (Chat Completions, compatible OpenRouter,
 * Mistral, Ollama, LM Studio…). Les modèles listés sont des suggestions : le champ
 * reste libre, les catalogues des fournisseurs changent plus vite que ce fichier.
 */
final class AgentProviders
{
    public const ANTHROPIC = 'anthropic';
    public const OPENAI = 'openai';
    public const OPENROUTER = 'openrouter';
    public const MISTRAL = 'mistral';
    public const OPENAI_COMPATIBLE = 'openai-compatible';

    /** @return list<array{id: string, label: string, protocol: 'anthropic'|'openai', baseUrl: string, defaultModel: string, models: list<string>, keyRequired: bool}> */
    public static function all(): array
    {
        return [
            [
                'id' => self::ANTHROPIC,
                'label' => 'Anthropic (Claude)',
                'protocol' => 'anthropic',
                'baseUrl' => 'https://api.anthropic.com',
                'defaultModel' => 'claude-sonnet-5-5',
                'models' => ['claude-sonnet-5-5', 'claude-opus-5-5', 'claude-haiku-5-5'],
                'keyRequired' => true,
            ],
            [
                'id' => self::OPENAI,
                'label' => 'OpenAI',
                'protocol' => 'openai',
                'baseUrl' => 'https://api.openai.com/v1',
                'defaultModel' => 'gpt-5',
                'models' => ['gpt-5', 'gpt-5-mini'],
                'keyRequired' => true,
            ],
            [
                'id' => self::OPENROUTER,
                'label' => 'OpenRouter',
                'protocol' => 'openai',
                'baseUrl' => 'https://openrouter.ai/api/v1',
                'defaultModel' => 'anthropic/claude-sonnet-5.5',
                'models' => ['anthropic/claude-sonnet-5.5', 'openai/gpt-5', 'mistralai/mistral-large'],
                'keyRequired' => true,
            ],
            [
                'id' => self::MISTRAL,
                'label' => 'Mistral',
                'protocol' => 'openai',
                'baseUrl' => 'https://api.mistral.ai/v1',
                'defaultModel' => 'mistral-large-latest',
                'models' => ['mistral-large-latest', 'mistral-medium-latest', 'mistral-small-latest'],
                'keyRequired' => true,
            ],
            [
                'id' => self::OPENAI_COMPATIBLE,
                'label' => 'Autre serveur compatible OpenAI (Ollama, LM Studio…)',
                'protocol' => 'openai',
                'baseUrl' => '',
                'defaultModel' => '',
                'models' => [],
                'keyRequired' => false,
            ],
        ];
    }

    /** @return array{id: string, label: string, protocol: 'anthropic'|'openai', baseUrl: string, defaultModel: string, models: list<string>, keyRequired: bool}|null */
    public static function get(string $id): ?array
    {
        foreach (self::all() as $provider) {
            if ($provider['id'] === $id) {
                return $provider;
            }
        }

        return null;
    }
}
