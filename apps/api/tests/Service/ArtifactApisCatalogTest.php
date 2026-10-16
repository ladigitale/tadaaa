<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ArtifactApisCatalog;
use PHPUnit\Framework\TestCase;

final class ArtifactApisCatalogTest extends TestCase
{
    private ArtifactApisCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new ArtifactApisCatalog(\dirname(__DIR__, 2).'/config/artifacts/apis-catalog.json');
    }

    public function testEveryEntryIsWellFormed(): void
    {
        $raw = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/config/artifacts/apis-catalog.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertNotSame([], $raw['apis']);
        foreach ($raw['apis'] as $id => $api) {
            $base = parse_url($api['baseUrl']);
            self::assertSame('https', $base['scheme'], $id);
            self::assertArrayNotHasKey('path', $base, $id);
            self::assertArrayNotHasKey('query', $base, $id);
            self::assertArrayHasKey($api['category'], $raw['categories'], $id);
            foreach ($api['endpoints'] as $eid => $endpoint) {
                self::assertStringStartsWith('/', $endpoint['path'], "$id/$eid");
                // Chaque exemple doit produire une URL valide : le catalogue ne ment pas.
                $example = $endpoint['example'] ?? [];
                $pathNames = $endpoint['pathParams'] ?? [];
                $built = $this->catalog->buildUrl(
                    $id,
                    $eid,
                    array_intersect_key($example, array_flip($pathNames)),
                    array_diff_key($example, array_flip($pathNames)),
                );
                self::assertStringStartsWith($api['baseUrl'].'/', $built['url'], "$id/$eid");
            }
        }
    }

    public function testBuildsUrlWithEncodedPathAndQuery(): void
    {
        $built = $this->catalog->buildUrl('wikipedia-fr', 'summary', ['title' => 'Léonard de Vinci']);
        self::assertSame('https://fr.wikipedia.org/api/rest_v1/page/summary/L%C3%A9onard%20de%20Vinci', $built['url']);

        $built = $this->catalog->buildUrl('open-meteo', 'forecast', [], ['latitude' => 48.85, 'longitude' => '2.35', 'current' => 'temperature_2m,wind_speed_10m']);
        self::assertSame('https://api.open-meteo.com/v1/forecast?latitude=48.85&longitude=2.35&current=temperature_2m%2Cwind_speed_10m', $built['url']);
        self::assertSame('api.open-meteo.com', $built['host']);
    }

    /** @return iterable<string, array{string, string, array<string, mixed>, array<string, mixed>}> */
    public static function rejectedCalls(): iterable
    {
        yield 'api inconnue' => ['evil', 'x', [], []];
        yield 'endpoint inconnu' => ['open-meteo', 'admin', [], []];
        yield 'paramètre non listé' => ['open-meteo', 'forecast', [], ['latitude' => '1', 'longitude' => '2', 'callback' => 'x']];
        yield 'paramètre obligatoire absent' => ['open-meteo', 'forecast', [], ['latitude' => '1']];
        yield 'variable de chemin absente' => ['wikipedia-fr', 'summary', [], []];
        yield 'variable de chemin inconnue' => ['wikipedia-fr', 'summary', ['title' => 'a', 'host' => 'b'], []];
        yield 'traversée de chemin' => ['wikipedia-fr', 'summary', ['title' => '..'], []];
        yield 'barre oblique dans le chemin' => ['wikipedia-fr', 'summary', ['title' => 'a/../../x'], []];
        yield 'encodage déjà appliqué' => ['wikipedia-fr', 'summary', ['title' => '%2e%2e'], []];
        yield 'hors liste fermée' => ['usgs-earthquakes', 'feed', ['feed' => 'all_month'], []];
        yield 'valeur non scalaire' => ['open-meteo', 'forecast', [], ['latitude' => ['1'], 'longitude' => '2']];
        yield 'valeur trop longue' => ['open-meteo', 'forecast', [], ['latitude' => str_repeat('9', 300), 'longitude' => '2']];
        yield 'caractère de contrôle' => ['open-meteo', 'forecast', [], ['latitude' => "1\r\nHost: x", 'longitude' => '2']];
    }

    /**
     * @dataProvider rejectedCalls
     *
     * @param array<string, mixed> $pathParams
     * @param array<string, mixed> $params
     */
    public function testRejectsUnsafeCalls(string $api, string $endpoint, array $pathParams, array $params): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->catalog->buildUrl($api, $endpoint, $pathParams, $params);
    }

    public function testSearchReturnsIndexThenDetail(): void
    {
        $index = $this->catalog->search();
        self::assertGreaterThan(10, $index['count']);
        self::assertArrayHasKey('hint', $index);
        self::assertIsArray($index['apis'][0]['endpoints']);
        self::assertIsString($index['apis'][0]['endpoints'][0]);

        $detail = $this->catalog->search('météo');
        self::assertSame('open-meteo', $detail['apis'][0]['id']);
        self::assertArrayHasKey('example', $detail['apis'][0]['endpoints'][0]);

        self::assertSame(0, $this->catalog->search('zzzinconnu')['count']);
    }
}
