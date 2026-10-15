<?php

declare(strict_types=1);

namespace App\Tests\Agent;

use App\Agent\AgUi\ArrayEventSink;
use App\Agent\AgUi\RecordingEventSink;
use App\Agent\ConversationHistory;
use App\Agent\ThreadState;
use PHPUnit\Framework\TestCase;

final class ConversationHistoryTest extends TestCase
{
    public function testRecorderKeepsACompactReplayableLog(): void
    {
        $inner = new ArrayEventSink();
        $rec = new RecordingEventSink($inner);
        $rec->user('Fais un quiz');
        foreach ([
            ['type' => 'RUN_STARTED', 'threadId' => 't', 'runId' => 'r'],
            ['type' => 'TEXT_MESSAGE_START', 'messageId' => 'm1', 'role' => 'assistant'],
            ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'm1', 'delta' => 'Je '],
            ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'm1', 'delta' => 'prépare.'],
            ['type' => 'TEXT_MESSAGE_END', 'messageId' => 'm1'],
            ['type' => 'TOOL_CALL_START', 'toolCallId' => 'c1', 'toolCallName' => 'preview_artifact'],
            ['type' => 'TOOL_CALL_ARGS', 'toolCallId' => 'c1', 'delta' => str_repeat('x', 100000)],
            ['type' => 'TOOL_CALL_END', 'toolCallId' => 'c1'],
            ['type' => 'CUSTOM', 'name' => 'artifact-preview', 'value' => ['document' => ['a' => 1]]],
            ['type' => 'CUSTOM', 'name' => 'artifact-preview', 'value' => ['document' => ['a' => 2]]],
            ['type' => 'CUSTOM', 'name' => 'artifact-published', 'value' => ['slug' => 'mon-quiz']],
            ['type' => 'CUSTOM', 'name' => 'a2ui', 'value' => ['x' => 1]],
            ['type' => 'RUN_FINISHED'],
        ] as $event) {
            $rec->emit($event);
        }
        self::assertCount(13, $inner->events, 'tout est relayé');
        $entries = $rec->entries();
        self::assertSame(['role' => 'user', 'text' => 'Fais un quiz'], $entries[0]);
        self::assertSame(['role' => 'assistant', 'id' => 'm1', 'text' => 'Je prépare.'], $entries[1]);
        self::assertSame('TOOL_CALL_START', $entries[2]['event']['type']);
        self::assertSame('TOOL_CALL_END', $entries[3]['event']['type']);
        self::assertSame('a2ui', $entries[4]['event']['name']);
        self::assertCount(5, $entries, 'ni arguments d’outils, ni aperçus dans le journal');
        self::assertSame(['document' => ['a' => 2]], $rec->preview(), 'dernier aperçu');
        self::assertSame(['slug' => 'mon-quiz'], $rec->published());
    }

    public function testStateRoundTripsCompressed(): void
    {
        $state = new ThreadState([['role' => 'user', 'content' => 'salut']], 3, ['publishedSlug' => 'x']);
        $encoded = ConversationHistory::encodeState($state);
        $back = ConversationHistory::decodeState($encoded);
        self::assertNotNull($back);
        self::assertSame(3, $back->messageCount);
        self::assertSame('salut', $back->messages[0]['content']);
        self::assertSame(['publishedSlug' => 'x'], $back->workspace);
        self::assertNull(ConversationHistory::decodeState('pas-du-base64!'));
        self::assertNull(ConversationHistory::decodeState(null));
    }

    public function testTitleIsShortAndOnOneLine(): void
    {
        self::assertSame('Un quiz', ConversationHistory::titleFrom("  Un\n quiz "));
        $title = ConversationHistory::titleFrom(str_repeat('mot ', 40));
        self::assertLessThan(61, mb_strlen($title));
        self::assertStringContainsString('…', $title);
    }
}
