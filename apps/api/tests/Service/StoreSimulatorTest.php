<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\StoreSimulator;
use PHPUnit\Framework\TestCase;

final class StoreSimulatorTest extends TestCase
{
    private StoreSimulator $simulator;

    protected function setUp(): void
    {
        $dir = \dirname(__DIR__, 2).'/tools/simulate-store';
        if (!is_dir($dir.'/node_modules/jsonata')) {
            self::markTestSkipped('Dépendances Node du simulateur non installées (npm install dans tools/simulate-store).');
        }
        $this->simulator = new StoreSimulator($dir);
    }

    public function testRunsActionsUntilCondition(): void
    {
        $doc = ['data' => ['stores' => ['c' => [
            'initial' => ['n' => 0],
            'reducer' => '$action.type="inc" ? $merge([$state, {"n": $state.n + 1}]) : $state',
        ]]]];
        $r = $this->simulator->simulate([
            'document' => $doc,
            'store' => 'c',
            'actions' => [['type' => 'inc', 'repeat' => 50]],
            'until' => 'n >= 5',
            'snapshots' => ['n'],
        ]);

        self::assertTrue($r['ok']);
        self::assertSame(5, $r['steps']);
        self::assertSame(5, $r['finalState']['n']);
        self::assertSame(['n' => 5], end($r['snapshots']));
    }

    public function testUnknownStoreIsReported(): void
    {
        $r = $this->simulator->simulate(['document' => ['data' => ['stores' => []]], 'store' => 'x', 'actions' => [['type' => 'a']]]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('introuvable', $r['errors'][0]);
    }
}
