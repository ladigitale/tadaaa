<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Mcp\Skill\SkillLoader;
use App\Mcp\Skill\SkillsRequestListener;
use App\Service\ArtifactDocumentValidator;
use App\Service\ArtifactScriptsCatalog;
use App\Service\ArtifactSkills;
use Mcp\Capability\Registry;
use PHPUnit\Framework\TestCase;

/**
 * Skills servies sur MCP (extension io.modelcontextprotocol/skills, SEP-2640) : frontmatter YAML valide, nom =
 * dernier segment de l'URI, manifeste complet avec empreintes, ressources enregistrées, skills/list et skills/get.
 */
final class ArtifactSkillsTest extends TestCase
{
    private static function config(): string
    {
        return \dirname(__DIR__, 2).'/config/artifacts';
    }

    private static function skills(): ArtifactSkills
    {
        $dir = self::config();
        $validator = new ArtifactDocumentValidator($dir.'/catalog.json', $dir.'/sdui.schema.json', new ArtifactScriptsCatalog($dir.'/scripts-catalog.json'));

        return new ArtifactSkills($validator, $dir.'/skills');
    }

    public function testEverySkillFollowsTheAgentSkillsFormat(): void
    {
        $all = self::skills()->all();
        self::assertArrayHasKey('demarrer', $all);
        foreach (['creer-artefact', 'modifier-artefact', 'tester-artefact', 'publier-artefact', 'corriger-erreurs', 'design', 'jeu', '3d', 'audio', 'donnees'] as $name) {
            self::assertArrayHasKey($name, $all, $name);
        }

        foreach ($all as $name => $skill) {
            // Frontmatter : YAML strict (un « : » non cité casse les lecteurs de skills), name et description.
            $front = ArtifactSkills::frontmatter($skill['files']['SKILL.md']);
            self::assertSame($skill['frontmatter'], $front, $name.' : frontmatter relu identique');
            self::assertSame($name, $front['name'] ?? null);
            self::assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name);
            self::assertLessThanOrEqual(64, \strlen($name));
            self::assertIsString($front['description'] ?? null, $name);
            self::assertGreaterThan(40, mb_strlen($front['description']), $name.' : description trop courte pour décider');
            self::assertLessThanOrEqual(1024, mb_strlen($front['description']), $name);
            // Dernier segment du chemin = nom.
            self::assertSame($name, basename($skill['path']));
            self::assertNotSame('', ArtifactSkills::body($skill['files']['SKILL.md']));
        }
    }

    public function testEntriesCarryACompleteManifest(): void
    {
        $skills = self::skills();
        $entry = ArtifactSkills::entry($skills->byName('demarrer') ?? []);
        self::assertSame('skill://demarrer/SKILL.md', $entry['uri']);
        $uris = array_column($entry['resources'], 'uri');
        self::assertSame('skill://demarrer/SKILL.md', $uris[0]);
        self::assertContains('skill://demarrer/references/outils.md', $uris);
        foreach ($entry['resources'] as $r) {
            $content = $skills->read($r['uri']);
            self::assertNotNull($content);
            self::assertSame('sha256:'.hash('sha256', $content), $r['digest']);
            self::assertSame(\strlen($content), $r['size']);
        }
        self::assertSame('briques/3d', $skills->byName('3d')['path'] ?? null);
        self::assertNotNull($skills->byUri('skill://actions/modifier-artefact/SKILL.md'));
    }

    public function testSkillsReferencedByThePilotExist(): void
    {
        $skills = self::skills();
        $root = \dirname(__DIR__, 2);
        $texts = [
            'mcp.yaml' => (string) file_get_contents($root.'/config/packages/mcp.yaml'),
            'prompts' => (string) file_get_contents($root.'/src/Mcp/Prompt/ArtifactPrompts.php'),
            'demarrer' => $skills->byName('demarrer')['files']['SKILL.md'] ?? '',
        ];
        $seen = 0;
        foreach ($texts as $source => $text) {
            preg_match_all('#skill://([a-z0-9/-]+?)(?:/SKILL\.md)?(?=[\s,;)."`]|$)#', $text, $m);
            foreach ($m[1] as $path) {
                ++$seen;
                self::assertNotNull($skills->byUri('skill://'.$path.'/SKILL.md'), $source.' cite skill://'.$path);
            }
        }
        self::assertGreaterThan(10, $seen);

        // Noms cités dans les tableaux et listes du pilote (`nom`).
        $pilot = ArtifactSkills::body($texts['demarrer']);
        $sections = preg_split('/^## /m', $pilot) ?: [];
        foreach ($sections as $section) {
            if (!preg_match('/^(1|2|3)\./', $section)) {
                continue;
            }
            preg_match_all('/`([a-z0-9-]+)`/', $section, $m);
            foreach ($m[1] as $name) {
                self::assertNotNull($skills->byName($name), 'demarrer cite '.$name);
            }
        }
    }

    public function testLoaderRegistersOneResourcePerFile(): void
    {
        $skills = self::skills();
        $registry = new Registry();
        (new SkillLoader($skills))->load($registry);
        $count = 0;
        foreach ($skills->all() as $skill) {
            $count += \count($skill['files']);
        }
        $resources = $registry->getResources();
        self::assertCount($count, $resources->references);
        $main = $registry->getResource('skill://design/SKILL.md');
        self::assertSame('design', $main->resource->name);
        self::assertSame('text/markdown', $main->resource->mimeType);
    }

    public function testSkillsListAndGet(): void
    {
        $listener = new SkillsRequestListener(self::skills());
        $list = $listener->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'skills/list']);
        self::assertSame('complete', $list['result']['resultType']);
        self::assertGreaterThan(30, \count($list['result']['skills']));
        self::assertArrayHasKey('ttlMs', $list['result']);

        $get = $listener->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'skills/get', 'params' => ['uri' => 'skill://briques/audio/SKILL.md']]);
        self::assertSame('audio', $get['result']['skill']['frontmatter']['name']);

        $dir = $listener->handle(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'resources/directory/read', 'params' => ['uri' => 'skill://demarrer']]);
        self::assertSame(['SKILL.md', 'references'], array_column($dir['result']['resources'], 'name'));
        self::assertSame('inode/directory', $dir['result']['resources'][1]['mimeType']);
        $refs = $listener->handle(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'resources/directory/read', 'params' => ['uri' => 'skill://demarrer/references']]);
        self::assertSame('skill://demarrer/references/outils.md', $refs['result']['resources'][0]['uri']);
        $prefix = $listener->handle(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'resources/directory/read', 'params' => ['uri' => 'skill://actions']]);
        self::assertContains('modifier-artefact', array_column($prefix['result']['resources'], 'name'));
        $file = $listener->handle(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'resources/directory/read', 'params' => ['uri' => 'skill://demarrer/SKILL.md']]);
        self::assertSame(-32602, $file['error']['code']);

        $missing = $listener->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'skills/get', 'params' => ['uri' => 'skill://inconnue/SKILL.md']]);
        self::assertSame(-32602, $missing['error']['code']);
    }

    public function testCatalogRulesServeActionSkills(): void
    {
        $dir = self::config();
        $validator = new ArtifactDocumentValidator($dir.'/catalog.json', $dir.'/sdui.schema.json', new ArtifactScriptsCatalog($dir.'/scripts-catalog.json'));
        $payload = $validator->mcpCatalogPayload(true, ['__none__'], ['design', 'modifier-artefact'], false);
        self::assertArrayHasKey('design', $payload['skills']);
        self::assertArrayHasKey('modifier-artefact', $payload['skills']);
        // Le sommaire de l'atelier ne liste que les skills de conception.
        self::assertSame(ArtifactDocumentValidator::SKILLS, array_values(array_intersect(ArtifactDocumentValidator::SKILLS, array_keys($validator->skills()))));
        self::assertArrayNotHasKey('modifier-artefact', $validator->skills());
    }
}
