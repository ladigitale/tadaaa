<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use ApiPlatform\Metadata\McpTool;
use ApiPlatform\State\ProcessorInterface;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

/**
 * Expose un outil MCP de Tadaaa (classe DTO + processor API Platform) à l'agent,
 * exécuté dans le process avec l'utilisateur courant : mêmes droits, même audit,
 * même compteur d'usage que par /mcp.
 *
 * Le schéma d'entrée est déduit du constructeur du DTO (types PHP, valeurs par défaut,
 * `@param list<string>`).
 */
final class McpToolAdapter implements AgentTool
{
    private readonly McpTool $metadata;

    /** @param class-string $toolClass */
    public function __construct(
        private readonly string $toolClass,
        private readonly ProcessorInterface $processor,
    ) {
        $attributes = (new \ReflectionClass($toolClass))->getAttributes(McpTool::class);
        if ($attributes === []) {
            throw new \LogicException(sprintf('%s n’a pas d’attribut #[McpTool].', $toolClass));
        }
        $this->metadata = $attributes[0]->newInstance();
    }

    public function name(): string
    {
        return (string) $this->metadata->getName();
    }

    public function description(): string
    {
        return (string) $this->metadata->getDescription();
    }

    public function inputSchema(): array
    {
        $properties = [];
        $required = [];
        $constructor = (new \ReflectionClass($this->toolClass))->getConstructor();
        $listParams = $this->listParams($constructor?->getDocComment() ?: '');
        foreach ($constructor?->getParameters() ?? [] as $param) {
            $type = $param->getType();
            $name = $param->getName();
            $schema = match ($type instanceof \ReflectionNamedType ? $type->getName() : 'mixed') {
                'string' => ['type' => 'string'],
                'int' => ['type' => 'integer'],
                'float' => ['type' => 'number'],
                'bool' => ['type' => 'boolean'],
                'array' => match ($listParams[$name] ?? null) {
                    null => ['type' => 'array'],
                    'object' => ['type' => 'object'],
                    default => ['type' => 'array', 'items' => ['type' => $listParams[$name]]],
                },
                default => [],
            };
            if ($param->isDefaultValueAvailable()) {
                $default = $param->getDefaultValue();
                if ($default !== null) {
                    $schema['default'] = $default;
                }
            } else {
                $required[] = $name;
            }
            $properties[$name] = $schema;
        }
        $schema = ['type' => 'object', 'properties' => (object) $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    public function execute(array $input, ToolContext $context): ToolResult
    {
        $args = [];
        foreach ((new \ReflectionClass($this->toolClass))->getConstructor()?->getParameters() ?? [] as $param) {
            if (\array_key_exists($param->getName(), $input)) {
                $args[$param->getName()] = $input[$param->getName()];
            }
        }
        try {
            $dto = new ($this->toolClass)(...$args);
        } catch (\TypeError $e) {
            return ToolResult::json(['error' => 'Arguments invalides : '.$e->getMessage()], true);
        }
        $result = $this->processor->process($dto, $this->metadata);
        if (!$result instanceof CallToolResult) {
            return ToolResult::json(['result' => $result]);
        }
        $texts = [];
        foreach ($result->content as $content) {
            if ($content instanceof TextContent) {
                $texts[] = (string) $content->text;
            }
        }

        return new ToolResult(implode("\n", $texts), $result->isError);
    }

    /**
     * @return array<string, string> paramètre → type des éléments (`@param list<string>`),
     *                               ou "object" pour un tableau associatif (`@param array<string, mixed>`)
     */
    private function listParams(string $doc): array
    {
        $out = [];
        if (preg_match_all('/@param\s+array<string,\s*\w+>(?:\|null)?\s+\$(\w+)/', $doc, $m, PREG_SET_ORDER)) {
            foreach ($m as [, $name]) {
                $out[$name] = 'object';
            }
        }
        if (preg_match_all('/@param\s+(?:list|array)<(string|int)>(?:\|null)?\s+\$(\w+)/', $doc, $m, PREG_SET_ORDER)) {
            foreach ($m as [, $type, $name]) {
                $out[$name] = $type === 'int' ? 'integer' : 'string';
            }
        }

        return $out;
    }
}
