<?php

declare(strict_types=1);

namespace App\Tests\Agent;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Agent\AgentRunner;
use App\Agent\AgUi\ArrayEventSink;
use App\Agent\AgUi\SseEventSink;
use App\Agent\Llm\LlmClient;
use App\Agent\Llm\LlmResponse;
use App\Agent\Llm\LlmUnavailable;
use App\Agent\RunInput;
use App\Agent\Tool\McpToolAdapter;
use App\Agent\Tool\RenderUiTool;
use App\Agent\Tool\Toolbox;
use App\Mcp\Tool\CreateTodoTool;
use App\Mcp\Tool\ListTodosTool;
use App\Service\ArtifactA2uiValidator;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\TestCase;

final class AgentRunnerTest extends TestCase
{
    public function testTextOnlyReply(): void
    {
        $llm = new ScriptedLlm([self::text('Bonjour !')]);
        $sink = $this->run($llm, self::input([['role' => 'user', 'content' => 'Salut']]));

        self::assertSame(['RUN_STARTED', 'TEXT_MESSAGE_START', 'TEXT_MESSAGE_CONTENT', 'TEXT_MESSAGE_END', 'RUN_FINISHED'], $sink->types());
        self::assertSame('Bonjour !', $sink->events[2]['delta']);
        self::assertStringContainsString('ChoicePicker', $llm->calls[0]['system']);
        self::assertSame(['render_ui', 'list_todos'], array_column($llm->calls[0]['tools'], 'name'));
    }

    public function testToolCallThenAnswer(): void
    {
        $llm = new ScriptedLlm([
            self::toolUse('t1', 'list_todos', ['status' => 'open']),
            self::text('Tu as 2 tâches ouvertes.'),
        ]);
        $sink = $this->run($llm, self::input([['role' => 'user', 'content' => 'Mes tâches ?']]));

        self::assertSame(
            ['RUN_STARTED', 'TOOL_CALL_START', 'TOOL_CALL_ARGS', 'TOOL_CALL_END', 'TEXT_MESSAGE_START', 'TEXT_MESSAGE_CONTENT', 'TEXT_MESSAGE_END', 'RUN_FINISHED'],
            $sink->types(),
        );
        self::assertSame('list_todos', $sink->events[1]['toolCallName']);
        $second = $llm->calls[1]['messages'];
        $toolResult = $second[\count($second) - 1]['content'][0];
        self::assertSame('tool_result', $toolResult['type']);
        self::assertSame('t1', $toolResult['tool_use_id']);
        self::assertStringContainsString('"status":"open"', $toolResult['content']);
    }

    public function testRenderUiEmitsA2ui(): void
    {
        $llm = new ScriptedLlm([
            self::toolUse('u1', 'render_ui', [
                'components' => [
                    ['id' => 'root', 'component' => 'List', 'children' => ['path' => '/todos', 'componentId' => 'item']],
                    ['id' => 'item', 'component' => 'Text', 'text' => ['path' => 'text']],
                ],
                'data' => ['todos' => [['text' => 'Acheter du pain']]],
            ]),
            self::text('Voilà.'),
        ]);
        $sink = $this->run($llm, self::input([['role' => 'user', 'content' => 'Affiche-les']]));

        $custom = array_values(array_filter($sink->events, static fn ($e) => $e['type'] === 'CUSTOM'));
        self::assertCount(1, $custom);
        self::assertSame('a2ui', $custom[0]['name']);
        self::assertSame(['createSurface', 'updateComponents', 'updateDataModel'], array_map(
            static fn (array $m) => array_keys($m)[1],
            $custom[0]['value'],
        ));
        $result = json_decode($llm->calls[1]['messages'][2]['content'][0]['content'], true);
        self::assertStringContainsString('ui-', $result['surfaceId']);
        self::assertFalse($llm->calls[1]['messages'][2]['content'][0]['is_error']);
    }

    public function testInvalidUiGoesBackToTheModel(): void
    {
        $llm = new ScriptedLlm([
            self::toolUse('u1', 'render_ui', ['components' => [['id' => 'root', 'component' => 'Video', 'url' => 'https://x/v.mp4']]]),
            self::toolUse('u2', 'render_ui', ['components' => [['id' => 'root', 'component' => 'Text', 'text' => 'ok']]]),
            self::text('Corrigé.'),
        ]);
        $sink = $this->run($llm, self::input([['role' => 'user', 'content' => 'Vidéo ?']]));

        $first = $llm->calls[1]['messages'][2]['content'][0];
        self::assertTrue($first['is_error']);
        self::assertStringContainsString('Video', $first['content']);
        self::assertCount(1, array_filter($sink->events, static fn ($e) => $e['type'] === 'CUSTOM'));
    }

    public function testUpdatingAnExistingSurface(): void
    {
        $llm = new ScriptedLlm([
            self::toolUse('u1', 'render_ui', ['surfaceId' => 'ui-abc-1', 'components' => [['id' => 'root', 'component' => 'Text', 'text' => 'mis à jour']]]),
            self::text('ok'),
        ]);
        $sink = $this->run($llm, self::input([['role' => 'user', 'content' => 'Mets à jour']]));
        $custom = array_values(array_filter($sink->events, static fn ($e) => $e['type'] === 'CUSTOM'));
        self::assertSame(['updateComponents'], array_map(static fn (array $m) => array_keys($m)[1], $custom[0]['value']));
    }

    public function testUiActionBecomesAUserTurn(): void
    {
        $llm = new ScriptedLlm([self::text('Tâche cochée.')]);
        $input = RunInput::fromArray([
            'threadId' => 't', 'runId' => 'r2',
            'messages' => [
                ['id' => '1', 'role' => 'user', 'content' => 'Mes tâches'],
                ['id' => '2', 'role' => 'assistant', 'content' => 'Les voici.'],
            ],
            'forwardedProps' => [
                'a2uiAction' => ['version' => 'v0.9', 'action' => ['name' => 'done', 'surfaceId' => 'ui-x-1', 'sourceComponentId' => 'check@root/0', 'context' => ['id' => 'abc']]],
                'a2uiErrors' => [['code' => 'UNSUPPORTED_COMPONENT', 'surfaceId' => 'ui-x-1', 'message' => 'oups']],
            ],
        ]);
        $this->run($llm, $input);

        $messages = $llm->calls[0]['messages'];
        self::assertSame(['user', 'assistant', 'user'], array_column($messages, 'role'));
        self::assertStringContainsString('[action] « done »', $messages[2]['content']);
        self::assertStringContainsString('"id":"abc"', $messages[2]['content']);
        self::assertStringContainsString('[erreurs d’interface]', $messages[2]['content']);
    }

    public function testModelUnavailable(): void
    {
        $llm = new ScriptedLlm([new LlmUnavailable('Agent non configuré.')]);
        $sink = $this->run($llm, self::input([['role' => 'user', 'content' => 'Salut']]));
        self::assertSame(['RUN_STARTED', 'RUN_ERROR'], $sink->types());
        self::assertSame('Agent non configuré.', $sink->events[1]['message']);
    }

    public function testStepLimit(): void
    {
        $llm = new ScriptedLlm(array_fill(0, AgentRunner::MAX_STEPS + 2, self::toolUse('t', 'list_todos', [])));
        $sink = $this->run($llm, self::input([['role' => 'user', 'content' => 'boucle']]));
        self::assertCount(AgentRunner::MAX_STEPS, $llm->calls);
        self::assertSame('RUN_FINISHED', $sink->types()[\count($sink->events) - 1]);
        self::assertStringContainsString('trop d’étapes', $sink->events[\count($sink->events) - 3]['delta']);
    }

    public function testNothingNewToAnswer(): void
    {
        $llm = new ScriptedLlm([]);
        $sink = $this->run($llm, self::input([['role' => 'assistant', 'content' => 'seul']]));
        self::assertSame(['RUN_STARTED', 'RUN_ERROR'], $sink->types());
        self::assertCount(0, $llm->calls);
    }

    public function testMcpToolSchemaAndExecution(): void
    {
        $processor = new FakeProcessor();
        $tool = new McpToolAdapter(CreateTodoTool::class, $processor);
        self::assertSame('create_todo', $tool->name());
        $schema = json_decode(json_encode($tool->inputSchema()), true);
        self::assertSame('string', $schema['properties']['text']['type']);
        self::assertSame(['type' => 'array', 'items' => ['type' => 'string']], $schema['properties']['tagIds']);
        self::assertSame('medium', $schema['properties']['priority']['default']);

        $context = new \App\Agent\Tool\ToolContext(new ArrayEventSink(), 'r');
        $result = $tool->execute(['text' => 'Pain', 'unknown' => 1], $context);
        self::assertFalse($result->isError);
        self::assertInstanceOf(CreateTodoTool::class, $processor->last);
        self::assertSame('Pain', $processor->last->text);

        $bad = $tool->execute(['text' => ['pas', 'une', 'chaîne']], $context);
        self::assertTrue($bad->isError);
    }

    public function testRunInputValidation(): void
    {
        $input = RunInput::fromArray([
            'threadId' => 't', 'runId' => 'r',
            'messages' => [
                ['id' => '1', 'role' => 'system', 'content' => 'ignore-moi'],
                ['id' => '2', 'role' => 'user', 'content' => '  '],
                ['id' => '3', 'role' => 'user', 'content' => 'ok'],
            ],
        ]);
        self::assertSame([['role' => 'user', 'content' => 'ok']], $input->messages);

        $this->expectException(\InvalidArgumentException::class);
        RunInput::fromArray(['messages' => []]);
    }

    public function testSseFraming(): void
    {
        $out = '';
        $sink = new SseEventSink(static function (string $chunk) use (&$out): void {
            $out .= $chunk;
        });
        $sink->emit(['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'm', 'delta' => 'é']);
        self::assertStringContainsString('data: {"type":"TEXT_MESSAGE_CONTENT","messageId":"m","delta":"é"', $out);
        self::assertSame("\n\n", substr($out, -2));
    }

    private function run(ScriptedLlm $llm, RunInput $input): ArrayEventSink
    {
        $toolbox = new Toolbox([
            new RenderUiTool(new ArtifactA2uiValidator(...self::validatorArgs())),
            new McpToolAdapter(ListTodosTool::class, new FakeProcessor()),
        ]);
        $sink = new ArrayEventSink();
        (new AgentRunner($llm, $toolbox, clock: static fn () => new \DateTimeImmutable('2026-10-08')))->run($input, $sink);

        return $sink;
    }

    /** @return array{0: string, 1: string, 2: \App\Service\ArtifactScriptsCatalog, 3: array<string, mixed>} */
    private static function validatorArgs(): array
    {
        $dir = sys_get_temp_dir().'/agent-test-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/scripts.json', json_encode(['maxScriptsPerDocument' => 0, 'cdnAllowlist' => [], 'libraries' => []]));

        return [$dir.'/catalog.json', $dir.'/schema.json', new \App\Service\ArtifactScriptsCatalog($dir.'/scripts.json'), ['components' => [], 'safeHtmlTags' => []]];
    }

    /** @param list<array{role: string, content: string}> $messages */
    private static function input(array $messages): RunInput
    {
        return RunInput::fromArray(['threadId' => 'thread', 'runId' => 'run-1', 'messages' => $messages]);
    }

    private static function text(string $text): LlmResponse
    {
        return new LlmResponse([['type' => 'text', 'text' => $text]], 'end_turn');
    }

    /** @param array<string, mixed> $input */
    private static function toolUse(string $id, string $name, array $input): LlmResponse
    {
        return new LlmResponse([['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input]], 'tool_use');
    }
}

/** LLM scripté : renvoie (ou lève) les réponses dans l'ordre et garde les appels. */
final class ScriptedLlm implements LlmClient
{
    /** @var list<array{system: string, messages: list<array<string, mixed>>, tools: list<array<string, mixed>>}> */
    public array $calls = [];

    /** @param list<LlmResponse|\Throwable> $script */
    public function __construct(private array $script)
    {
    }

    public function complete(string $system, array $messages, array $tools): LlmResponse
    {
        $this->calls[] = ['system' => $system, 'messages' => $messages, 'tools' => $tools];
        $next = array_shift($this->script) ?? new LlmResponse([['type' => 'text', 'text' => '(fin du script)']], 'end_turn');
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}

/** Processor MCP factice : renvoie l'entrée reçue. */
final class FakeProcessor implements ProcessorInterface
{
    public ?object $last = null;

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $this->last = $data;

        return new CallToolResult([new TextContent(json_encode(get_object_vars($data)))]);
    }
}
