<?php

declare(strict_types=1);

namespace App\Agent\Tool;

final class Toolbox
{
    /** @var array<string, AgentTool> */
    private array $tools = [];

    /** @param iterable<AgentTool> $tools */
    public function __construct(iterable $tools)
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /** @return list<array{name: string, description: string, input_schema: array<string, mixed>}> */
    public function schemas(): array
    {
        return array_values(array_map(static fn (AgentTool $t): array => [
            'name' => $t->name(),
            'description' => $t->description(),
            'input_schema' => $t->inputSchema(),
        ], $this->tools));
    }

    /** @param array<string, mixed> $input */
    public function execute(string $name, array $input, ToolContext $context): ToolResult
    {
        $tool = $this->tools[$name] ?? null;
        if ($tool === null) {
            return ToolResult::json(['error' => sprintf('Outil inconnu : %s', $name)], true);
        }
        try {
            return $tool->execute($input, $context);
        } catch (\Throwable $e) {
            // L'erreur repart au modèle (qui peut réessayer) sans casser le run.
            return ToolResult::json(['error' => $e->getMessage() !== '' ? $e->getMessage() : $e::class], true);
        }
    }
}
