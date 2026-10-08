<?php

declare(strict_types=1);

namespace App\Tests\Agent;

use App\Agent\Llm\AnthropicLlmClient;
use App\Agent\Llm\LlmUnavailable;
use App\Agent\Llm\OpenAiLlmClient;
use App\Agent\Llm\UnconfiguredLlmClient;
use App\Agent\Settings\AgentProviders;
use App\Agent\Settings\AgentSettingsService;
use App\Agent\Settings\SecretBox;
use App\Agent\Settings\UrlGuard;
use App\Entity\AgentSettings;
use App\Entity\User;
use App\Repository\AgentSettingsRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AgentSettingsTest extends TestCase
{
    public function testSecretBoxRoundTripAndTamper(): void
    {
        $box = new SecretBox('', 'app-secret');
        $cipher = $box->encrypt('sk-ant-123');
        self::assertStringStartsWith('v1:', $cipher);
        self::assertStringNotContainsString('sk-ant', $cipher);
        self::assertSame('sk-ant-123', $box->decrypt($cipher));
        self::assertNotSame($cipher, $box->encrypt('sk-ant-123'));

        $other = new SecretBox(base64_encode(random_bytes(32)), 'app-secret');
        $this->expectException(\RuntimeException::class);
        $other->decrypt($cipher);
    }

    public function testSecretBoxRejectsBadConfiguredKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SecretBox('trop-court', 's');
    }

    public function testUrlGuard(): void
    {
        $dns = ['public.example' => ['93.184.216.34'], 'lan.example' => ['192.168.1.20'], 'v6.example' => ['::1']];
        $guard = new UrlGuard(static fn (string $h): array => $dns[$h] ?? []);
        self::assertNull($guard->check('https://public.example/v1', false));
        self::assertNull($guard->check('http://public.example:11434/v1', false));
        self::assertStringContainsString('privée', (string) $guard->check('http://lan.example:11434/v1', false));
        self::assertStringContainsString('privée', (string) $guard->check('http://127.0.0.1:11434/v1', false));
        self::assertStringContainsString('privée', (string) $guard->check('http://[::1]/v1', false));
        self::assertStringContainsString('privée', (string) $guard->check('http://v6.example/v1', false));
        self::assertStringContainsString('privée', (string) $guard->check('http://169.254.169.254/latest', false));
        self::assertNull($guard->check('http://lan.example:11434/v1', true));
        self::assertNotNull($guard->check('file:///etc/passwd', false));
        self::assertNotNull($guard->check('https://user:pw@public.example/v1', false));
        self::assertNotNull($guard->check('https://public.example/v1?x=1', false));
        self::assertStringContainsString('résoudre', (string) $guard->check('https://nowhere.example/v1', false));
    }

    public function testOpenAiTranslation(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Mes tâches ?'],
            ['role' => 'assistant', 'content' => [
                ['type' => 'text', 'text' => 'Je regarde.'],
                ['type' => 'tool_use', 'id' => 't1', 'name' => 'list_todos', 'input' => ['status' => 'open']],
            ]],
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => '{"todos":[]}', 'is_error' => false],
            ]],
        ];
        $out = OpenAiLlmClient::toOpenAiMessages('SYS', $messages);
        self::assertSame(['system', 'user', 'assistant', 'tool'], array_column($out, 'role'));
        self::assertSame('{"status":"open"}', $out[2]['tool_calls'][0]['function']['arguments']);
        self::assertSame('t1', $out[3]['tool_call_id']);

        $empty = OpenAiLlmClient::toOpenAiMessages('S', [['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'x', 'name' => 'n', 'input' => []]]]]);
        self::assertSame('{}', $empty[1]['tool_calls'][0]['function']['arguments']);
        self::assertNull($empty[1]['content']);

        $response = OpenAiLlmClient::fromOpenAiResponse(['choices' => [['finish_reason' => 'tool_calls', 'message' => [
            'content' => 'Un instant.',
            'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'render_ui', 'arguments' => '{"components":[]}']]],
        ]]]]);
        self::assertSame('tool_use', $response->stopReason);
        self::assertSame('Un instant.', $response->text());
        self::assertSame([['id' => 'c1', 'name' => 'render_ui', 'input' => ['components' => []]]], $response->toolUses());
    }

    public function testOpenAiClientHttpCall(): void
    {
        $captured = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['url' => $url, 'headers' => $options['headers'], 'body' => json_decode($options['body'], true)];

            return new MockResponse(json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'OK']]]]));
        });
        $client = new OpenAiLlmClient($http, 'sk-x', 'mistral-large-latest', 'https://api.mistral.ai/v1/');
        $r = $client->complete('S', [['role' => 'user', 'content' => 'ping']], [['name' => 'a', 'description' => 'd', 'input_schema' => ['type' => 'object']]]);
        self::assertSame('OK', $r->text());
        self::assertSame('https://api.mistral.ai/v1/chat/completions', $captured['url']);
        self::assertContains('authorization: Bearer sk-x', $captured['headers']);
        self::assertSame('function', $captured['body']['tools'][0]['type']);
    }

    public function testProviderErrorsAreReadable(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error":{"message":"invalid x-api-key"}}', ['http_code' => 401]));
        $client = new AnthropicLlmClient($http, 'bad', 'claude-sonnet-5-5');
        try {
            $client->complete('S', [['role' => 'user', 'content' => 'x']], []);
            self::assertTrue(false, 'exception attendue');
        } catch (LlmUnavailable $e) {
            self::assertStringContainsString('401', $e->getMessage());
            self::assertStringContainsString('Vérifie la clé API', $e->getMessage());
        }
    }

    public function testUpdateValidationAndView(): void
    {
        [$service, $repo] = $this->service();
        $user = new User();

        $view = $service->view($user);
        self::assertFalse($view['configured']);
        self::assertSame('anthropic', $view['provider']);
        self::assertCount(\count(AgentProviders::all()), $view['providers']);

        $errors = $service->update($user, ['provider' => 'nope', 'model' => 'a b']);
        self::assertSame(['provider', 'model'], array_column($errors, 'field'));
        self::assertNull($repo->stored);

        self::assertSame([], $service->update($user, ['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5']));
        self::assertFalse($service->view($user)['configured'], 'clé requise');

        self::assertSame([], $service->update($user, ['apiKey' => '  sk-ant-abcd1234  ']));
        $view = $service->view($user);
        self::assertTrue($view['configured']);
        self::assertTrue($view['hasKey']);
        self::assertSame('1234', $view['keyHint']);
        self::assertStringNotContainsString('sk-ant', json_encode($view));
        self::assertStringNotContainsString('sk-ant', (string) $repo->stored?->getApiKeyCipher());

        self::assertSame([], $service->update($user, ['model' => 'claude-opus-5-5']));
        self::assertTrue($service->view($user)['hasKey'], 'clé conservée si absente du patch');

        self::assertSame([], $service->update($user, ['apiKey' => '']));
        self::assertFalse($service->view($user)['hasKey']);
    }

    public function testCustomUrlRules(): void
    {
        [$service] = $this->service();
        $user = new User();
        $errors = $service->update($user, ['provider' => 'openai-compatible', 'model' => 'llama3.2']);
        self::assertSame('customBaseUrlEnabled', $errors[0]['field']);

        $errors = $service->update($user, ['provider' => 'openai-compatible', 'model' => 'llama3.2', 'customBaseUrlEnabled' => true, 'customBaseUrl' => 'http://lan.example:11434/v1']);
        self::assertSame('customBaseUrl', $errors[0]['field']);
        self::assertStringContainsString('privée', $errors[0]['message']);

        self::assertSame([], $service->update($user, ['provider' => 'openai-compatible', 'model' => 'llama3.2', 'customBaseUrlEnabled' => true, 'customBaseUrl' => 'https://ollama.public.example/v1/']));
        $view = $service->view($user);
        self::assertTrue($view['configured'], 'clé facultative avec URL personnalisée');
        self::assertSame('https://ollama.public.example/v1', $view['customBaseUrl']);

        [$open] = $this->service(allowPrivate: true);
        self::assertSame([], $open->update($user, ['provider' => 'openai-compatible', 'model' => 'llama3.2', 'customBaseUrlEnabled' => true, 'customBaseUrl' => 'http://lan.example:11434/v1']));
    }

    public function testClientResolution(): void
    {
        $user = new User();
        [$none] = $this->service();
        $resolved = $none->clientFor($user);
        self::assertSame('none', $resolved['source']);
        self::assertInstanceOf(UnconfiguredLlmClient::class, $resolved['client']);
        try {
            $resolved['client']->complete('S', [], []);
            self::assertTrue(false, 'exception attendue');
        } catch (LlmUnavailable $e) {
            self::assertSame('AGENT_NOT_CONFIGURED', $e->errorCode());
            self::assertStringContainsString('https://tadaaa.test/connectivity/assistant', $e->getMessage());
        }

        [$shared] = $this->service(serverKey: 'sk-server', shared: true);
        self::assertSame('server', $shared->clientFor($user)['source']);
        [$adminOnly] = $this->service(serverKey: 'sk-server');
        self::assertSame('none', $adminOnly->clientFor($user)['source']);
        self::assertFalse($adminOnly->view($user)['serverKeyAvailable']);

        [$mine] = $this->service(serverKey: 'sk-server', shared: true);
        $mine->update($user, ['provider' => 'mistral', 'model' => 'mistral-large-latest', 'apiKey' => 'mk-1']);
        $resolved = $mine->clientFor($user);
        self::assertSame('user', $resolved['source']);
        self::assertInstanceOf(OpenAiLlmClient::class, $resolved['client']);
        self::assertSame('mistral-large-latest', $resolved['model']);
    }

    public function testUnreadableKeyGivesAClearError(): void
    {
        $user = new User();
        [$a, $repo] = $this->service();
        $a->update($user, ['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'apiKey' => 'sk-1']);
        [$b] = $this->service(repo: $repo, appSecret: 'autre-secret');
        $client = $b->clientFor($user)['client'];
        try {
            $client->complete('S', [], []);
            self::assertTrue(false, 'exception attendue');
        } catch (LlmUnavailable $e) {
            self::assertStringContainsString('illisible', $e->getMessage());
        }
    }

    /** @return array{0: AgentSettingsService, 1: InMemoryAgentSettingsRepository} */
    private function service(string $serverKey = '', bool $shared = false, bool $allowPrivate = false, ?InMemoryAgentSettingsRepository $repo = null, string $appSecret = 'secret'): array
    {
        $repo ??= new InMemoryAgentSettingsRepository();
        $dns = ['lan.example' => ['192.168.1.20'], 'ollama.public.example' => ['93.184.216.34']];

        return [new AgentSettingsService(
            $repo,
            new SecretBox('', $appSecret),
            new MockHttpClient(),
            $serverKey,
            'claude-sonnet-5-5',
            'https://api.anthropic.com',
            2048,
            $shared,
            $allowPrivate,
            'https://tadaaa.test/',
            new UrlGuard(static fn (string $h): array => $dns[$h] ?? []),
        ), $repo];
    }
}

final class InMemoryAgentSettingsRepository extends AgentSettingsRepository
{
    public ?AgentSettings $stored = null;

    public function __construct()
    {
    }

    public function findForUser(User $user): ?AgentSettings
    {
        return $this->stored;
    }

    public function save(AgentSettings $settings): void
    {
        $this->stored = $settings;
    }
}
