<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Artifact;
use App\Entity\ArtifactPlace;
use App\Entity\ArtifactPlaceMessage;
use PHPUnit\Framework\TestCase;

final class ArtifactPlaceMessageEditTest extends TestCase
{
    private function place(): ArtifactPlace
    {
        $artifact = (new \ReflectionClass(Artifact::class))->newInstanceWithoutConstructor();

        return new ArtifactPlace($artifact, 'balises', 47.3, 0.6, ['message' => 'salut'], [], 40, 'hash', 'ip');
    }

    public function testDeletedKeepsATraceWithoutContent(): void
    {
        $m = new ArtifactPlaceMessage($this->place(), 'drawing', '', 'data:image/svg+xml;base64,PHN2Zy8+', 'Ju', 'ip', 'tok', true, 3);
        $m->markDeleted('auteur');
        self::assertTrue($m->isDeleted());
        self::assertSame('auteur', $m->getDeletedBy());
        self::assertNull($m->getMedia());
        self::assertSame('', $m->getText());
        $n = new ArtifactPlaceMessage($this->place(), 'text', 'x', null, null, 'ip', 'tok');
        $n->markDeleted('n’importe quoi');
        self::assertSame('auteur', $n->getDeletedBy());
        $n->markDeleted('moderation');
        self::assertSame('moderation', $n->getDeletedBy());
    }

    public function testEditKeepsKindAndMarksEdited(): void
    {
        $t = new ArtifactPlaceMessage($this->place(), 'text', 'avant', null, null, 'ip', 'tok');
        $t->edit('après', 'data:image/png;base64,AA', 2, 'ref-1');
        self::assertSame('après', $t->getText());
        self::assertNull($t->getMedia(), 'un texte ne prend pas de média');
        self::assertNull($t->getFrame());
        self::assertNull($t->getRefId());
        self::assertNotNull($t->getEditedAt());

        $d = new ArtifactPlaceMessage($this->place(), 'drawing', '', 'data:image/svg+xml;base64,AA', null, 'ip', 'tok', true, 3);
        $d->edit('', 'data:image/svg+xml;base64,BB', 9, 'mur-2');
        self::assertSame('data:image/svg+xml;base64,BB', $d->getMedia());
        self::assertSame(5, $d->getFrame());
        self::assertSame('mur-2', $d->getRefId());
        $d->edit('', null, null, null);
        self::assertSame('data:image/svg+xml;base64,BB', $d->getMedia(), 'sans nouveau média, le dessin reste');
    }
}
