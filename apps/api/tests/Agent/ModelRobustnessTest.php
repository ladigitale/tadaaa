<?php

declare(strict_types=1);

namespace App\Tests\Agent;

use App\Agent\AgentRunner;
use App\Agent\AgUi\ArrayEventSink;
use App\Agent\Llm\AnthropicStreamParser;
use App\Agent\Llm\LlmClient;
use App\Agent\Llm\LlmHttp;
use App\Agent\Llm\LlmResponse;
use App\Agent\Llm\LlmStream;
use App\Agent\Llm\LlmUnavailable;
use App\Agent\Llm\OpenAiLlmClient;
use App\Agent\RunInput;
use App\Agent\Tool\AgentTool;
use App\Agent\Tool\ToolContext;
use App\Agent\Tool\Toolbox;
use App\Agent\Tool\ToolResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Écarts entre modèles et fournisseurs : arguments d'outil mal formés, réponses vides,
 * ids d'appel répétés, signal de vie pendant les silences, paramètres propres à OpenAI.
 */
final class ModelRobustnessTest extends TestCase
{
    public function testInvalidToolArgumentsAreNotExecutedAsEmptyObject(): void
    {
        $response = OpenAiLlmClient::fromOpenAiResponse(['choices' => [['finish_reason' => 'tool_calls', 'message' => [
            'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'echo', 'arguments' => '{"text": "coupé']]],
        ]]]]);
        $use = $response->toolUses()[0];
        self::assertTrue($use['invalid']);

        $tool = new EchoTool();
        $llm = new OneShotLlm([$response, new LlmResponse([['type' => 'text', 'text' => 'ok']], 'end_turn')]);
        $sink = $this->run($llm, $tool);

        self::assertSame(0, $tool->calls);
        $result = $llm->calls[1]['messages'][2]['content'][0];
        self::assertTrue($result['is_error']);
        self::assertStringContainsString('Arguments de echo invalides', $result['content']);
        self::assertContains('RUN_FINISHED', $sink->types());
    }

    public function testArgumentsAsObjectEmptyStringOrEmptyList(): void
    {
        $call = static fn (mixed $arguments): array => OpenAiLlmClient::fromOpenAiResponse(['choices' => [['message' => [
            'tool_calls' => [['id' => 'c', 'function' => ['name' => 'echo', 'arguments' => $arguments]]],
        ]]]])->toolUses()[0];

        self::assertSame(['text' => 'a'], $call(['text' => 'a'])['input']); // déjà décodé (Ollama…)
        foreach (['', '[]', null] as $empty) { // outil sans paramètre
            $use = $call($empty);
            self::assertSame([], $use['input']);
            self::assertFalse($use['invalid']);
        }
        self::assertTrue($call('not json')['invalid']);
    }

    public function testMissingOrRepeatedToolCallIdsBecomeUnique(): void
    {
        $r = OpenAiLlmClient::fromOpenAiResponse(['choices' => [['message' => ['tool_calls' => [
            ['id' => 'call_0', 'function' => ['name' => 'echo', 'arguments' => '{}']],
            ['id' => 'call_0', 'function' => ['name' => 'echo', 'arguments' => '{}']],
            ['function' => ['name' => 'echo', 'arguments' => '{}']],
        ]]]]]);
        $ids = array_column($r->toolUses(), 'id');
        self::assertCount(3, array_unique($ids));
        self::assertSame('call_0', $ids[0]);
    }

    public function testContentGivenAsListOfParts(): void
    {
        $r = OpenAiLlmClient::fromOpenAiResponse(['choices' => [['message' => ['content' => [
            ['type' => 'text', 'text' => 'Bon'],
            ['type' => 'text', 'text' => 'jour'],
        ]]]]]);
        self::assertSame('Bonjour', $r->text());
    }

    public function testEmptyModelResponseIsReportedWithACode(): void
    {
        $sink = $this->run(new OneShotLlm([new LlmResponse([], 'end_turn')]), new EchoTool());

        self::assertSame(['RUN_STARTED', 'RUN_ERROR'], $sink->types());
        self::assertSame('EMPTY_RESPONSE', $sink->events[1]['code']);
    }

    public function testToolOnlyRunIsNotAnEmptyResponse(): void
    {
        $llm = new OneShotLlm([
            new LlmResponse([['type' => 'tool_use', 'id' => 't', 'name' => 'echo', 'input' => ['text' => 'x']]], 'tool_use'),
            new LlmResponse([], 'end_turn'), // rien à ajouter après l'outil : légitime
        ]);
        $sink = $this->run($llm, new EchoTool());

        self::assertContains('RUN_FINISHED', $sink->types());
        self::assertNotContains('RUN_ERROR', $sink->types());
    }

    public function testInterfaceErrorsComeWithInstructions(): void
    {
        $llm = new OneShotLlm([new LlmResponse([['type' => 'text', 'text' => 'ok']], 'end_turn')]);
        $input = RunInput::fromArray([
            'threadId' => 't', 'runId' => 'r',
            'messages' => [['role' => 'user', 'content' => 'salut']],
            'forwardedProps' => ['a2uiErrors' => [['code' => 'UNKNOWN_SURFACE', 'surfaceId' => 's', 'message' => 'x']]],
        ]);
        (new AgentRunner($llm, new Toolbox([new EchoTool()])))->run($input, new ArrayEventSink());

        self::assertStringContainsString('corrige-la avec render_ui', $llm->calls[0]['messages'][0]['content']);
    }

    public function testHeartbeatWhileTheProviderIsSilent(): void
    {
        $waits = 0;
        $stream = new LlmStream(null, null, static function () use (&$waits): void { ++$waits; });
        $http = new FakeStreamHttp(['timeout', 'timeout', 'data:{"ok":true}']);
        $got = '';
        LlmHttp::consume($http, $http->request('GET', 'https://x.test/'), static function (string $c) use (&$got): void { $got .= $c; }, $stream, 120.0, 10.0);

        self::assertSame('{"ok":true}', $got);
        self::assertSame(2, $waits);
    }

    public function testWaitingProviderKeepsTheClientAwakeWithStatusEvents(): void
    {
        $llm = new class implements LlmClient {
            public function complete(string $system, array $messages, array $tools, ?LlmStream $stream = null): LlmResponse
            {
                $stream?->waiting();
                $stream?->waiting();

                return new LlmResponse([['type' => 'text', 'text' => 'enfin']], 'end_turn');
            }
        };
        $sink = $this->run($llm, new EchoTool());

        $status = array_values(array_filter($sink->events, static fn (array $e) => $e['type'] === 'CUSTOM'));
        self::assertCount(2, $status);
        self::assertSame(['status', AgentRunner::WAITING_STATUS], [$status[0]['name'], $status[0]['value']]);
        self::assertContains('RUN_FINISHED', $sink->types());
    }

    public function testSilentProviderIsAbandoned(): void
    {
        $http = new FakeStreamHttp(['timeout', 'timeout', 'timeout', 'data:trop tard']);
        $got = '';
        try {
            LlmHttp::consume($http, $http->request('GET', 'https://x.test/'), static function (string $c) use (&$got): void { $got .= $c; }, null, 25.0, 10.0);
            self::fail('exception attendue');
        } catch (LlmUnavailable $e) {
            self::assertStringContainsString('ne répond plus', $e->getMessage());
            self::assertSame('', $got);
        }
    }

    public function testOpenAiRetriesWithMaxCompletionTokens(): void
    {
        $bodies = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$bodies): MockResponse {
            $body = json_decode($options['body'], true);
            $bodies[] = $body;
            if (isset($body['max_tokens'])) {
                return new MockResponse(json_encode(['error' => ['message' => "Unsupported parameter: 'max_tokens'. Use 'max_completion_tokens' instead."]]), ['http_code' => 400]);
            }

            return new MockResponse(json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'ok']]]]));
        });
        $client = new OpenAiLlmClient($http, 'k', 'o3', 'https://api.openai.com/v1');
        $r = $client->complete('S', [['role' => 'user', 'content' => 'x']], [], new LlmStream());

        self::assertSame('ok', $r->text());
        self::assertCount(2, $bodies);
        self::assertArrayNotHasKey('max_tokens', $bodies[1]);
        self::assertSame(2048, $bodies[1]['max_completion_tokens']);
    }

    public function testTruncatedAnthropicToolJsonIsFlagged(): void
    {
        $parser = new AnthropicStreamParser();
        $sse = static fn (array $e): string => 'data: '.json_encode($e)."\n\n";
        $parser->push($sse(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'a', 'name' => 'echo']]));
        $parser->push($sse(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"text": "inache']]));
        $parser->push($sse(['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use']]));

        self::assertTrue($parser->finish()->toolUses()[0]['invalid']);
    }

    private function run(LlmClient $llm, AgentTool $tool): ArrayEventSink
    {
        $sink = new ArrayEventSink();
        $input = RunInput::fromArray(['threadId' => 't', 'runId' => 'r', 'messages' => [['role' => 'user', 'content' => 'go']]]);
        (new AgentRunner($llm, new Toolbox([$tool])))->run($input, $sink);

        return $sink;
    }
}

final class EchoTool implements AgentTool
{
    public int $calls = 0;

    public function name(): string
    {
        return 'echo';
    }

    public function description(): string
    {
        return 'Renvoie son entrée.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['text' => ['type' => 'string']]];
    }

    public function execute(array $input, ToolContext $context): ToolResult
    {
        ++$this->calls;

        return ToolResult::json($input);
    }
}

final class OneShotLlm implements LlmClient
{
    /** @var list<array{messages: list<array<string, mixed>>}> */
    public array $calls = [];

    /** @param list<LlmResponse> $script */
    public function __construct(private array $script)
    {
    }

    public function complete(string $system, array $messages, array $tools, ?LlmStream $stream = null): LlmResponse
    {
        $this->calls[] = ['messages' => $messages];

        return array_shift($this->script) ?? new LlmResponse([['type' => 'text', 'text' => 'fin']], 'end_turn');
    }
}

/** Client HTTP factice dont le flux rejoue une suite de silences (`timeout`) et de morceaux (`data:…`). */
final class FakeStreamHttp implements \Symfony\Contracts\HttpClient\HttpClientInterface
{
    /** @param list<string> $script */
    public function __construct(private readonly array $script)
    {
    }

    public function request(string $method, string $url, array $options = []): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        return new MockResponse('');
    }

    public function stream(iterable|\Symfony\Contracts\HttpClient\ResponseInterface $responses, ?float $timeout = null): \Symfony\Contracts\HttpClient\ResponseStreamInterface
    {
        $response = $responses instanceof \Symfony\Contracts\HttpClient\ResponseInterface ? $responses : [...$responses][0];
        $chunks = array_map(static fn (string $s): \Symfony\Contracts\HttpClient\ChunkInterface => new FakeChunk($s), $this->script);

        return new class($response, $chunks) implements \Symfony\Contracts\HttpClient\ResponseStreamInterface {
            private int $i = 0;

            /** @param list<\Symfony\Contracts\HttpClient\ChunkInterface> $chunks */
            public function __construct(private readonly \Symfony\Contracts\HttpClient\ResponseInterface $response, private readonly array $chunks)
            {
            }

            public function key(): \Symfony\Contracts\HttpClient\ResponseInterface
            {
                return $this->response;
            }

            public function current(): \Symfony\Contracts\HttpClient\ChunkInterface
            {
                return $this->chunks[$this->i];
            }

            public function next(): void
            {
                ++$this->i;
            }

            public function rewind(): void
            {
                $this->i = 0;
            }

            public function valid(): bool
            {
                return $this->i < \count($this->chunks);
            }
        };
    }

    public function withOptions(array $options): static
    {
        return $this;
    }
}

final class FakeChunk implements \Symfony\Contracts\HttpClient\ChunkInterface
{
    public function __construct(private readonly string $spec)
    {
    }

    public function isTimeout(): bool
    {
        return $this->spec === 'timeout';
    }

    public function isFirst(): bool
    {
        return false;
    }

    public function isLast(): bool
    {
        return false;
    }

    public function getInformationalStatus(): ?array
    {
        return null;
    }

    public function getContent(): string
    {
        return $this->isTimeout() ? '' : substr($this->spec, 5);
    }

    public function getOffset(): int
    {
        return 0;
    }

    public function getError(): ?string
    {
        return null;
    }
}
