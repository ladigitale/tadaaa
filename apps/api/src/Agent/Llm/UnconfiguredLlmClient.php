<?php

declare(strict_types=1);

namespace App\Agent\Llm;

/** Aucun modèle disponible pour cet utilisateur : chaque appel lève une erreur explicite. */
final class UnconfiguredLlmClient implements LlmClient
{
    public function __construct(private readonly string $message)
    {
    }

    public function complete(string $system, array $messages, array $tools): LlmResponse
    {
        throw new LlmUnavailable($this->message, code: LlmUnavailable::NOT_CONFIGURED);
    }
}
