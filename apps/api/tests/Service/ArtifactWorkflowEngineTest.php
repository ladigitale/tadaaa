<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ArtifactWorkflow;
use App\Entity\ArtifactWorkflowRun;
use App\Service\ArtifactWorkflowDefinition;
use App\Service\ArtifactWorkflowEngine;
use App\Service\StoreSimulator;
use PHPUnit\Framework\TestCase;

final class ArtifactWorkflowEngineTest extends TestCase
{
    private StoreSimulator $jsonata;
    /** @var list<array{artifact: string, input: mixed}> */
    private array $calls = [];

    protected function setUp(): void
    {
        $dir = \dirname(__DIR__, 2).'/tools/simulate-store';
        if (!is_dir($dir.'/node_modules/jsonata')) {
            self::markTestSkipped('jsonata non installé (tools/simulate-store).');
        }
        $this->jsonata = new StoreSimulator($dir);
        $this->calls = [];
    }

    /** @param array<string, mixed> $definition */
    private function newRun(array $definition, mixed $input): ArtifactWorkflowRun
    {
        self::assertSame([], ArtifactWorkflowDefinition::validate($definition), 'définition valide');
        $workflow = (new \ReflectionClass(ArtifactWorkflow::class))->newInstanceWithoutConstructor();

        return new ArtifactWorkflowRun($workflow, $definition, null, json_decode((string) json_encode($input), false));
    }

    /**
     * Étapes simulées : « somme » additionne values, « double » double n, « humain » attend.
     *
     * @return array<string, mixed>
     */
    private function fakeRun(array $step, mixed $input): array
    {
        $in = json_decode((string) json_encode($input), true);
        $this->calls[] = ['artifact' => $step['artifact'], 'input' => $in];

        return match ($step['artifact']) {
            'somme' => ['status' => 'completed', 'runId' => 'r-somme', 'output' => array_sum($in['values'] ?? [])],
            'double' => ['status' => 'completed', 'runId' => 'r-double', 'output' => ['n' => 2 * ($in['n'] ?? 0)]],
            'humain' => ['status' => 'pending', 'runId' => 'r-humain', 'mode' => 'interactive', 'url' => 'https://a/x?run=r-humain&rt=t', 'runToken' => 't'],
            default => ['status' => 'failed', 'runId' => 'r-ko', 'output' => null, 'errors' => ['boum']],
        };
    }

    private function advance(ArtifactWorkflowRun $run): ?string
    {
        return ArtifactWorkflowEngine::advance(
            $run,
            fn (array $exprs, \stdClass $ctx): array => $this->jsonata->evaluate($exprs, $ctx),
            fn (array $step, mixed $input): array => $this->fakeRun($step, $input),
            static function (): void {},
        );
    }

    public function testChainsOutputsAndComputesFinalOutput(): void
    {
        $run = $this->newRun([
            'steps' => [
                ['id' => 'a', 'artifact' => 'somme'],
                ['id' => 'b', 'artifact' => 'double', 'input' => "{'n': steps.a.output}"],
            ],
            'output' => "{'total': steps.a.output, 'double': steps.b.output.n}",
        ], ['values' => [1, 2, 3]]);

        self::assertSame(ArtifactWorkflowRun::STATUS_COMPLETED, $this->advance($run));
        self::assertSame(['values' => [1, 2, 3]], $this->calls[0]['input']);
        self::assertSame(['n' => 6], $this->calls[1]['input']);
        self::assertSame(['total' => 6, 'double' => 12], json_decode((string) json_encode($run->getOutput()), true));
    }

    public function testDefaultOutputIsPreviousAndIfSkipsSteps(): void
    {
        $run = $this->newRun([
            'steps' => [
                ['id' => 'a', 'artifact' => 'somme'],
                ['id' => 'b', 'artifact' => 'double', 'if' => 'steps.a.output > 100', 'input' => "{'n': steps.a.output}"],
            ],
        ], ['values' => [1, 2]]);

        self::assertSame(ArtifactWorkflowRun::STATUS_COMPLETED, $this->advance($run));
        self::assertCount(1, $this->calls);
        self::assertSame('skipped', $run->getSteps()['b']['status']);
        self::assertSame(3, $run->getOutput());
    }

    public function testWaitsThenResumes(): void
    {
        $run = $this->newRun([
            'steps' => [
                ['id' => 'h', 'artifact' => 'humain', 'mode' => 'interactive'],
                ['id' => 'b', 'artifact' => 'double', 'input' => "{'n': steps.h.output.score}"],
            ],
        ], []);

        self::assertSame(ArtifactWorkflowRun::STATUS_WAITING, $this->advance($run));
        self::assertSame(ArtifactWorkflowRun::STATUS_WAITING, $run->getStatus());
        self::assertSame('pending', $run->getSteps()['h']['status']);

        ArtifactWorkflowEngine::resume($run, 'h', ['status' => 'completed', 'runId' => 'r-humain', 'output' => ['score' => 21]]);
        self::assertSame(1, $run->getCursor());
        self::assertSame(ArtifactWorkflowRun::STATUS_COMPLETED, $this->advance($run));
        self::assertSame(['n' => 42], json_decode((string) json_encode($run->getOutput()), true));
    }

    public function testFailureStopsUnlessOptional(): void
    {
        $failing = $this->newRun(['steps' => [['id' => 'x', 'artifact' => 'casse'], ['id' => 'a', 'artifact' => 'somme']]], ['values' => [1]]);
        self::assertSame(ArtifactWorkflowRun::STATUS_FAILED, $this->advance($failing));
        self::assertStringContainsString('« x »', $failing->getErrors()[0]);
        self::assertCount(1, $this->calls);

        $this->calls = [];
        $optional = $this->newRun(['steps' => [['id' => 'x', 'artifact' => 'casse', 'optional' => true], ['id' => 'a', 'artifact' => 'somme', 'input' => 'input']]], ['values' => [4]]);
        self::assertSame(ArtifactWorkflowRun::STATUS_COMPLETED, $this->advance($optional));
        self::assertSame(4, $optional->getOutput());
    }

    public function testExpiredInteractiveStepFailsTheWorkflow(): void
    {
        $run = $this->newRun(['steps' => [['id' => 'h', 'artifact' => 'humain', 'mode' => 'interactive']]], []);
        $this->advance($run);
        ArtifactWorkflowEngine::resume($run, 'h', ['status' => 'expired', 'runId' => 'r-humain', 'errors' => ['Délai dépassé']]);
        self::assertSame(ArtifactWorkflowRun::STATUS_FAILED, $run->getStatus());
    }

    public function testDefinitionValidation(): void
    {
        self::assertNotSame([], ArtifactWorkflowDefinition::validate(['steps' => []]));
        self::assertNotSame([], ArtifactWorkflowDefinition::validate(['steps' => [['id' => 'a', 'artifact' => 'somme'], ['id' => 'a', 'artifact' => 'somme']]]));
        self::assertNotSame([], ArtifactWorkflowDefinition::validate(['steps' => [['id' => 'input', 'artifact' => 'somme']]]));
        self::assertNotSame([], ArtifactWorkflowDefinition::validate(['steps' => [['id' => 'a', 'artifact' => 'somme', 'mode' => 'magique']]]));
        self::assertSame(['/steps/0/if' => 'x > 1', '/output' => 'steps'], ArtifactWorkflowDefinition::expressions(['steps' => [['id' => 'a', 'artifact' => 'somme', 'if' => 'x > 1']], 'output' => 'steps']));
    }
}
