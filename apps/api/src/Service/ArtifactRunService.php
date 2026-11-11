<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ArtifactRun;
use App\Entity\User;
use App\Repository\ArtifactRunRepository;
use App\Webhook\WebhookEventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Artefact utilisé comme outil : contrat `io` (voir {@see ArtifactIo}), runs et sortie.
 *
 * Mode headless : l'entrée est validée, envoyée au réducteur JSONata du store d'entrée (même
 * sous-processus bridé que simulate_store), la sortie lue dans `output.from` puis validée.
 * Chaque run est enregistré (get_artifact_run) et signalé par webhook
 * (artifact.run.completed / artifact.run.failed).
 */
final class ArtifactRunService
{
    public function __construct(
        private readonly ArtifactService $artifacts,
        private readonly StoreSimulator $simulator,
        private readonly ArtifactRunRepository $runs,
        private readonly DatasetAccessService $access,
        private readonly EntityManagerInterface $em,
        private readonly WebhookDispatcher $webhooks,
    ) {
    }

    /**
     * Artefacts lisibles par l'utilisateur qui déclarent un contrat `io`.
     *
     * @return list<array<string, mixed>>
     */
    public function listTools(User $user, ?string $datasetId = null): array
    {
        $out = [];
        foreach ($this->artifacts->listForUser($user, $datasetId) as $summary) {
            $ref = \is_string($summary['slug'] ?? null) ? $summary['slug'] : (string) ($summary['id'] ?? '');
            if ($ref === '') {
                continue;
            }
            try {
                $contract = $this->contract($user, $ref);
            } catch (\Throwable) {
                continue;
            }
            if ($contract !== null) {
                $out[] = ['slug' => $summary['slug'] ?? null, 'id' => $summary['id'] ?? null] + $contract;
            }
        }

        return $out;
    }

    /**
     * Contrat publié d'un artefact (null : pas de bloc `io`).
     *
     * @return array<string, mixed>|null
     */
    public function contract(User $user, string $idOrSlug): ?array
    {
        $detail = $this->artifacts->getForUser($user, $idOrSlug);
        $document = ArtifactFiles::expand(json_decode((string) json_encode($detail['document'] ?? []), true));

        return \is_array($document) ? ArtifactIo::describe($document) : null;
    }

    /**
     * Lance un run. `mode` vide = headless.
     *
     * @return array<string, mixed>
     */
    public function run(User $user, string $idOrSlug, mixed $input, ?string $mode = null): array
    {
        $mode = $mode ?: 'headless';
        if (!\in_array($mode, ArtifactIo::MODES, true)) {
            throw new BadRequestHttpException('mode : '.implode(', ', ArtifactIo::MODES).'.');
        }
        $detail = $this->artifacts->getForUser($user, $idOrSlug);
        // Forme « wire » (les {} restent des objets) pour le réducteur, forme tableau pour lire io.
        $wire = ArtifactFiles::expand(json_decode((string) json_encode($detail['document'] ?? new \stdClass()), false));
        $document = json_decode((string) json_encode($wire), true);
        $io = \is_array($document) ? ($document['io'] ?? null) : null;
        if (!\is_array($io)) {
            throw new BadRequestHttpException('Cet artefact ne déclare pas de contrat io (voir get_artifact_catalog rules=["io"]).');
        }
        $declErrors = ArtifactIo::validateDeclaration($io, $document);
        if ($declErrors !== []) {
            throw new BadRequestHttpException('Contrat io invalide : '.$declErrors[0]['path'].' '.$declErrors[0]['message']);
        }
        if (!\in_array($mode, $io['modes'], true)) {
            throw new BadRequestHttpException(sprintf('Mode « %s » non déclaré par l’artefact (io.modes : %s).', $mode, implode(', ', $io['modes'])));
        }
        if ($mode !== 'headless') {
            throw new BadRequestHttpException(sprintf('Le mode « %s » n’est pas encore exécutable par l’API : seul headless l’est pour l’instant.', $mode));
        }

        $input ??= new \stdClass();
        if (\is_array($io['input']['schema'] ?? null)) {
            $inputErrors = ArtifactIo::validateValue($io['input']['schema'], $input, '');
            if ($inputErrors !== []) {
                throw new BadRequestHttpException('Entrée invalide : '.implode(' ', \array_slice($inputErrors, 0, 5)));
            }
        }

        $artifact = $this->artifacts->findArtifactEntity($idOrSlug);
        $run = new ArtifactRun($artifact, (int) ($detail['version'] ?? $artifact->getCurrentVersion()), $user, $mode, $input);
        $this->executeHeadless($run, $wire, $io, $input);

        $this->em->persist($run);
        $this->em->flush();
        $this->notify($run, $user);

        return $this->serialize($run);
    }

    /** @return array<string, mixed> */
    public function get(User $user, string $runId): array
    {
        return $this->serialize($this->requireRun($user, $runId));
    }

    /** @return list<array<string, mixed>> */
    public function listRuns(User $user, string $idOrSlug, int $limit = 20): array
    {
        $this->artifacts->getForUser($user, $idOrSlug);
        $artifact = $this->artifacts->findArtifactEntity($idOrSlug);

        return array_map(fn (ArtifactRun $r): array => $this->serialize($r, false), $this->runs->findRecentForArtifact($artifact, $limit));
    }

    /**
     * @param array<string, mixed> $io
     */
    private function executeHeadless(ArtifactRun $run, mixed $wire, array $io, mixed $input): void
    {
        $plan = ArtifactIo::headlessPlan($io, $input);
        $result = $this->simulator->simulate([
            'document' => $wire,
            'store' => $plan['store'],
            'actions' => $plan['actions'],
            'until' => $plan['until'],
            'maxSteps' => $plan['maxSteps'],
        ]);
        $metrics = array_filter([
            'steps' => $result['steps'] ?? null,
            'msPerStep' => $result['metrics']['msPerStep'] ?? null,
            'stateBytes' => $result['metrics']['stateBytes'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);
        if (($result['ok'] ?? false) !== true) {
            $run->finish(ArtifactRun::STATUS_FAILED, null, array_values(array_map('strval', (array) ($result['errors'] ?? ['Échec du réducteur.']))), $metrics);

            return;
        }
        $output = ArtifactIo::pluck($result['finalState'] ?? null, $plan['path']);
        if ($plan['until'] !== null && ($result['untilReached'] ?? false) !== true) {
            $run->finish(ArtifactRun::STATUS_INCOMPLETE, $output, [sprintf('Condition io.output.when jamais vraie en %d pas (augmenter io.headless.maxSteps ou ajouter des actions).', (int) ($result['steps'] ?? 0))], $metrics);

            return;
        }
        $outputErrors = \is_array($io['output']['schema'] ?? null) ? ArtifactIo::validateValue($io['output']['schema'], $output, '') : [];
        if ($outputErrors !== []) {
            $run->finish(ArtifactRun::STATUS_FAILED, $output, array_map(static fn (string $e): string => 'Sortie non conforme : '.$e, \array_slice($outputErrors, 0, 10)), $metrics);

            return;
        }
        $run->finish(ArtifactRun::STATUS_COMPLETED, $output, [], $metrics);
    }

    private function notify(ArtifactRun $run, User $user): void
    {
        $type = $run->getStatus() === ArtifactRun::STATUS_COMPLETED ? WebhookEventType::ARTIFACT_RUN_COMPLETED : WebhookEventType::ARTIFACT_RUN_FAILED;
        $this->webhooks->dispatch($run->getArtifact()->getDataset(), $type, $this->serialize($run), $user);
    }

    private function requireRun(User $user, string $runId): ArtifactRun
    {
        $run = Uuid::isValid($runId) ? $this->runs->find(Uuid::fromString($runId)) : null;
        if (!$run instanceof ArtifactRun || $this->access->getRole($user, $run->getArtifact()->getDataset()) === null) {
            throw new NotFoundHttpException('Run introuvable.');
        }

        return $run;
    }

    /** @return array<string, mixed> */
    private function serialize(ArtifactRun $run, bool $withData = true): array
    {
        $out = [
            'runId' => $run->getId()->toRfc4122(),
            'artifactId' => $run->getArtifact()->getId()->toRfc4122(),
            'slug' => $run->getArtifact()->getSlug(),
            'version' => $run->getArtifactVersion(),
            'mode' => $run->getMode(),
            'status' => $run->getStatus(),
            'createdAt' => $run->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'completedAt' => $run->getCompletedAt()?->format(\DateTimeInterface::ATOM),
            'errors' => $run->getErrors(),
            'metrics' => (object) $run->getMetrics(),
        ];
        if ($withData) {
            $out['input'] = $run->getInput();
            $out['output'] = $run->getOutput();
        }

        return $out;
    }
}
