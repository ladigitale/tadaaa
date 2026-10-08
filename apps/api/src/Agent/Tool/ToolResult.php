<?php

declare(strict_types=1);

namespace App\Agent\Tool;

final class ToolResult
{
    public function __construct(
        public readonly string $content,
        public readonly bool $isError = false,
    ) {
    }

    /** @param array<string, mixed>|list<mixed> $data */
    public static function json(array $data, bool $isError = false): self
    {
        return new self(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $isError);
    }
}
