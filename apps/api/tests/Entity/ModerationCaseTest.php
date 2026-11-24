<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Artifact;
use App\Entity\ModerationCase;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ModerationCaseTest extends TestCase
{
    public function testReportsReopenAKeptCaseButNotAHiddenOne(): void
    {
        $a = (new \ReflectionClass(Artifact::class))->newInstanceWithoutConstructor();
        $c = new ModerationCase($a, 'message', Uuid::v7());
        self::assertSame('open', $c->getStatus());
        $c->reported(1);
        $c->reported(1);
        self::assertSame(2, $c->getReports(), 'compte les signalements même si l\'entité en voit moins');
        $c->decide('kept', new User('boss@example.com'));
        self::assertSame(0, $c->getReports());
        $c->reported(1);
        self::assertSame('open', $c->getStatus(), 'rouvert après « laisser »');
        $c->decide('hidden', null);
        $c->reported(4);
        self::assertSame('hidden', $c->getStatus(), 'déjà masqué : reste réglé');
        self::assertSame(4, $c->getReports());
    }
}
