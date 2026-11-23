<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Mcp\Skill\SkillResources;
use Mcp\Capability\Attribute\McpResource;
use PHPUnit\Framework\TestCase;

/**
 * Compétences servies sur MCP (SEP-2640) : une ressource skill://<nom>/SKILL.md par fichier de compétence, avec le
 * nom et la description de son en-tête, et le texte du fichier tel quel.
 */
final class SkillResourcesTest extends TestCase
{
    public function testOneResourcePerSkillFileMatchingItsFrontmatter(): void
    {
        $dir = \dirname(__DIR__, 2).'/config/artifacts/skills';
        $files = glob($dir.'/*.md') ?: [];
        $names = array_map(static fn (string $f): string => basename($f, '.md'), $files);
        sort($names);
        self::assertSame($names, SkillResources::NAMES);

        $skills = new SkillResources();
        foreach ((new \ReflectionClass(SkillResources::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $attrs = $method->getAttributes(McpResource::class);
            if ($attrs === []) {
                continue;
            }
            $res = $attrs[0]->newInstance();
            $text = (string) $method->invoke($skills);
            self::assertTrue((bool) preg_match('/^---\n(.*?)\n---\n/s', $text, $m), $res->name.' : en-tête YAML');
            $front = [];
            foreach (explode("\n", $m[1]) as $line) {
                [$k, $v] = array_pad(explode(': ', $line, 2), 2, '');
                $front[$k] = trim($v);
            }
            self::assertSame('skill://'.$front['name'].'/SKILL.md', $res->uri);
            self::assertSame($front['name'], $res->name);
            self::assertSame($front['description'], $res->description);
            self::assertSame('text/markdown', $res->mimeType);
            // Nom selon Agent Skills : minuscules, chiffres, tirets.
            self::assertTrue((bool) preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $res->name));
        }
    }
}
