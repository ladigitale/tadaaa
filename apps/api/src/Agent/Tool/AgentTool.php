<?php

declare(strict_types=1);

namespace App\Agent\Tool;

interface AgentTool
{
    public function name(): string;

    public function description(): string;

    /** @return array<string, mixed> JSON Schema de l'entrée */
    public function inputSchema(): array;

    /** @param array<string, mixed> $input */
    public function execute(array $input, ToolContext $context): ToolResult;
}
