<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Artifact;
use App\Entity\Dataset;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\TagRepository;
use App\Service\ArtifactTags;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class ArtifactTagsTest extends TestCase
{
    public function testNormalizesNames(): void
    {
        self::assertSame(['Jeu', 'Jeu 3D'], ArtifactTags::normalizeNames(['  Jeu ', 'jeu', 'Jeu   3D', '']));
        self::assertSame([], ArtifactTags::normalizeNames(null));
        self::assertSame([], ArtifactTags::normalizeNames([]));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalid(): iterable
    {
        yield 'pas une liste' => [['a' => 'Jeu']];
        yield 'chaîne seule' => ['Jeu'];
        yield 'nombre' => [[3]];
        yield 'trop long' => [[str_repeat('x', 41)]];
        yield 'trop nombreux' => [['a', 'b', 'c', 'd', 'e', 'f', 'g']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testRejectsInvalidNames(mixed $raw): void
    {
        $this->expectException(BadRequestHttpException::class);
        ArtifactTags::normalizeNames($raw);
    }

    public function testAssignsExistingTagsAndCreatesMissingOnes(): void
    {
        $dataset = new Dataset('Artefacts');
        $existing = (new Tag('tag-jeu', $dataset))->setName('Jeu');
        $deleted = (new Tag('tag-old', $dataset))->setName('Outil')->setDeletedAt(new \DateTimeImmutable());
        $repo = $this->createStub(TagRepository::class);
        $repo->method('findChangedSince')->willReturn([$existing, $deleted]);
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $o) use (&$persisted): void {
            $persisted[] = $o;
        });

        $tags = new ArtifactTags($repo, $em);
        $artifact = new Artifact($dataset, new User('a@example.com'), 'mon-jeu', 'Mon jeu');
        $tags->assign($artifact, ['jeu', 'Outil']);

        // « jeu » retrouve l'étiquette existante (casse ignorée) ; « Outil » supprimée est recréée
        self::assertCount(1, $persisted);
        self::assertInstanceOf(Tag::class, $persisted[0]);
        self::assertSame('Outil', $persisted[0]->getName());
        self::assertSame(['tag-jeu', $persisted[0]->getId()], $artifact->getTagIds());
    }

    public function testDescribesOnlyLiveTagsInOrder(): void
    {
        $dataset = new Dataset('Artefacts');
        $a = (new Tag('tag-a', $dataset))->setName('Instrument')->setColor('blue');
        $b = (new Tag('tag-b', $dataset))->setName('Audio');
        $gone = (new Tag('tag-c', $dataset))->setName('Ancienne')->setDeletedAt(new \DateTimeImmutable());
        $repo = $this->createStub(TagRepository::class);
        $repo->method('findChangedSince')->willReturn([$a, $b, $gone]);

        $artifact = new Artifact($dataset, new User('a@example.com'), 'theremine', 'Thérémine');
        $artifact->setTagIds(['tag-b', 'tag-c', 'tag-a', 'tag-inconnue']);
        $tags = new ArtifactTags($repo, $this->createStub(EntityManagerInterface::class));

        self::assertSame([
            ['id' => 'tag-b', 'name' => 'Audio', 'color' => 'default'],
            ['id' => 'tag-a', 'name' => 'Instrument', 'color' => 'blue'],
        ], $tags->describe($artifact));
    }
}
