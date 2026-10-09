<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * `RunAgentInput` AG-UI reçu de sonic-chat, réduit à ce que l'agent utilise.
 * Voir agent-stack, docs/backend-contract.md.
 */
final class RunInput
{
    public const MAX_MESSAGES = 40;
    public const MAX_CHARS = 8000;

    /**
     * @param list<array{role: 'user'|'assistant', content: string}> $messages
     * @param array<string, mixed>|null                             $a2uiAction  `{version, action: {…}}`
     * @param array<string, mixed>|null                             $sduiAction  `{name, context, sourceNodeId?}`
     * @param list<array<string, mixed>>                            $a2uiErrors
     * @param array{artifactSlug?: string}                          $appContext  contexte fourni par l'application
     * @param int|null                                              $messageCount nombre de messages texte reçus, avant
     *                                                                            la troncature à MAX_MESSAGES
     */
    public function __construct(
        public readonly string $threadId,
        public readonly string $runId,
        public readonly array $messages,
        public readonly ?array $a2uiAction = null,
        public readonly ?array $sduiAction = null,
        public readonly array $a2uiErrors = [],
        public readonly array $appContext = [],
        ?int $messageCount = null,
    ) {
        $this->messageCount = $messageCount ?? \count($messages);
    }

    public readonly int $messageCount;

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        $threadId = \is_string($body['threadId'] ?? null) ? substr($body['threadId'], 0, 100) : '';
        $runId = \is_string($body['runId'] ?? null) ? substr($body['runId'], 0, 100) : '';
        if ($threadId === '' || $runId === '') {
            throw new \InvalidArgumentException('threadId et runId sont requis.');
        }
        $messages = [];
        foreach (\is_array($body['messages'] ?? null) ? $body['messages'] : [] as $m) {
            if (!\is_array($m) || !\in_array($m['role'] ?? null, ['user', 'assistant'], true)) {
                continue;
            }
            $content = $m['content'] ?? '';
            if (!\is_string($content) || trim($content) === '') {
                continue;
            }
            $messages[] = ['role' => $m['role'], 'content' => mb_substr($content, 0, self::MAX_CHARS)];
        }
        $count = \count($messages);
        $messages = \array_slice($messages, -self::MAX_MESSAGES);
        $fp = \is_array($body['forwardedProps'] ?? null) ? $body['forwardedProps'] : [];

        return new self(
            $threadId,
            $runId,
            $messages,
            \is_array($fp['a2uiAction'] ?? null) ? $fp['a2uiAction'] : null,
            \is_array($fp['sduiAction'] ?? null) ? $fp['sduiAction'] : null,
            \is_array($fp['a2uiErrors'] ?? null) ? array_values(array_filter($fp['a2uiErrors'], 'is_array')) : [],
            self::appContext($fp),
            $count,
        );
    }

    /** @param array<string, mixed> $fp */
    private static function appContext(array $fp): array
    {
        $slug = \is_array($fp['artifact'] ?? null) ? ($fp['artifact']['slug'] ?? null) : null;

        return \is_string($slug) && preg_match('/^[a-z0-9][a-z0-9-]{2,63}$/', $slug) ? ['artifactSlug' => $slug] : [];
    }
}
