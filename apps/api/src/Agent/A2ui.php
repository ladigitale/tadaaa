<?php

declare(strict_types=1);

namespace App\Agent;

use App\Service\ArtifactA2uiValidator;

final class A2ui
{
    /** @param array<string, mixed> $body */
    public static function message(string $kind, array $body): array
    {
        return ['version' => ArtifactA2uiValidator::VERSION, $kind => $body];
    }
}
