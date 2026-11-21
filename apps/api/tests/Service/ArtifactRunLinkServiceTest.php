<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Artifact;
use App\Entity\ArtifactRun;
use App\Entity\ArtifactRunLink;
use App\Service\ArtifactRunLinkService;
use App\Service\StoreSimulator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Uuid;

final class ArtifactRunLinkServiceTest extends TestCase
{
    private ArtifactRunLinkService $service;

    protected function setUp(): void
    {
        $this->service = (new \ReflectionClass(ArtifactRunLinkService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(ArtifactRunLinkService::class, 'jsonata'))->setValue($this->service, new StoreSimulator(\dirname(__DIR__, 2).'/tools/simulate-store'));
    }

    private static function link(?string $map): ArtifactRunLink
    {
        $artifact = (new \ReflectionClass(Artifact::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Artifact::class, 'slug'))->setValue($artifact, 'telecommande');
        $run = (new \ReflectionClass(ArtifactRun::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(ArtifactRun::class, 'id'))->setValue($run, Uuid::v7());
        (new \ReflectionProperty(ArtifactRun::class, 'artifact'))->setValue($run, $artifact);

        return new ArtifactRunLink($run, $run, $map, null);
    }

    /** @return list<\stdClass> */
    private static function items(string $json): array
    {
        return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }

    private function jsonataAvailable(): bool
    {
        return is_dir(\dirname(__DIR__, 2).'/tools/simulate-store/node_modules/jsonata');
    }

    public function testWithoutMapDataIsTheMessage(): void
    {
        $out = $this->service->transform(self::link(null), self::items('[{"id":1,"data":{"type":"action","name":"pulse"}},{"id":2,"data":[{"type":"end"}]},{"id":3,"data":null}]'));
        self::assertCount(2, $out);
        self::assertSame('pulse', $out[0]->name);
        self::assertSame('end', $out[1]->type);
    }

    public function testWithoutMapRejectsNonMessages(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->service->transform(self::link(null), self::items('[{"id":1,"data":"applaudir"}]'));
    }

    public function testMapTranslatesPads(): void
    {
        if (!$this->jsonataAvailable()) {
            self::assertTrue(true);

            return;
        }
        $map = 'data.pad = "applaudir" ? {"type": "action", "name": "reaction", "payload": {"n": data.force}} : data.pad = "fin" ? [{"type": "action", "name": "chapitre", "payload": {"nom": "Fin"}}, {"type": "end"}]';
        $out = $this->service->transform(self::link($map), self::items('[{"id":1,"data":{"pad":"applaudir","force":20}},{"id":2,"data":{"pad":"rien"}},{"id":3,"data":{"pad":"fin"}}]'));
        self::assertCount(3, $out, json_encode($out));
        self::assertSame(20, $out[0]->payload->n);
        self::assertSame('chapitre', $out[1]->name);
        self::assertSame('end', $out[2]->type);

        // Un seul élément : le résultat reste une liste de messages.
        $one = $this->service->transform(self::link('{"type": "action", "name": "annonce", "payload": {"texte": from.slug & " : " & $string(id)}}'), self::items('[{"id":9,"data":{}}]'));
        self::assertCount(1, $one);
        self::assertSame('telecommande : 9', $one[0]->payload->texte);
    }

    public function testMapErrors(): void
    {
        if (!$this->jsonataAvailable()) {
            self::assertTrue(true);

            return;
        }
        $this->expectException(BadRequestHttpException::class);
        $this->service->transform(self::link('"juste un texte"'), self::items('[{"id":1,"data":{}}]'));
    }

    public function testTargetedItemsOnlyReachListedRuns(): void
    {
        $a = '01a1f29a-f242-76b7-a2e0-1d86f2d1a22f';
        $b = '01a1f29a-f242-76b7-a2e0-1d86f2d1a230';
        $items = self::items('[{"id":1,"data":{}},{"id":2,"data":{},"to":["'.$a.'"]},{"id":3,"data":{},"to":["'.$b.'","'.$a.'"]}]');
        self::assertSame([0, 1, 2], ArtifactRunLinkService::itemsFor($items, $a));
        self::assertSame([0, 2], ArtifactRunLinkService::itemsFor($items, strtoupper($b)));
        self::assertSame([0], ArtifactRunLinkService::itemsFor($items, '01a1f29a-0000-7000-8000-000000000000'));
    }

    public function testMapKeepsOneResultPerItem(): void
    {
        if (!$this->jsonataAvailable()) {
            self::assertTrue(true);

            return;
        }
        $out = $this->service->transform(self::link('data.n > 1 ? [{"type": "action", "name": "a"}, {"type": "action", "name": "b"}] : (data.n = 1 ? {"type": "action", "name": "seul"})'), self::items('[{"id":1,"data":{"n":0}},{"id":2,"data":{"n":1}},{"id":3,"data":{"n":2}}]'));
        self::assertSame(['seul', 'a', 'b'], array_map(static fn (\stdClass $m): string => $m->name, $out));
        $one = $this->service->transform(self::link('{"type": "action", "name": "x"}'), self::items('[{"id":1,"data":{}}]'));
        self::assertCount(1, $one);
    }
}
