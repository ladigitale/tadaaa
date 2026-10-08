<?php

declare(strict_types=1);

namespace App\Agent\Llm;

/** Le modèle ne peut pas répondre (configuration, quota, réseau) : message montrable à l'utilisateur. */
final class LlmUnavailable extends \RuntimeException
{
}
