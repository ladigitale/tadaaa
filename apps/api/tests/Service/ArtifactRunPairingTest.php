<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ArtifactIo;
use App\Service\ArtifactRunPairingService;
use App\Service\ArtifactStream;
use PHPUnit\Framework\TestCase;

final class ArtifactRunPairingTest extends TestCase
{
    /** @return list<array{path: string, message: string}> */
    private static function check(array $stream): array
    {
        $doc = [
            'schema' => 'artifacts/1',
            'title' => 'Régie',
            'defaultView' => 'home',
            'views' => [['id' => 'home', 'title' => 'Accueil', 'root' => ['nodes' => []]]],
            'data' => ['stores' => ['scene' => ['initial' => ['n' => 0], 'reducer' => '$state'], 'out' => ['initial' => ['q' => []], 'reducer' => '$state']]],
            'io' => [
                'modes' => ['interactive'],
                'output' => ['schema' => ['type' => 'number'], 'from' => 'scene.n'],
                'stream' => $stream,
            ],
        ];

        return ArtifactIo::validateDeclaration($doc['io'], $doc);
    }

    public function testValidPair(): void
    {
        self::assertSame([], self::check(['store' => 'scene', 'actions' => ['vote'], 'pair' => ['remote' => 'telecommande', 'map' => 'data', 'max' => 12, 'ttl' => 600, 'input' => ['equipe' => 'bleue']]]));
        self::assertSame([], self::check(['store' => 'scene', 'actions' => ['vote'], 'emit' => ['from' => 'out.q', 'ack' => 'out'], 'pair' => ['remote' => 'ecran', 'back' => 'data']]));
        $d = ArtifactStream::describe(['store' => 'scene', 'actions' => ['vote'], 'pair' => ['remote' => 'telecommande']]);
        self::assertSame(['remote' => 'telecommande', 'max' => ArtifactStream::DEFAULT_PAIRS], $d['pair']);
    }

    public function testPairErrors(): void
    {
        $paths = static fn (array $errors): array => array_column($errors, 'path');
        self::assertContains('/io/stream/pair/remote', $paths(self::check(['store' => 'scene', 'actions' => ['a'], 'pair' => ['remote' => 'Pas un slug']])));
        self::assertContains('/io/stream/pair/max', $paths(self::check(['store' => 'scene', 'actions' => ['a'], 'pair' => ['remote' => 'r', 'max' => 100]])));
        self::assertContains('/io/stream/pair/ttl', $paths(self::check(['store' => 'scene', 'actions' => ['a'], 'pair' => ['remote' => 'r', 'ttl' => 5]])));
        self::assertContains('/io/stream/pair/back', $paths(self::check(['store' => 'scene', 'actions' => ['a'], 'pair' => ['remote' => 'r', 'back' => 'data']])), 'back sans file d’envoi');
        self::assertContains('/io/stream/pair', $paths(self::check(['emit' => ['from' => 'out.q'], 'pair' => ['remote' => 'r']])), 'un hôte doit accepter des messages');
        self::assertContains('/io/stream/pair/oops', $paths(self::check(['store' => 'scene', 'actions' => ['a'], 'pair' => ['remote' => 'r', 'oops' => 1]])));
        self::assertContains('/io/stream/pair/map', $paths(self::check(['store' => 'scene', 'actions' => ['a'], 'pair' => ['remote' => 'r', 'map' => str_repeat('x', ArtifactStream::MAX_PAIR_MAP + 1)]])));
    }

    public function testPairRoles(): void
    {
        $stream = ['store' => 'scene', 'actions' => ['vote'], 'emit' => ['from' => 'out.q', 'ack' => 'out'], 'pair' => [
            ['role' => 'rouge', 'remote' => 'ecran', 'back' => 'data', 'input' => ['equipe' => 'rouge'], 'max' => 4],
            ['role' => 'bleu', 'remote' => 'ecran', 'back' => 'data', 'input' => ['equipe' => 'bleu']],
        ]];
        self::assertSame([], self::check($stream));
        self::assertSame(['rouge', 'bleu'], array_keys(ArtifactStream::pairEntries($stream)));
        self::assertSame([['role' => 'rouge', 'remote' => 'ecran', 'max' => 4], ['role' => 'bleu', 'remote' => 'ecran', 'max' => ArtifactStream::DEFAULT_PAIRS]], ArtifactStream::describe($stream)['pair']);
        self::assertSame([''], array_keys(ArtifactStream::pairEntries(['pair' => ['remote' => 'ecran']])));

        $paths = static fn (array $errors): array => array_column($errors, 'path');
        $base = ['store' => 'scene', 'actions' => ['a']];
        self::assertContains('/io/stream/pair/0/role', $paths(self::check($base + ['pair' => [['remote' => 'r']]])), 'rôle obligatoire dans une liste');
        self::assertContains('/io/stream/pair/1/role', $paths(self::check($base + ['pair' => [['role' => 'a', 'remote' => 'r'], ['role' => 'a', 'remote' => 'r']]])), 'rôle en double');
        self::assertContains('/io/stream/pair/role', $paths(self::check($base + ['pair' => ['role' => 'a', 'remote' => 'r']])), 'pas de rôle dans la forme objet');
        self::assertContains('/io/stream/pair', $paths(self::check($base + ['pair' => array_fill(0, 5, ['role' => 'x', 'remote' => 'r'])])), '4 rôles au plus');
    }

    public function testCodes(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $c = ArtifactRunPairingService::newCode();
            self::assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', $c);
            $seen[$c] = true;
        }
        self::assertGreaterThan(195, \count($seen));
    }
}
