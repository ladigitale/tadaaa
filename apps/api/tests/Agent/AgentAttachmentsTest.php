<?php

declare(strict_types=1);

namespace App\Tests\Agent;

use App\Agent\AgentRunner;
use App\Agent\AgUi\ArrayEventSink;
use App\Agent\Llm\LlmResponse;
use App\Agent\Llm\OpenAiLlmClient;
use App\Agent\PastedTextCompactor;
use App\Agent\RunInput;
use App\Agent\Tool\Toolbox;
use App\Agent\UserContent;
use PHPUnit\Framework\TestCase;

final class AgentAttachmentsTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private static function image(): array
    {
        return ['type' => 'image', 'source' => ['type' => 'data', 'value' => self::PNG, 'mimeType' => 'image/png'], 'metadata' => ['filename' => 'pixel.png']];
    }

    private static function pastedPart(string $text, string $name = 'Texte collé'): array
    {
        return ['type' => 'document', 'source' => ['type' => 'data', 'value' => base64_encode($text), 'mimeType' => 'text/plain'], 'metadata' => ['filename' => $name, 'pasted' => true]];
    }

    public function testParsesMultimodalUserMessage(): void
    {
        $input = RunInput::fromArray(['threadId' => 't', 'runId' => 'r', 'messages' => [
            ['id' => 'm1', 'role' => 'user', 'content' => [['type' => 'text', 'text' => 'Que vois-tu ?'], self::image()]],
        ]]);
        $content = $input->messages[0]['content'];
        self::assertIsArray($content);
        self::assertSame('text', $content[0]['type']);
        self::assertSame('[image jointe : pixel.png]', $content[1]['text']);
        self::assertSame(['type' => 'base64', 'media_type' => 'image/png', 'data' => self::PNG], $content[2]['source']);
        self::assertSame('Que vois-tu ?', $input->lastUserText);
        self::assertSame([['kind' => 'image', 'name' => 'pixel.png', 'mimeType' => 'image/png', 'size' => 70]], $input->lastUserAttachments);
    }

    public function testRejectsFakeImagesUrlsAndUnknownFormats(): void
    {
        $parsed = UserContent::parse([
            ['type' => 'image', 'source' => ['type' => 'data', 'value' => base64_encode('pas une image'), 'mimeType' => 'image/png'], 'metadata' => ['filename' => 'faux.png']],
            ['type' => 'image', 'source' => ['type' => 'url', 'value' => 'https://example.com/x.png'], 'metadata' => ['filename' => 'lien.png']],
            ['type' => 'document', 'source' => ['type' => 'data', 'value' => base64_encode('PK'), 'mimeType' => 'application/zip'], 'metadata' => ['filename' => 'a.zip']],
        ]);
        $texts = implode(' | ', array_column(\is_array($parsed['content']) ? $parsed['content'] : [['text' => $parsed['content']]], 'text'));
        self::assertStringContainsString('faux.png', $texts);
        self::assertStringContainsString('pas les liens', $texts);
        self::assertStringContainsString('application/zip', $texts);
        self::assertSame([], $parsed['attachments']);
        self::assertIsString($parsed['content']); // que du texte : chaîne
    }

    public function testShortPasteIsInlinedAndLongPasteSummarized(): void
    {
        $short = "ligne 1\nligne 2";
        $long = str_repeat("Une ligne de données assez longue pour dépasser la limite.\n", 800);
        $llm = new ScriptedLlm([
            new LlmResponse([['type' => 'text', 'text' => 'RÉSUMÉ FIDÈLE']], 'end_turn'),
            new LlmResponse([['type' => 'text', 'text' => 'Bien reçu.']], 'end_turn'),
        ]);
        $input = RunInput::fromArray(['threadId' => 't', 'runId' => 'r', 'messages' => [
            ['id' => 'm1', 'role' => 'user', 'content' => [['type' => 'text', 'text' => 'Voici'], self::pastedPart($short, 'court.txt'), self::pastedPart($long)]],
        ]]);
        $sink = new ArrayEventSink();
        (new AgentRunner($llm, new Toolbox([]), clock: static fn () => new \DateTimeImmutable('2026-11-12')))->run($input, $sink);

        self::assertCount(2, $llm->calls);
        self::assertStringContainsString('Texte à résumer', $llm->calls[0]['messages'][0]['content']);
        $sent = $llm->calls[1]['messages'][0]['content'];
        self::assertStringContainsString("ligne 1\nligne 2", $sent[1]['text']);
        self::assertStringContainsString('RÉSUMÉ FIDÈLE', $sent[2]['text']);
        self::assertStringContainsString('trop long pour être lu en entier', $sent[2]['text']);
        $status = array_values(array_filter($sink->events, static fn (array $e): bool => ($e['name'] ?? null) === 'status'));
        self::assertStringContainsString('Résumé de « Texte collé »', $status[0]['value']);
    }

    public function testSqueezeKeepsHeadAndTail(): void
    {
        $text = str_repeat('a', 100).str_repeat('z', 100);
        $out = PastedTextCompactor::squeeze($text, 40);
        self::assertStringStartsWith(str_repeat('a', 30), $out);
        self::assertStringEndsWith(str_repeat('z', 10), $out);
        self::assertStringContainsString('160 caractères retirés', $out);
    }

    public function testVeryLongTypedMessageBecomesPastedText(): void
    {
        $parsed = UserContent::parse(str_repeat('x', UserContent::MAX_TEXT + 10));
        self::assertSame('pasted_text', $parsed['content'][0]['type']);
        self::assertSame('Message long', $parsed['attachments'][0]['name']);
    }

    public function testThreadMemoryDropsBinaries(): void
    {
        $messages = UserContent::withoutBinaries([
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Regarde'],
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => self::PNG]],
                ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => 'JVBERg=='], 'title' => 'devis.pdf'],
            ]],
        ]);
        self::assertSame('text', $messages[0]['content'][1]['type']);
        self::assertStringContainsString('devis.pdf', $messages[0]['content'][2]['text']);
        self::assertStringNotContainsString(self::PNG, (string) json_encode($messages));
    }

    public function testOpenAiConversionOfImagesAndPdf(): void
    {
        $out = OpenAiLlmClient::toOpenAiMessages('sys', [['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'Regarde'],
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => self::PNG]],
            ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => 'JVBERg=='], 'title' => 'devis.pdf'],
        ]]]);
        $parts = $out[1]['content'];
        self::assertSame('user', $out[1]['role']);
        self::assertSame(['type' => 'text', 'text' => 'Regarde'], $parts[0]);
        self::assertSame('data:image/png;base64,'.self::PNG, $parts[1]['image_url']['url']);
        self::assertSame(['filename' => 'devis.pdf', 'file_data' => 'data:application/pdf;base64,JVBERg=='], $parts[2]['file']);
    }
}
