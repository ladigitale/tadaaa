<?php

declare(strict_types=1);

namespace App\Tests\Agent;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Agent\AgentRunner;
use App\Agent\AgUi\ArrayEventSink;
use App\Agent\Llm\AnthropicLlmClient;
use App\Agent\Llm\AnthropicStreamParser;
use App\Agent\Llm\LlmClient;
use App\Agent\Llm\LlmResponse;
use App\Agent\Llm\LlmStream;
use App\Agent\RunInput;
use App\Agent\ThreadState;
use App\Agent\ThreadStore;
use App\Agent\Tool\PreviewArtifactTool;
use App\Agent\Tool\PublishPreviewTool;
use App\Agent\Tool\Toolbox;
use App\Mcp\Tool\PublishArtifactTool;
use App\Mcp\Tool\UpdateArtifactTool;
use App\Service\ArtifactA2uiValidator;
use App\Service\ArtifactDocumentValidator;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Atelier : flux SSE du modèle, mémoire de conversation entre runs, réponses tronquées,
 * publication du dernier aperçu.
 */
final class AgentContinuityTest extends TestCase
{
    public function testStreamParserRebuildsTextAndToolCalls(): void
    {
        $texts = [];
        $tools = [];
        $parser = new AnthropicStreamParser(new LlmStream(
            static function (string $d) use (&$texts): void { $texts[] = $d; },
            static function (string $id, string $name) use (&$tools): void { $tools[] = "$id:$name"; },
        ));
        $sse = self::sse([
            ['type' => 'message_start', 'message' => ['id' => 'm']],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Je prépare ']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'le quiz.']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 't1', 'name' => 'preview_artifact', 'input' => []]],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"document":{"a":']],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{},"b":[1]}}']],
            ['type' => 'content_block_stop', 'index' => 1],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use']],
            ['type' => 'message_stop'],
        ]);
        // Morceaux coupés n'importe où.
        foreach (str_split($sse, 7) as $chunk) {
            $parser->push($chunk);
        }
        $response = $parser->finish();

        self::assertSame(['Je prépare ', 'le quiz.'], $texts);
        self::assertSame(['t1:preview_artifact'], $tools);
        self::assertSame('tool_use', $response->stopReason);
        self::assertSame('Je prépare le quiz.', $response->text());
        $use = $response->toolUses()[0];
        self::assertSame(['document' => ['a' => [], 'b' => [1]]], $use['input']);
        self::assertSame('{"document":{"a":{},"b":[1]}}', json_encode($use['raw']));
        self::assertStringContainsString('"input":{"document":{"a":{}', (string) json_encode($response->rawContent));
    }

    public function testStreamParserMarksTruncatedToolCalls(): void
    {
        $parser = new AnthropicStreamParser();
        $parser->push(self::sse([
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 't1', 'name' => 'preview_artifact', 'input' => []]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"document":{"views":[{"id"']],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'max_tokens']],
        ]));
        $response = $parser->finish();
        self::assertSame('max_tokens', $response->stopReason);
        self::assertSame([], $response->toolUses()[0]['input']);

        $this->expectException(\App\Agent\Llm\LlmUnavailable::class);
        (new AnthropicStreamParser())->push(self::sse([['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]]));
    }

    public function testAnthropicClientStreamsWithPromptCaching(): void
    {
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = json_decode($options['body'], true);

            return new MockResponse(str_split(self::sse([
                ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Bonjour']],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']],
            ]), 10), ['http_code' => 200, 'response_headers' => ['content-type' => 'text/event-stream']]);
        });
        $client = new AnthropicLlmClient($http, 'k', 'claude-test', 16000);
        $seen = '';
        $history = [['role' => 'user', 'content' => 'Salut']];
        $response = $client->complete('SYS', $history, [['name' => 'a', 'description' => 'd', 'input_schema' => ['type' => 'object']]], new LlmStream(
            static function (string $d) use (&$seen): void { $seen .= $d; },
        ));

        self::assertSame('Bonjour', $seen);
        self::assertSame('Bonjour', $response->text());
        $body = $requests[0];
        self::assertTrue($body['stream']);
        self::assertSame(16000, $body['max_tokens']);
        self::assertSame(['type' => 'ephemeral'], $body['system'][0]['cache_control']);
        self::assertSame([['type' => 'text', 'text' => 'Salut', 'cache_control' => ['type' => 'ephemeral']]], $body['messages'][0]['content']);
        // L'historique de l'agent n'est pas modifié.
        self::assertSame('Salut', $history[0]['content']);

        $failing = new AnthropicLlmClient(new MockHttpClient(new MockResponse('{"error":{"message":"invalid x-api-key"}}', ['http_code' => 401])), 'k', 'm');
        try {
            $failing->complete('S', $history, [], new LlmStream());
            self::assertTrue(false, 'exception attendue');
        } catch (\App\Agent\Llm\LlmUnavailable $e) {
            self::assertStringContainsString('401', $e->getMessage());
            self::assertStringContainsString('Vérifie la clé API', $e->getMessage());
        }
    }

    public function testRunnerRelaysTheStreamWithoutDuplicates(): void
    {
        $llm = new StreamingLlm([
            self::toolCall('t1', 'preview_artifact', ['document' => ['schema' => 'artifacts/0']], 'Je prépare.'),
            self::reply('Corrigé.'),
        ]);
        $sink = new ArrayEventSink();
        $this->runner($llm)->run(self::input([['role' => 'user', 'content' => 'un quiz']]), $sink);

        $types = $sink->types();
        self::assertSame(1, \count(array_keys($types, 'TOOL_CALL_START', true)));
        // Texte streamé avant l'appel d'outil, sans réémission en fin de tour.
        self::assertSame(['RUN_STARTED', 'TEXT_MESSAGE_START', 'TEXT_MESSAGE_CONTENT', 'TOOL_CALL_START', 'TEXT_MESSAGE_END', 'TOOL_CALL_ARGS', 'TOOL_CALL_END'], \array_slice($types, 0, 7));
        self::assertSame('RUN_FINISHED', $types[\count($types) - 1]);
    }

    public function testTruncatedToolCallIsNotExecuted(): void
    {
        $llm = new StreamingLlm([
            new LlmResponse([['type' => 'tool_use', 'id' => 't1', 'name' => 'preview_artifact', 'input' => []]], 'max_tokens'),
            self::reply('Je fais plus court.'),
        ]);
        $sink = new ArrayEventSink();
        $this->runner($llm)->run(self::input([['role' => 'user', 'content' => 'un quiz']]), $sink);

        self::assertCount(0, array_filter($sink->events, static fn (array $e): bool => ($e['name'] ?? null) === 'artifact-preview'));
        self::assertFalse(\in_array('TOOL_CALL_ARGS', $sink->types(), true));
        $result = $llm->calls[1]['messages'][2]['content'][0];
        self::assertTrue($result['is_error']);
        self::assertStringContainsString('limite de longueur', $result['content']);

        $cut = new StreamingLlm([new LlmResponse([['type' => 'text', 'text' => 'Voici le début']], 'max_tokens')]);
        $sink = new ArrayEventSink();
        $this->runner($cut)->run(self::input([['role' => 'user', 'content' => 'x']]), $sink);
        self::assertStringContainsString('Réponse coupée', (string) json_encode($sink->events, JSON_UNESCAPED_UNICODE));
    }

    public function testConversationMemoryAcrossRuns(): void
    {
        $threads = new ThreadStore(new ArrayAdapter());
        $processor = new RecordingProcessor();
        $doc = self::quiz();
        $llm = new StreamingLlm([
            // Run 1 : catalogue, aperçu, proposition.
            self::toolCall('c1', 'get_artifact_catalog', []),
            self::toolCall('p1', 'preview_artifact', ['document' => $doc]),
            self::reply('Aperçu prêt. Je publie ?'),
            // Run 2 (clic « Publier ») : publication du dernier aperçu, sans le document.
            self::toolCall('u1', 'publish_preview', ['title' => 'Quiz']),
            self::reply('Publié.'),
            // Run 3 : modification puis nouvelle version du même artefact.
            self::toolCall('p2', 'preview_artifact', ['document' => ['title' => 'Quiz v2'] + $doc]),
            self::toolCall('u2', 'publish_preview', ['note' => 'v2']),
            self::reply('Mis à jour.'),
        ]);
        $runner = $this->runner($llm, $threads, $processor, withCatalog: true);

        $history = [['role' => 'user', 'content' => 'un quiz sur la Loire']];
        $runner->run(self::input($history), new ArrayEventSink());
        self::assertCount(3, $llm->calls);

        // Le navigateur renvoie les textes + l'action « Publier » (pas de nouveau message utilisateur).
        $history[] = ['role' => 'assistant', 'content' => 'Aperçu prêt. Je publie ?'];
        $sink = new ArrayEventSink();
        $runner->run(self::input($history, ['a2uiAction' => ['version' => 'v0.9', 'action' => ['name' => 'publish', 'surfaceId' => 's', 'sourceComponentId' => 'b', 'context' => []]]]), $sink);

        $sent = $llm->calls[3]['messages'];
        $json = (string) json_encode($sent, JSON_UNESCAPED_UNICODE);
        // La transcription du run 1 est là : catalogue lu, aperçu composé.
        self::assertStringContainsString('"name":"get_artifact_catalog"', $json);
        self::assertStringContainsString('"name":"preview_artifact"', $json);
        self::assertSame('user', $sent[\count($sent) - 1]['role']);
        self::assertStringContainsString('[action] « publish »', $sent[\count($sent) - 1]['content']);
        // Publié sans que le modèle réécrive le document, objets vides compris.
        self::assertInstanceOf(PublishArtifactTool::class, $processor->calls[0]['dto']);
        self::assertSame('Quiz', $processor->calls[0]['dto']->title);
        self::assertSame($doc, $processor->calls[0]['dto']->document);
        self::assertStringContainsString('"answered":{}', (string) json_encode($processor->calls[0]['context']['raw_arguments']));
        $published = array_values(array_filter($sink->events, static fn (array $e): bool => ($e['name'] ?? null) === 'artifact-published'));
        self::assertSame('quiz-loire', $published[0]['value']['slug']);

        $history[] = ['role' => 'assistant', 'content' => 'Publié.'];
        $history[] = ['role' => 'user', 'content' => 'change le titre'];
        $runner->run(self::input($history), new ArrayEventSink());
        self::assertInstanceOf(UpdateArtifactTool::class, $processor->calls[1]['dto']);
        self::assertSame('quiz-loire', $processor->calls[1]['dto']->slug);
        self::assertSame('Quiz v2', $processor->calls[1]['dto']->document['title']);

        // Autre conversation : rien à publier.
        $other = new StreamingLlm([self::toolCall('x', 'publish_preview', ['title' => 'Q']), self::reply('ok')]);
        $this->runner($other, $threads, $processor)->run(RunInput::fromArray(['threadId' => 'autre', 'runId' => 'r', 'messages' => [['role' => 'user', 'content' => 'publie']]]), new ArrayEventSink());
        self::assertStringContainsString('Aucun aperçu valide', $other->calls[1]['messages'][2]['content'][0]['content']);
    }

    public function testMemoryIsIgnoredWhenTheHistoryDoesNotFollow(): void
    {
        $threads = new ThreadStore(new ArrayAdapter());
        $threads->save('scope', 'thread', new ThreadState([['role' => 'user', 'content' => 'ancien'], ['role' => 'assistant', 'content' => 'ok']], 5));
        $llm = new StreamingLlm([self::reply('Bonjour')]);
        $this->runner($llm, $threads)->run(self::input([['role' => 'user', 'content' => 'nouveau']]), new ArrayEventSink());
        self::assertSame([['role' => 'user', 'content' => 'nouveau']], $llm->calls[0]['messages']);
    }

    public function testEditByPatchReadAndPublish(): void
    {
        $processor = new RecordingProcessor();
        $doc = self::quiz();
        $llm = new StreamingLlm([
            self::toolCall('p1', 'preview_artifact', ['document' => $doc]),
            self::reply('Aperçu prêt.'),
            // Run 2 : lecture ciblée, patch invalide (refusé), patch valide, publication.
            self::toolCall('r1', 'read_preview', []),
            self::toolCall('r2', 'read_preview', ['path' => '/views/0/title']),
            self::toolCall('e1', 'edit_preview', ['ops' => [['op' => 'remove', 'path' => '/views/0']]]),
            self::toolCall('e2', 'edit_preview', ['ops' => [
                ['op' => 'replace', 'path' => '/title', 'value' => 'Quiz de la Loire'],
                ['op' => 'add', 'path' => '/data/stores/quiz/initial/answered2', 'value' => new \stdClass()],
            ]]),
            self::toolCall('u1', 'publish_preview', ['title' => 'Quiz de la Loire']),
            self::reply('Publié.'),
        ]);
        $runner = $this->runner($llm, null, $processor);
        $history = [['role' => 'user', 'content' => 'un quiz']];
        $runner->run(self::input($history), new ArrayEventSink());
        $history[] = ['role' => 'assistant', 'content' => 'Aperçu prêt.'];
        $history[] = ['role' => 'user', 'content' => 'change le titre et publie'];
        $sink = new ArrayEventSink();
        $runner->run(self::input($history), $sink);

        $results = [];
        foreach ($llm->calls as $call) {
            $last = $call['messages'][\count($call['messages']) - 1];
            if (\is_array($last['content']) && ($last['content'][0]['type'] ?? null) === 'tool_result') {
                $results[$last['content'][0]['tool_use_id']] = $last['content'][0];
            }
        }
        self::assertStringContainsString('"views":[{"id":"q","title":"Quiz","a2ui":["{…', $results['r1']['content']);
        self::assertSame('"Quiz"', $results['r2']['content']);
        self::assertTrue($results['e1']['is_error'], $results['e1']['content']);
        self::assertFalse($results['e2']['is_error'], $results['e2']['content']);

        // Aperçu réémis après le patch, objets vides du document et du patch compris.
        $previews = array_values(array_filter($sink->events, static fn (array $e): bool => ($e['name'] ?? null) === 'artifact-preview'));
        self::assertCount(1, $previews);
        $json = (string) json_encode($previews[0]['value']['document']);
        self::assertStringContainsString('"title":"Quiz de la Loire"', $json);
        self::assertStringContainsString('"answered":{}', $json);
        self::assertStringContainsString('"answered2":{}', $json);
        // Publication : le document patché.
        $dto = $processor->calls[0]['dto'];
        self::assertInstanceOf(PublishArtifactTool::class, $dto);
        self::assertSame('Quiz de la Loire', $dto->document['title']);
        self::assertSame(\count($doc['views']), \count($dto->document['views']));

        // Mémoire : le document complet du run 1 n'est plus relu au run 2.
        $sent = (string) json_encode($llm->calls[2]['messages'], JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('document retiré de la mémoire', $sent);
        self::assertStringNotContainsString('createSurface', $sent);
    }

    public function testDraftStartsFromTheEditedArtifact(): void
    {
        $processor = new RecordingProcessor();
        $processor->stored = (string) json_encode(['title' => 'Existant', 'data' => ['x' => new \stdClass()]] + self::quiz());
        $llm = new StreamingLlm([
            self::toolCall('e1', 'edit_preview', ['ops' => [['op' => 'replace', 'path' => '/title', 'value' => 'Modifié']]]),
            self::toolCall('u1', 'publish_preview', ['note' => 'titre']),
            self::reply('Fait.'),
        ]);
        $input = RunInput::fromArray(['threadId' => 'edit', 'runId' => 'r', 'messages' => [['role' => 'user', 'content' => 'renomme']], 'forwardedProps' => ['artifact' => ['slug' => 'quiz-loire']]]);
        $this->runner($llm, null, $processor)->run($input, new ArrayEventSink());

        self::assertInstanceOf(\App\Mcp\Tool\GetArtifactTool::class, $processor->calls[0]['dto']);
        $update = $processor->calls[1]['dto'];
        self::assertInstanceOf(UpdateArtifactTool::class, $update);
        self::assertSame('quiz-loire', $update->slug);
        self::assertSame('Modifié', $update->document['title']);
        self::assertStringContainsString('"x":{}', (string) json_encode($processor->calls[1]['context']['raw_arguments']));
    }

    public function testStartFromKitThenAdjust(): void
    {
        $processor = new RecordingProcessor();
        $llm = new StreamingLlm([
            self::toolCall('k0', 'start_from_kit', ['kit' => 'quiz', 'params' => ['title' => 'Q']]),
            self::toolCall('k1', 'start_from_kit', ['kit' => 'quiz', 'params' => ['title' => 'Capitales', 'timer' => 10, 'speed' => true,
                'questions' => [['question' => 'Capitale du Japon ?', 'answers' => ['Kyoto', 'Tokyo'], 'correct' => 1]]]]),
            self::toolCall('e1', 'edit_preview', ['ops' => [['op' => 'replace', 'path' => '/views/0/title', 'value' => 'Jouer']]]),
            self::toolCall('u1', 'publish_preview', []),
            self::reply('Publié.'),
        ]);
        $sink = new ArrayEventSink();
        $this->runner($llm, null, $processor)->run(self::input([['role' => 'user', 'content' => 'un quiz de vitesse sur les capitales']]), $sink);

        $results = [];
        foreach ($llm->calls as $call) {
            $last = $call['messages'][\count($call['messages']) - 1];
            if (\is_array($last['content']) && ($last['content'][0]['type'] ?? null) === 'tool_result') {
                $results[$last['content'][0]['tool_use_id']] = $last['content'][0];
            }
        }
        self::assertTrue($results['k0']['is_error']);
        self::assertStringContainsString('params.questions : requis.', $results['k0']['content']);
        self::assertFalse($results['k1']['is_error'], $results['k1']['content']);
        self::assertFalse($results['e1']['is_error'], $results['e1']['content']);

        $previews = array_values(array_filter($sink->events, static fn (array $e): bool => ($e['name'] ?? null) === 'artifact-preview'));
        self::assertCount(2, $previews);
        $doc = $processor->calls[0]['dto']->document;
        self::assertSame('Capitales', $doc['title']);
        self::assertSame('Jouer', $doc['views'][0]['title']);
        self::assertStringContainsString('sonic-ticker', (string) json_encode($doc));
        // Le modèle n'a écrit que des paramètres : rien de comparable à un document entier.
        self::assertLessThan(400, \strlen((string) json_encode($llm->calls[1]['messages'][1]['content'])));
    }

    public function testLargeTranscriptsAreCompacted(): void
    {
        $state = new ThreadState([
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'c', 'content' => str_repeat('x', 5000)]]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'd', 'content' => 'court']]],
        ], 1, ['preview' => ['document' => []]]);
        $compact = $state->compacted();
        self::assertStringContainsString('retiré de la mémoire', $compact->messages[0]['content'][0]['content']);
        self::assertSame('court', $compact->messages[1]['content'][0]['content']);
        self::assertSame($state->workspace, $compact->workspace);
    }

    private function runner(StreamingLlm $llm, ?ThreadStore $threads = null, ?RecordingProcessor $processor = null, bool $withCatalog = false): AgentRunner
    {
        $api = __DIR__.'/../../config/artifacts';
        $validator = new ArtifactDocumentValidator($api.'/catalog.json', $api.'/sdui.schema.json', new \App\Service\ArtifactScriptsCatalog($api.'/scripts-catalog.json'));
        $processor ??= new RecordingProcessor();
        $drafts = new \App\Agent\Tool\DraftStore($processor);
        $preview = new PreviewArtifactTool($validator, $drafts);
        $tools = [
            $preview,
            new \App\Agent\Tool\EditPreviewTool($preview, $drafts),
            new \App\Agent\Tool\ReadPreviewTool($drafts),
            new PublishPreviewTool($processor, $drafts),
            new \App\Agent\Tool\StartFromKitTool(new \App\Service\ArtifactKits(__DIR__.'/../../config/artifacts/kits'), $preview),
        ];
        if ($withCatalog) {
            $tools[] = new StaticTool('get_artifact_catalog', '{"catalog":"…"}');
        }

        return new AgentRunner($llm, new Toolbox($tools), threads: $threads ?? new ThreadStore(new ArrayAdapter()), threadScope: 'scope');
    }

    /** @return array<string, mixed> */
    private static function quiz(): array
    {
        return [
            'schema' => 'artifacts/1', 'title' => 'Quiz', 'defaultView' => 'q',
            'data' => ['stores' => ['quiz' => ['initial' => ['score' => 0, 'answered' => []], 'reducer' => '$']]],
            'views' => [['id' => 'q', 'title' => 'Quiz', 'a2ui' => [
                ['version' => 'v0.9', 'createSurface' => ['surfaceId' => 's', 'catalogId' => ArtifactA2uiValidator::BASIC_CATALOG_ID]],
                ['version' => 'v0.9', 'updateComponents' => ['surfaceId' => 's', 'components' => [['id' => 'root', 'component' => 'Text', 'text' => 'Q1']]]],
            ]]],
        ];
    }

    /** @param array<string, mixed> $input */
    private static function toolCall(string $id, string $name, array $input, string $text = ''): LlmResponse
    {
        $content = $text !== '' ? [['type' => 'text', 'text' => $text]] : [];
        $content[] = ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input];
        // Arguments bruts tels qu'un vrai modèle les écrirait : `answered*` sont des objets vides.
        $raw = json_decode((string) preg_replace('/"(answered\d*)":\[\]/', '"$1":{}', (string) json_encode((object) $input)), false);

        return new LlmResponse($content, 'tool_use', [$id => $raw]);
    }

    private static function reply(string $text): LlmResponse
    {
        return new LlmResponse([['type' => 'text', 'text' => $text]], 'end_turn');
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed>                       $forwarded
     */
    private static function input(array $messages, array $forwarded = []): RunInput
    {
        return RunInput::fromArray(['threadId' => 'thread', 'runId' => 'run-'.\count($messages), 'messages' => $messages, 'forwardedProps' => $forwarded]);
    }

    /** @param list<array<string, mixed>> $events */
    private static function sse(array $events): string
    {
        return implode('', array_map(static fn (array $e): string => 'event: '.$e['type']."\ndata: ".json_encode($e)."\n\n", $events));
    }

    private static function validatorArgs(): array
    {
        $dir = sys_get_temp_dir().'/agent-test-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/scripts.json', json_encode(['maxScriptsPerDocument' => 0, 'cdnAllowlist' => [], 'libraries' => []]));

        return [$dir.'/catalog.json', $dir.'/schema.json', new \App\Service\ArtifactScriptsCatalog($dir.'/scripts.json'), ['components' => [], 'safeHtmlTags' => []]];
    }
}

/** LLM scripté qui « streame » : texte et débuts d'appels d'outils passent par le LlmStream. */
final class StreamingLlm implements LlmClient
{
    /** @var list<array{system: string, messages: list<array<string, mixed>>}> */
    public array $calls = [];

    /** @param list<LlmResponse> $script */
    public function __construct(private array $script)
    {
    }

    public function complete(string $system, array $messages, array $tools, ?LlmStream $stream = null): LlmResponse
    {
        $this->calls[] = ['system' => $system, 'messages' => json_decode((string) json_encode($messages), true)];
        $next = array_shift($this->script) ?? new LlmResponse([['type' => 'text', 'text' => '(fin)']], 'end_turn');
        foreach ($next->content as $block) {
            if ($block['type'] === 'text') {
                $stream?->text($block['text']);
            } elseif ($block['type'] === 'tool_use') {
                $stream?->toolStart($block['id'], $block['name']);
            }
        }

        return $next;
    }
}

final class RecordingProcessor implements ProcessorInterface
{
    /** @var list<array{dto: object, context: array<string, mixed>}> */
    public array $calls = [];

    /** Document renvoyé par get_artifact (JSON brut, objets vides compris). */
    public string $stored = '{}';

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $this->calls[] = ['dto' => $data, 'context' => $context];
        if ($data instanceof \App\Mcp\Tool\GetArtifactTool) {
            return new CallToolResult([new TextContent('{"slug":"'.$data->slug.'","document":'.$this->stored.'}')]);
        }
        $slug = $data instanceof UpdateArtifactTool ? $data->slug : 'quiz-loire';

        return new CallToolResult([new TextContent(['id' => 'a1', 'slug' => $slug, 'url' => 'https://artifacts.test/'.$slug, 'version' => \count($this->calls)])]);
    }
}

final class StaticTool implements \App\Agent\Tool\AgentTool
{
    public function __construct(private readonly string $toolName, private readonly string $result)
    {
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return 'test';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object'];
    }

    public function execute(array $input, \App\Agent\Tool\ToolContext $context): \App\Agent\Tool\ToolResult
    {
        return new \App\Agent\Tool\ToolResult($this->result, false);
    }
}
