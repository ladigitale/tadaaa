<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Artifact;
use App\Entity\ArtifactPlaceVisitor;
use App\Entity\User;
use App\Repository\ArtifactPlaceVisitorRepository;
use App\Service\ArtifactPlaceVisitors;
use App\Service\ArtifactPlaceVisitorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class ArtifactPlaceVisitorTest extends TestCase
{
    private static function svg(string $inner): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256">'.$inner.'</svg>');
    }

    public function testAcceptsSketchExport(): void
    {
        $v = self::svg('<defs><mask id="m"><rect width="9" height="9"/></mask></defs><g mask="url(#m)"><path d="M0 0L9 9" stroke="#ffd23a"/><use href="#m"/></g>'
            .'<metadata id="sonic-sketch-source" data-format="gz64">H4sIA/onAB==</metadata>');
        self::assertSame($v, ArtifactPlaceVisitorService::cleanAvatar($v));
        self::assertNull(ArtifactPlaceVisitorService::cleanAvatar(''));
        self::assertStringNotContainsString('metadata', ArtifactPlaceVisitorService::stripMetadata(ArtifactPlaceVisitorService::svgOf($v)));
    }

    /** @return iterable<string, array{string}> */
    public static function unsafe(): iterable
    {
        yield 'script' => ['<script>alert(1)</script>'];
        yield 'évènement' => ['<rect onload="alert(1)"/>'];
        yield 'lien externe' => ['<use href="https://x.test/a.svg#b"/>'];
        yield 'xlink externe' => ['<a xlink:href="javascript:alert(1)"><rect/></a>'];
        yield 'image' => ['<image href="#x"/>'];
        yield 'url externe' => ['<rect fill="url(https://x.test/p)"/>'];
        yield 'foreignObject' => ['<foreignObject><div/></foreignObject>'];
        yield 'import' => ['<style>@import "https://x.test/a.css";</style>'];
    }

    /** @dataProvider unsafe */
    public function testRejectsActiveOrExternalContent(string $inner): void
    {
        $this->expectException(BadRequestHttpException::class);
        ArtifactPlaceVisitorService::cleanAvatar(self::svg($inner));
    }

    public function testRejectsOtherFormatsAndHugeFiles(): void
    {
        foreach (['data:image/png;base64,AAAA', 'https://x.test/a.svg', 'data:image/svg+xml;base64,'.str_repeat('A', 400_000)] as $bad) {
            try {
                ArtifactPlaceVisitorService::cleanAvatar($bad);
                self::fail('accepté : '.substr($bad, 0, 30));
            } catch (BadRequestHttpException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private static function artifact(): Artifact
    {
        $a = (new \ReflectionClass(Artifact::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Artifact::class, 'id'))->setValue($a, Uuid::v7());

        return $a;
    }

    private static function user(): User
    {
        $u = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, Uuid::v7());

        return $u;
    }

    /** @var list<ArtifactPlaceVisitor> */
    private array $store = [];

    /** Résolveur sur un dépôt en mémoire. */
    private function resolver(): ArtifactPlaceVisitors
    {
        $store = &$this->store;
        $repo = $this->createMock(ArtifactPlaceVisitorRepository::class);
        $repo->method('findByDevice')->willReturnCallback(static function (Artifact $a, string $c, string $h) use (&$store): ?ArtifactPlaceVisitor {
            foreach ($store as $v) {
                if ($v->getArtifact() === $a && $v->getCollection() === $c && $v->getDeviceHash() === $h) {
                    return $v;
                }
            }

            return null;
        });
        $repo->method('findByUser')->willReturnCallback(static function (Artifact $a, string $c, User $u) use (&$store): ?ArtifactPlaceVisitor {
            foreach ($store as $v) {
                if ($v->getArtifact() === $a && $v->getCollection() === $c && $v->getUser() === $u) {
                    return $v;
                }
            }

            return null;
        });
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $o) use (&$store): void {
            $store[] = $o;
        });

        return new ArtifactPlaceVisitors($repo, $em);
    }

    public function testDeviceBlazeIsCreatedOnceAndReused(): void
    {
        $r = $this->resolver();
        $a = self::artifact();
        $key = str_repeat('aZ_-', 6);
        self::assertNull($r->resolve($a, 'publiques', null, null, true), 'ni appareil ni compte : pas de blaze');
        self::assertNull($r->resolve($a, 'publiques', $key, null, false));
        $v = $r->resolve($a, 'publiques', $key, null, true);
        self::assertNotNull($v);
        self::assertSame($v, $r->resolve($a, 'publiques', $key, null, true));
        self::assertNotSame($v, $r->resolve($a, 'autre', $key, null, true), 'un blaze par collection');
    }

    public function testAccountClaimsDeviceBlazeAndFollowsToOtherDevices(): void
    {
        $r = $this->resolver();
        $a = self::artifact();
        $u = self::user();
        $phone = str_repeat('pH0n', 6);
        $v = $r->resolve($a, 'publiques', $phone, null, true);
        self::assertSame($v, $r->resolve($a, 'publiques', $phone, $u, true), 'le compte revendique le blaze de l\'appareil');
        self::assertSame($u, $v?->getUser());
        self::assertSame($v, $r->resolve($a, 'publiques', str_repeat('t4bl', 6), $u, true), 'autre appareil : même blaze');
        self::assertSame($v, $r->resolve($a, 'publiques', null, $u, false));
        // Un autre compte sur le même téléphone : son propre blaze, l'appareil reste au premier.
        $other = self::user();
        $w = $r->resolve($a, 'publiques', $phone, $other, true);
        self::assertNotNull($w);
        self::assertNotSame($v, $w);
        self::assertSame($other, $w->getUser());
        self::assertSame($v, $r->resolve($a, 'publiques', $phone, null, false), 'anonyme sur ce téléphone : le blaze de l\'appareil');
    }

    public function testHiddenOrEmptyAvatarHasNoPath(): void
    {
        $r = $this->resolver();
        $v = $r->resolve(self::artifact(), 'publiques', str_repeat('aZ_-', 6), null, true);
        self::assertNotNull($v);
        self::assertNull(ArtifactPlaceVisitors::avatarPath('balise', 'publiques', $v));
        $v->update('zorg', self::svg('<path d="M0 0L9 9"/>'));
        self::assertStringStartsWith('/api/public/artifacts/balise/places/publiques/avatars/'.$v->getId()->toRfc4122().'?v=', (string) ArtifactPlaceVisitors::avatarPath('balise', 'publiques', $v));
        $v->report();
        $v->report();
        $v->report();
        self::assertNull(ArtifactPlaceVisitors::avatarPath('balise', 'publiques', $v));
    }

    public function testDeviceKeyIsHashedPerArtifact(): void
    {
        $a = self::artifact();
        $b = self::artifact();
        $key = str_repeat('aZ_-', 6);
        self::assertNull(ArtifactPlaceVisitors::deviceHash($a, null));
        self::assertNull(ArtifactPlaceVisitors::deviceHash($a, 'court'));
        self::assertNull(ArtifactPlaceVisitors::deviceHash($a, str_repeat('é', 30)));
        self::assertSame(64, \strlen((string) ArtifactPlaceVisitors::deviceHash($a, $key)));
        self::assertNotSame(ArtifactPlaceVisitors::deviceHash($a, $key), ArtifactPlaceVisitors::deviceHash($b, $key));
    }
}
