<?php

declare(strict_types=1);

namespace App\Agent\Llm;

/**
 * Un tour de modèle avec outils. Les messages suivent le format « content blocks »
 * (texte, tool_use, tool_result) ; un adaptateur par fournisseur s'occupe du transport.
 */
interface LlmClient
{
    /**
     * @param list<array{role: 'user'|'assistant', content: string|list<array<string, mixed>>}> $messages
     * @param list<array{name: string, description: string, input_schema: array<string, mixed>}> $tools
     * @param LlmStream|null                                                                       $stream reçoit le texte et les débuts
     *                                                                                                     d'appels d'outils pendant la
     *                                                                                                     génération, si le client
     *                                                                                                     sait streamer
     */
    public function complete(string $system, array $messages, array $tools, ?LlmStream $stream = null): LlmResponse;
}
