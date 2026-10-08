<?php

declare(strict_types=1);

namespace App\Agent\Llm;

/** Le modèle ne peut pas répondre (configuration, quota, réseau) : message montrable à l'utilisateur. */
final class LlmUnavailable extends \RuntimeException
{
    /** Code AG-UI `RUN_ERROR.code` quand l'assistant n'est pas configuré. */
    public const NOT_CONFIGURED = 1;

    public function errorCode(): ?string
    {
        return $this->getCode() === self::NOT_CONFIGURED ? 'AGENT_NOT_CONFIGURED' : null;
    }
}
