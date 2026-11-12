<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ArtifactRun;
use App\Entity\ArtifactWorkflow;
use App\Entity\ArtifactWorkflowRun;
use App\Entity\User;
use App\Event\ArtifactRunFinished;
use App\Repository\ArtifactWorkflowRepository;
use App\Repository\ArtifactWorkflowRunRepository;
use App\Webhook\WebhookEventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Workflows d'artefacts : enchaînement d'artefacts-outils (contrat io), la sortie d'une étape
 * alimentant la suivante ({@see ArtifactWorkflowDefinition}).
 *
 * Les étapes headless s'exécutent aussitôt ; une étape interactive ou intégrée met le workflow en
 * attente (`waiting`, webhook artifact.workflow.waiting avec l'URL à donner) jusqu'à la fin de son
 * run ({@see ArtifactRunFinished}), puis le workflow reprend. Les runs s'exécutent au nom de la
 * personne qui a lancé le workflow.
 */
final class ArtifactWorkflowService
{
    private const SLUG = '/^[a-z0-9][a-z0-9-]{2,63}$/';

    public function __construct(
        private readonly ArtifactWorkflowRepository $workflows,
        private readonly ArtifactWorkflowRunRepository $runs,
        private readonly ArtifactRunService $artifactRuns,
        private readonly ArtifactService $artifacts,
        private readonly DatasetAccessService $access,
        private readonly StoreSimulator $jsonata,
        private readonly EntityManagerInterface $em,
        private readonly WebhookDispatcher $webhooks,
    ) {
    }

    /**
     * Crée ou remplace un workflow (jeu des artefacts de l'utilisateur).
     *
     * @return array<string, mixed>
     */
    public function save(User $user, string $slug, mixed $definition): array
    {
        if (!preg_match(self::SLUG, $slug)) {
            throw new BadRequestHttpException('slug : a-z, 0-9 et - (3 à 64 caractères).');
        }
        $definition = json_decode((string) json_encode($definition), true);
        $errors = ArtifactWorkflowDefinition::validate($definition);
        if ($errors === []) {
            foreach ($this->jsonata->checkExpressions(ArtifactWorkflowDefinition::expressions($definition)) as $e) {
                $errors[] = ['path' => $e['key'], 'message' => $e['message']];
            }
            foreach ($definition['steps'] as $i => $step) {
                $errors = [...$errors, ...$this->checkStepArtifact($user, $step, '/steps/'.$i)];
            }
        }
        if ($errors !== []) {
            throw new BadRequestHttpException('Workflow invalide : '.implode(' ; ', array_map(static fn (array $e): string => $e['path'].' '.$e['message'], \array_slice($errors, 0, 8))));
        }
        $dataset = $this->artifacts->artifactStoreFor($user);
        $this->access->assertCanWrite($user, $dataset);
        $title = \is_string($definition['title'] ?? null) ? $definition['title'] : $slug;
        $workflow = $this->workflows->findOneBySlug($dataset, $slug);
        if ($workflow === null) {
            $workflow = new ArtifactWorkflow($dataset, $slug, $title, $definition, $user);
            $this->em->persist($workflow);
        } else {
            $workflow->update($title, $definition);
        }
        $this->em->flush();

        return $this->serializeWorkflow($workflow);
    }

    /** @return list<array<string, mixed>> */
    public function list(User $user): array
    {
        $dataset = $this->artifacts->artifactStoreFor($user);

        return array_map(fn (ArtifactWorkflow $w): array => $this->serializeWorkflow($w, false), $this->workflows->findForDataset($dataset));
    }

    /** @return array<string, mixed> */
    public function get(User $user, string $slug): array
    {
        $workflow = $this->requireWorkflow($user, $slug);

        return $this->serializeWorkflow($workflow) + ['runs' => array_map(fn (ArtifactWorkflowRun $r): array => $this->serializeRun($r, false), $this->runs->findRecent($workflow, 10))];
    }

    public function delete(User $user, string $slug): void
    {
        $workflow = $this->requireWorkflow($user, $slug);
        $this->access->assertCanWrite($user, $workflow->getDataset());
        $this->em->remove($workflow);
        $this->em->flush();
    }

    /**
     * Lance un workflow ; renvoie son état après les étapes immédiates (headless).
     *
     * @return array<string, mixed>
     */
    public function start(User $user, string $slug, mixed $input): array
    {
        $workflow = $this->requireWorkflow($user, $slug);
        $definition = $workflow->getDefinition();
        $input ??= new \stdClass();
        if (\is_array($definition['input']['schema'] ?? null)) {
            $inputErrors = ArtifactIo::validateValue($definition['input']['schema'], $input, '');
            if ($inputErrors !== []) {
                throw new BadRequestHttpException('Entrée invalide : '.implode(' ', \array_slice($inputErrors, 0, 5)));
            }
        }
        $run = new ArtifactWorkflowRun($workflow, $definition, $user, $input);
        $this->em->persist($run);
        $this->em->flush();
        $this->advance($run);

        return $this->serializeRun($run);
    }

    /** @return array<string, mixed> */
    public function getRun(User $user, string $runId): array
    {
        return $this->serializeRun($this->requireRun($user, $runId));
    }

    /** @return array<string, mixed> */
    public function cancelRun(User $user, string $runId): array
    {
        $run = $this->requireRun($user, $runId);
        if ($run->isFinished()) {
            throw new ConflictHttpException('Ce workflow est déjà terminé.');
        }
        $run->finish(ArtifactWorkflowRun::STATUS_CANCELLED, null, ['Workflow annulé.']);
        $this->em->flush();
        // Étape en attente : son run est annulé aussi (le lien ne sert plus).
        foreach ($run->getSteps() as $state) {
            if (($state['status'] ?? null) === 'pending' && \is_string($state['runId'] ?? null)) {
                try {
                    $this->artifactRuns->cancel($user, $state['runId']);
                } catch (\Throwable) {
                }
            }
        }
        $this->notify($run, WebhookEventType::ARTIFACT_WORKFLOW_FAILED);

        return $this->serializeRun($run);
    }

    /** Reprise d'un workflow quand le run qu'il attendait se termine. */
    #[AsEventListener]
    public function onArtifactRunFinished(ArtifactRunFinished $event): void
    {
        $artifactRun = $event->run;
        $run = $artifactRun->getWorkflowRun();
        $step = $artifactRun->getWorkflowStep();
        if ($run === null || $step === null || $run->getStatus() !== ArtifactWorkflowRun::STATUS_WAITING) {
            return;
        }
        $state = $run->getSteps()[$step] ?? null;
        if (!\is_array($state) || ($state['runId'] ?? null) !== $artifactRun->getId()->toRfc4122()) {
            return;
        }
        ArtifactWorkflowEngine::resume($run, $step, $this->artifactRuns->serializeEntity($artifactRun));
        $this->em->flush();
        if ($run->isFinished()) {
            $this->notify($run, WebhookEventType::ARTIFACT_WORKFLOW_FAILED);

            return;
        }
        $this->advance($run);
    }

    /** Joue les étapes à partir du curseur, jusqu'à une attente, une erreur ou la fin. */
    private function advance(ArtifactWorkflowRun $run): void
    {
        $user = $run->getCreatedBy();
        if ($user === null) {
            $run->finish(ArtifactWorkflowRun::STATUS_FAILED, null, ['Le compte qui a lancé ce workflow n’existe plus.']);
            $this->em->flush();
            $this->notify($run, WebhookEventType::ARTIFACT_WORKFLOW_FAILED);

            return;
        }
        $event = ArtifactWorkflowEngine::advance(
            $run,
            fn (array $exprs, \stdClass $context): array => $this->jsonata->evaluate($exprs, $context),
            function (array $step, mixed $input) use ($user, $run): array {
                try {
                    return $this->artifactRuns->run($user, (string) $step['artifact'], $input, \is_string($step['mode'] ?? null) ? $step['mode'] : null, $run, (string) $step['id']);
                } catch (HttpExceptionInterface $e) {
                    return ['status' => ArtifactRun::STATUS_FAILED, 'output' => null, 'errors' => [$e->getMessage()]];
                }
            },
            fn () => $this->em->flush(),
        );
        $this->em->flush();
        $type = match ($event) {
            ArtifactWorkflowRun::STATUS_WAITING => WebhookEventType::ARTIFACT_WORKFLOW_WAITING,
            ArtifactWorkflowRun::STATUS_COMPLETED => WebhookEventType::ARTIFACT_WORKFLOW_COMPLETED,
            ArtifactWorkflowRun::STATUS_FAILED => WebhookEventType::ARTIFACT_WORKFLOW_FAILED,
            default => null,
        };
        if ($type !== null) {
            $this->notify($run, $type);
        }
    }

    /**
     * @param array<string, mixed> $step
     *
     * @return list<array{path: string, message: string}>
     */
    private function checkStepArtifact(User $user, array $step, string $path): array
    {
        try {
            $contract = $this->artifactRuns->contract($user, (string) $step['artifact']);
        } catch (HttpExceptionInterface) {
            return [['path' => $path.'/artifact', 'message' => sprintf('Artefact « %s » introuvable ou non lisible.', $step['artifact'])]];
        }
        if ($contract === null) {
            return [['path' => $path.'/artifact', 'message' => sprintf('La version en ligne de « %s » ne déclare pas de contrat io.', $step['artifact'])]];
        }
        $mode = \is_string($step['mode'] ?? null) ? $step['mode'] : 'headless';
        if (!\in_array($mode, $contract['modes'] ?? [], true)) {
            return [['path' => $path.'/mode', 'message' => sprintf('« %s » ne déclare pas le mode %s (io.modes : %s).', $step['artifact'], $mode, implode(', ', $contract['modes'] ?? []))]];
        }

        return [];
    }

    private function notify(ArtifactWorkflowRun $run, string $type): void
    {
        try {
            $this->webhooks->dispatch($run->getWorkflow()->getDataset(), $type, $this->serializeRun($run), $run->getCreatedBy());
        } catch (\Throwable) {
            // Un webhook en échec n'arrête pas le workflow.
        }
    }

    private function requireWorkflow(User $user, string $slug): ArtifactWorkflow
    {
        $dataset = $this->artifacts->artifactStoreFor($user);
        $workflow = $this->workflows->findOneBySlug($dataset, $slug);
        if ($workflow === null || $this->access->getRole($user, $workflow->getDataset()) === null) {
            throw new NotFoundHttpException('Workflow introuvable.');
        }

        return $workflow;
    }

    private function requireRun(User $user, string $runId): ArtifactWorkflowRun
    {
        $run = Uuid::isValid($runId) ? $this->runs->find(Uuid::fromString($runId)) : null;
        if (!$run instanceof ArtifactWorkflowRun || $this->access->getRole($user, $run->getWorkflow()->getDataset()) === null) {
            throw new NotFoundHttpException('Run de workflow introuvable.');
        }

        return $run;
    }

    /** @return array<string, mixed> */
    private function serializeWorkflow(ArtifactWorkflow $workflow, bool $withDefinition = true): array
    {
        $out = [
            'id' => $workflow->getId()->toRfc4122(),
            'slug' => $workflow->getSlug(),
            'title' => $workflow->getTitle(),
            'steps' => array_map(static fn (array $s): string => (string) $s['id'], $workflow->getDefinition()['steps'] ?? []),
            'updatedAt' => $workflow->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
        if ($withDefinition) {
            $out['definition'] = $workflow->getDefinition();
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function serializeRun(ArtifactWorkflowRun $run, bool $withData = true): array
    {
        $out = [
            'workflowRunId' => $run->getId()->toRfc4122(),
            'workflow' => $run->getWorkflow()->getSlug(),
            'status' => $run->getStatus(),
            'cursor' => $run->getCursor(),
            'createdAt' => $run->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'completedAt' => $run->getCompletedAt()?->format(\DateTimeInterface::ATOM),
            'errors' => $run->getErrors(),
        ];
        $steps = $run->getSteps();
        $waiting = null;
        foreach ($steps as $id => $state) {
            if (($state['status'] ?? null) === 'pending') {
                $waiting = ['step' => $id] + array_intersect_key($state, array_flip(['runId', 'mode', 'url', 'runToken', 'linkKey', 'expiresAt']));
            }
        }
        if ($waiting !== null && $run->getStatus() === ArtifactWorkflowRun::STATUS_WAITING) {
            $out['waiting'] = $waiting;
        }
        if ($withData) {
            $out['input'] = $run->getInput();
            // Le jeton n'est rendu que dans `waiting` (étape en cours).
            $out['steps'] = array_map(static fn (array $s): array => array_diff_key($s, ['runToken' => true]), $steps);
            $out['output'] = $run->getOutput();
        }

        return $out;
    }
}
