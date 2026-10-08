<?php

declare(strict_types=1);

namespace App\Agent\Settings;

use App\Agent\Llm\AnthropicLlmClient;
use App\Agent\Llm\LlmClient;
use App\Agent\Llm\OpenAiLlmClient;
use App\Agent\Llm\UnconfiguredLlmClient;
use App\Entity\AgentSettings;
use App\Entity\User;
use App\Repository\AgentSettingsRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Réglages de l'assistant par utilisateur, et choix du client LLM pour un run :
 * 1. les réglages de l'utilisateur s'ils sont complets ;
 * 2. sinon la clé du serveur (`AGENT_LLM_*`) si elle est partagée avec lui
 *    (`AGENT_LLM_SHARED=1` pour tous, sinon administrateurs seulement) ;
 * 3. sinon un client qui répond « assistant non configuré ».
 */
final class AgentSettingsService
{
    public const MAX_KEY_LENGTH = 512;

    public function __construct(
        private readonly AgentSettingsRepository $repository,
        private readonly SecretBox $secretBox,
        private readonly HttpClientInterface $http,
        #[Autowire('%env(AGENT_LLM_API_KEY)%')]
        private readonly string $serverKey,
        #[Autowire('%env(AGENT_LLM_MODEL)%')]
        private readonly string $serverModel,
        #[Autowire('%env(AGENT_LLM_BASE_URL)%')]
        private readonly string $serverBaseUrl,
        #[Autowire('%env(int:AGENT_LLM_MAX_TOKENS)%')]
        private readonly int $maxTokens,
        #[Autowire('%env(bool:AGENT_LLM_SHARED)%')]
        private readonly bool $serverKeyShared,
        #[Autowire('%env(bool:AGENT_ALLOW_PRIVATE_URLS)%')]
        private readonly bool $allowPrivateUrls,
        #[Autowire('%env(APP_PUBLIC_URL)%')]
        private readonly string $appPublicUrl,
        private readonly ?UrlGuard $urlGuard = null,
    ) {
    }

    /** Vue envoyée au navigateur : jamais la clé, seulement `hasKey` et ses 4 derniers caractères. */
    public function view(User $user): array
    {
        $settings = $this->repository->findForUser($user);

        return [
            'provider' => $settings?->getProvider() ?: AgentProviders::ANTHROPIC,
            'model' => $settings?->getModel() ?? '',
            'hasKey' => $settings?->hasKey() ?? false,
            'keyHint' => $settings?->getKeyHint(),
            'customBaseUrlEnabled' => $settings?->isCustomBaseUrlEnabled() ?? false,
            'customBaseUrl' => $settings?->getCustomBaseUrl(),
            'configured' => $settings !== null && $this->isComplete($settings),
            'serverKeyAvailable' => $this->serverKeyAvailableFor($user),
            'allowPrivateUrls' => $this->allowPrivateUrls,
            'providers' => AgentProviders::all(),
            'updatedAt' => $settings?->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * Applique un patch `{provider?, model?, apiKey?, customBaseUrlEnabled?, customBaseUrl?}`.
     * `apiKey` absent : inchangée ; `""` : supprimée.
     *
     * @param array<string, mixed> $patch
     *
     * @return list<array{field: string, message: string}> erreurs (rien n'est enregistré s'il y en a)
     */
    public function update(User $user, array $patch): array
    {
        $settings = $this->repository->findForUser($user) ?? new AgentSettings($user);
        $errors = [];

        $providerId = \array_key_exists('provider', $patch) ? $patch['provider'] : ($settings->getProvider() ?: AgentProviders::ANTHROPIC);
        $provider = \is_string($providerId) ? AgentProviders::get($providerId) : null;
        if ($provider === null) {
            $errors[] = ['field' => 'provider', 'message' => 'Fournisseur inconnu.'];
        }

        $model = \array_key_exists('model', $patch) ? $patch['model'] : $settings->getModel();
        if (!\is_string($model) || !preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/@+-]{0,127}$#', trim($model))) {
            $errors[] = ['field' => 'model', 'message' => 'Nom de modèle invalide (lettres, chiffres, . _ : / @ + -, 128 max).'];
        }

        $customEnabled = \array_key_exists('customBaseUrlEnabled', $patch) ? $patch['customBaseUrlEnabled'] : $settings->isCustomBaseUrlEnabled();
        $customUrl = \array_key_exists('customBaseUrl', $patch) ? $patch['customBaseUrl'] : $settings->getCustomBaseUrl();
        if (!\is_bool($customEnabled)) {
            $errors[] = ['field' => 'customBaseUrlEnabled', 'message' => 'Booléen attendu.'];
        }
        $customUrl = \is_string($customUrl) && trim($customUrl) !== '' ? rtrim(trim($customUrl), '/') : null;
        if ($customEnabled === true) {
            $message = $customUrl === null ? 'URL requise quand l’URL personnalisée est cochée.' : $this->guard()->check($customUrl, $this->allowPrivateUrls);
            if ($message !== null) {
                $errors[] = ['field' => 'customBaseUrl', 'message' => $message];
            }
        } elseif ($provider !== null && $provider['baseUrl'] === '') {
            $errors[] = ['field' => 'customBaseUrlEnabled', 'message' => 'Ce fournisseur demande une URL personnalisée.'];
        }

        $newKey = null;
        if (\array_key_exists('apiKey', $patch)) {
            $key = $patch['apiKey'];
            $key = \is_string($key) ? trim($key) : null;
            if ($key === null || \strlen($key) > self::MAX_KEY_LENGTH || preg_match('/\s/', $key)) {
                $errors[] = ['field' => 'apiKey', 'message' => 'Clé API invalide (512 caractères max, sans espace).'];
            } else {
                $newKey = $key;
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        $settings->setProvider((string) $providerId);
        $settings->setModel(trim((string) $model));
        $settings->setCustomBaseUrl((bool) $customEnabled, $customUrl);
        if ($newKey !== null) {
            $settings->setApiKey(
                $newKey === '' ? null : $this->secretBox->encrypt($newKey),
                $newKey === '' ? null : substr($newKey, -4),
            );
        }
        $this->repository->save($settings);

        return [];
    }

    /**
     * Client LLM à utiliser pour un run de cet utilisateur.
     *
     * @return array{client: LlmClient, source: 'user'|'server'|'none', model: string}
     */
    public function clientFor(User $user): array
    {
        $settings = $this->repository->findForUser($user);
        if ($settings !== null && $this->isComplete($settings)) {
            return ['client' => $this->buildClient($settings), 'source' => 'user', 'model' => $settings->getModel()];
        }
        if ($this->serverKeyAvailableFor($user)) {
            return [
                'client' => new AnthropicLlmClient($this->http, $this->serverKey, $this->serverModel, $this->maxTokens, $this->serverBaseUrl),
                'source' => 'server',
                'model' => $this->serverModel,
            ];
        }

        return [
            'client' => new UnconfiguredLlmClient(sprintf(
                'Assistant non configuré : choisis un fournisseur et une clé API dans Tadaaa (%s).',
                rtrim($this->appPublicUrl, '/').'/connectivity/assistant',
            )),
            'source' => 'none',
            'model' => '',
        ];
    }

    public function isComplete(AgentSettings $settings): bool
    {
        $provider = AgentProviders::get($settings->getProvider());
        if ($provider === null || $settings->getModel() === '') {
            return false;
        }
        if ($settings->isCustomBaseUrlEnabled()) {
            return $settings->getCustomBaseUrl() !== null; // serveur perso : clé facultative
        }

        return $provider['baseUrl'] !== '' && (!$provider['keyRequired'] || $settings->hasKey());
    }

    private function buildClient(AgentSettings $settings): LlmClient
    {
        $provider = AgentProviders::get($settings->getProvider());
        \assert($provider !== null);
        try {
            $key = $settings->hasKey() ? $this->secretBox->decrypt((string) $settings->getApiKeyCipher()) : '';
        } catch (\RuntimeException) {
            return new UnconfiguredLlmClient('La clé API enregistrée est illisible (clé de chiffrement du serveur changée) : saisis-la de nouveau dans les réglages de l’assistant.');
        }
        $custom = $settings->isCustomBaseUrlEnabled();
        $baseUrl = $custom ? (string) $settings->getCustomBaseUrl() : $provider['baseUrl'];
        // URL libre : le blocage du réseau interne est fait à la connexion (résiste au DNS rebinding).
        $http = $custom && !$this->allowPrivateUrls ? new NoPrivateNetworkHttpClient($this->http) : $this->http;

        return $provider['protocol'] === 'anthropic'
            ? new AnthropicLlmClient($http, $key, $settings->getModel(), $this->maxTokens, $baseUrl)
            : new OpenAiLlmClient($http, $key, $settings->getModel(), $baseUrl, $this->maxTokens);
    }

    private function serverKeyAvailableFor(User $user): bool
    {
        return $this->serverKey !== '' && ($this->serverKeyShared || \in_array('ROLE_ADMIN', $user->getRoles(), true));
    }

    private function guard(): UrlGuard
    {
        return $this->urlGuard ?? new UrlGuard();
    }
}
