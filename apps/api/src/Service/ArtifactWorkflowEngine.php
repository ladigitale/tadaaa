<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ArtifactRun;
use App\Entity\ArtifactWorkflowRun;

/**
 * Pas à pas d'un workflow d'artefacts, sans I/O : l'évaluation JSONata et l'exécution d'une étape
 * sont fournies par l'appelant ({@see ArtifactWorkflowService}), ce qui rend la logique testable.
 */
final class ArtifactWorkflowEngine
{
    /**
     * Joue les étapes à partir du curseur, jusqu'à une attente, une erreur ou la fin.
     *
     * @param callable(array<string, string>, \stdClass): array{results: array<string, mixed>, errors: list<array{key: string, message: string}>} $evaluate
     * @param callable(array<string, mixed>, mixed): array<string, mixed>                                                                       $runStep  run sérialisé
     * @param callable(): void                                                                                                                  $persist
     *
     * @return string|null statut atteint à signaler (waiting, completed, failed) ; null si rien à signaler
     */
    public static function advance(ArtifactWorkflowRun $run, callable $evaluate, callable $runStep, callable $persist): ?string
    {
        $definition = $run->getDefinition();
        $steps = \is_array($definition['steps'] ?? null) ? array_values($definition['steps']) : [];
        if (!$run->isFinished() && $run->getStatus() !== ArtifactWorkflowRun::STATUS_RUNNING) {
            $run->setStatus(ArtifactWorkflowRun::STATUS_RUNNING);
        }
        while (!$run->isFinished() && $run->getCursor() < \count($steps)) {
            $index = $run->getCursor();
            $step = $steps[$index];
            $id = (string) $step['id'];
            $context = ArtifactWorkflowDefinition::context($run->getInput(), $run->getSteps());
            $exprs = [];
            if (\is_string($step['if'] ?? null)) {
                $exprs['if'] = $step['if'];
            }
            $exprs['input'] = \is_string($step['input'] ?? null) ? $step['input'] : ($index === 0 ? 'input' : 'previous');
            $eval = $evaluate($exprs, $context);
            if ($eval['errors'] !== []) {
                if (self::stepFailed($run, $step, ['JSONata ('.$eval['errors'][0]['key'].') : '.$eval['errors'][0]['message']])) {
                    return ArtifactWorkflowRun::STATUS_FAILED;
                }
                continue;
            }
            if (isset($exprs['if']) && ($eval['results']['if'] ?? null) !== true) {
                $run->setStep($id, ['status' => 'skipped', 'output' => null]);
                $run->setCursor($index + 1);
                $persist();
                continue;
            }
            $input = $eval['results']['input'] ?? new \stdClass();
            if ($input === []) {
                $input = new \stdClass();
            }
            $result = $runStep($step, $input);
            if (($result['status'] ?? null) === ArtifactRun::STATUS_PENDING) {
                $run->setStep($id, array_filter([
                    'status' => 'pending',
                    'runId' => $result['runId'] ?? null,
                    'mode' => $result['mode'] ?? null,
                    'url' => $result['url'] ?? null,
                    'runToken' => $result['runToken'] ?? null,
                    'linkKey' => $result['linkKey'] ?? null,
                    'expiresAt' => $result['expiresAt'] ?? null,
                ], static fn (mixed $v): bool => $v !== null));
                $run->setStatus(ArtifactWorkflowRun::STATUS_WAITING);
                $persist();

                return ArtifactWorkflowRun::STATUS_WAITING;
            }
            self::record($run, $id, $result);
            if (($result['status'] ?? null) !== ArtifactRun::STATUS_COMPLETED) {
                if (self::stepFailed($run, $step, array_values(array_map('strval', (array) ($result['errors'] ?? []))), recorded: true)) {
                    return ArtifactWorkflowRun::STATUS_FAILED;
                }
                continue;
            }
            $run->setCursor($index + 1);
            $persist();
        }
        if ($run->isFinished()) {
            return null;
        }

        $context = ArtifactWorkflowDefinition::context($run->getInput(), $run->getSteps());
        $output = $context->previous;
        if (\is_string($definition['output'] ?? null)) {
            $eval = $evaluate(['output' => $definition['output']], $context);
            if ($eval['errors'] !== []) {
                $run->finish(ArtifactWorkflowRun::STATUS_FAILED, null, ['Sortie du workflow (JSONata) : '.$eval['errors'][0]['message']]);

                return ArtifactWorkflowRun::STATUS_FAILED;
            }
            $output = $eval['results']['output'] ?? null;
        }
        $run->finish(ArtifactWorkflowRun::STATUS_COMPLETED, $output);

        return ArtifactWorkflowRun::STATUS_COMPLETED;
    }

    /**
     * Enregistre la fin du run qu'attendait l'étape `$stepId` et avance le curseur ; un échec non
     * optionnel termine le workflow (`failed`).
     *
     * @param array<string, mixed> $result run sérialisé
     */
    public static function resume(ArtifactWorkflowRun $run, string $stepId, array $result): void
    {
        $steps = array_values($run->getDefinition()['steps'] ?? []);
        $step = $steps[$run->getCursor()] ?? null;
        if (!\is_array($step) || ($step['id'] ?? null) !== $stepId) {
            return;
        }
        self::record($run, $stepId, $result);
        if (($result['status'] ?? null) !== ArtifactRun::STATUS_COMPLETED) {
            if (self::stepFailed($run, $step, array_values(array_map('strval', (array) ($result['errors'] ?? []))), recorded: true)) {
                return;
            }
        } else {
            $run->setCursor($run->getCursor() + 1);
        }
        $run->setStatus(ArtifactWorkflowRun::STATUS_RUNNING);
    }

    /** @param array<string, mixed> $result */
    private static function record(ArtifactWorkflowRun $run, string $id, array $result): void
    {
        $state = ['status' => $result['status'] ?? null, 'runId' => $result['runId'] ?? null, 'output' => $result['output'] ?? null];
        if (!empty($result['files'])) {
            $state['files'] = $result['files'];
        }
        if (!empty($result['errors'])) {
            $state['errors'] = $result['errors'];
        }
        $run->setStep($id, $state);
    }

    /**
     * @param array<string, mixed> $step
     * @param list<string>         $errors
     *
     * @return bool vrai si le workflow s'arrête (étape non optionnelle)
     */
    private static function stepFailed(ArtifactWorkflowRun $run, array $step, array $errors, bool $recorded = false): bool
    {
        $id = (string) $step['id'];
        if (!$recorded) {
            $run->setStep($id, ['status' => 'failed', 'output' => null, 'errors' => $errors]);
        }
        if (($step['optional'] ?? false) === true) {
            $run->setCursor($run->getCursor() + 1);

            return false;
        }
        $run->finish(ArtifactWorkflowRun::STATUS_FAILED, null, array_map(static fn (string $e): string => sprintf('Étape « %s » : %s', $id, $e), $errors ?: ['échec']));

        return true;
    }
}
