<?php

declare(strict_types=1);

namespace App\Service;

/**
 * OAuth token endpoint / DCR error (RFC 6749).
 */
final class OAuthServerException extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly int $statusCode = 400,
    ) {
        parent::__construct($description);
    }
}
