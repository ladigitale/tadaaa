<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ArtifactRun;
use App\Entity\ArtifactVisibility;
use App\Entity\ArtifactWorkflowRun;
use App\Event\ArtifactRunFinished;
use App\Entity\User;
use App\Repository\ArtifactRunRepository;
use App\Webhook\WebhookEventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
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
        private readonly UsageMeter $usage,
        private readonly ArtifactRunFiles $files,
        private readonly EventDispatcherInterface $events,
        #[Autowire(service: 'limiter.artifact_runs')]
        private readonly RateLimiterFactoryInterface $limiter,
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

    public function slugFor(string $idOrSlug): string
    {
        return $this->artifacts->findArtifactEntity($idOrSlug)->getSlug();
    }

    /**
     * Contrat publié d'un artefact (null : pas de bloc `io`).
     *
     * @return array<string, mixed>|null
     */
    public function contract(User $user, string $idOrSlug): ?array
    {
        $this->artifacts->getForUser($user, $idOrSlug);
        $artifact = $this->artifacts->findArtifactEntity($idOrSlug);
        // Contrat de la version en ligne : celle qu'exécutent les runs.
        $detail = $this->artifacts->getForUser($user, $idOrSlug, $artifact->getLiveVersion());
        $document = ArtifactFiles::expand(json_decode((string) json_encode($detail['document'] ?? []), true));
        $contract = \is_array($document) ? ArtifactIo::describe($document) : null;

        return $contract === null ? null : $contract + ['openapi' => '/api/artifacts/'.$artifact->getSlug().'/openapi.json'];
    }

    /** Durée de validité d'un run interactif / intégré (jeton). */
    public const PENDING_TTL = 'PT24H';
    /** Rétention des runs terminés. */
    public const RETENTION = 'P90D';
    /** Runs expirés traités à chaque création de run (le reste : app:artifact-runs:sweep). */
    private const SWEEP_ON_RUN = 20;

    /**
     * Lance un run sur la version en ligne. `mode` vide = headless.
     *
     * - headless : exécuté tout de suite, statut final renvoyé ;
     * - interactive : run `pending`, `url` du viewer portant le run et son jeton (24 h) ; la
     *   sortie arrive quand la personne a terminé (get_artifact_run, webhook) ;
     * - embed : run `pending`, `runToken` à passer à <artifact-embed run=… run-token=…>.
     *
     * @return array<string, mixed>
     */
    public function run(User $user, string $idOrSlug, mixed $input, ?string $mode = null, ?ArtifactWorkflowRun $workflowRun = null, ?string $workflowStep = null): array
    {
        $mode = $mode ?: 'headless';
        if (!\in_array($mode, ArtifactIo::MODES, true)) {
            throw new BadRequestHttpException('mode : '.implode(', ', ArtifactIo::MODES).'.');
        }
        $limit = $this->limiter->create($user->getUserIdentifier())->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Trop de runs d’artefacts, réessayez dans quelques instants.');
        }
        $this->artifacts->getForUser($user, $idOrSlug);
        $artifact = $this->artifacts->findArtifactEntity($idOrSlug);
        // Un outil s'exécute sur ce que le viewer publie : la version en ligne.
        $version = $artifact->getLiveVersion();
        $detail = $this->artifacts->getForUser($user, $idOrSlug, $version);
        // Forme « wire » (les {} restent des objets) pour le réducteur, forme tableau pour lire io.
        $wire = ArtifactFiles::expand(json_decode((string) json_encode($detail['document'] ?? new \stdClass()), false));
        $document = json_decode((string) json_encode($wire), true);
        $io = \is_array($document) ? ($document['io'] ?? null) : null;
        if (!\is_array($io)) {
            throw new BadRequestHttpException('La version en ligne de cet artefact ne déclare pas de contrat io (voir get_artifact_catalog rules=["io"]).');
        }
        $declErrors = ArtifactIo::validateDeclaration($io, $document);
        if ($declErrors !== []) {
            throw new BadRequestHttpException('Contrat io invalide : '.$declErrors[0]['path'].' '.$declErrors[0]['message']);
        }
        if (!\in_array($mode, $io['modes'], true)) {
            throw new BadRequestHttpException(sprintf('Mode « %s » non déclaré par l’artefact (io.modes : %s).', $mode, implode(', ', $io['modes'])));
        }

        $input ??= new \stdClass();
        if (\is_array($io['input']['schema'] ?? null)) {
            $inputErrors = ArtifactIo::validateValue($io['input']['schema'], $input, '');
            if ($inputErrors !== []) {
                throw new BadRequestHttpException('Entrée invalide : '.implode(' ', \array_slice($inputErrors, 0, 5)));
            }
        }

        $run = new ArtifactRun($artifact, $version, $user, $mode, $input);
        $run->setIo($io);
        if ($workflowRun !== null && $workflowStep !== null) {
            $run->attachToWorkflow($workflowRun, $workflowStep);
        }
        $extra = ['title' => \is_string($io['title'] ?? null) ? $io['title'] : $artifact->getTitle()];
        if ($mode === 'headless') {
            $this->executeHeadless($run, $wire, $io, $input);
        } else {
            $token = $run->issueToken((new \DateTimeImmutable())->add(new \DateInterval(self::PENDING_TTL)));
            $extra['runToken'] = $token;
            $linkKey = $artifact->getVisibility() === ArtifactVisibility::Link ? $artifact->getLinkToken() : null;
            if ($mode === 'embed' && $linkKey !== null) {
                $extra['linkKey'] = $linkKey;
            }
            if ($mode === 'embed' && $artifact->getVisibility() === ArtifactVisibility::Private) {
                $extra['note'] = 'Artefact privé : une intégration ne peut pas l’afficher (passer l’artefact en « lien » ou « public »).';
            }
            if ($mode === 'interactive') {
                $linkToken = $artifact->getVisibility() === ArtifactVisibility::Link ? $artifact->getLinkToken() : null;
                $extra['url'] = self::withRunParams($this->artifacts->publicUrl($artifact->getSlug(), $linkToken), $run->getId()->toRfc4122(), $token);
                if ($artifact->getVisibility() === ArtifactVisibility::Private) {
                    $extra['note'] = 'Artefact privé : seuls les membres connectés de son jeu pourront ouvrir ce lien.';
                }
            }
        }

        $this->em->persist($run);
        $this->em->flush();
        try {
            $this->usage->increment($user, $artifact->getDataset(), UsageMeter::ARTIFACT_RUNS);
        } catch (\Throwable) {
            // L'observabilité ne bloque pas le run.
        }
        if ($mode === 'headless') {
            $this->notify($run, $user);
        }
        try {
            $this->sweep(self::SWEEP_ON_RUN);
        } catch (\Throwable) {
            // Le ménage ne bloque pas le run (repris par app:artifact-runs:sweep).
        }

        return $this->serialize($run) + $extra;
    }

    /**
     * Run vu par le viewer (jeton du run, sans compte) : entrée à envoyer au store si `pending`.
     *
     * @return array<string, mixed>
     */
    public function publicGet(string $slug, string $runId, string $token): array
    {
        $run = $this->requirePublicRun($slug, $runId, $token);
        $out = [
            'runId' => $run->getId()->toRfc4122(),
            'slug' => $slug,
            'mode' => $run->getMode(),
            'status' => $run->isExpired() && $run->getStatus() === ArtifactRun::STATUS_PENDING ? 'expired' : $run->getStatus(),
            'expiresAt' => $run->getExpiresAt()?->format(\DateTimeInterface::ATOM),
        ];
        if ($out['status'] === ArtifactRun::STATUS_PENDING) {
            $out['input'] = $run->getInput();
        }

        return $out;
    }

    /**
     * Sortie publiée par le viewer : validée par io.output.schema, run terminé, webhook.
     *
     * @return array<string, mixed>
     */
    public function publicComplete(string $slug, string $runId, string $token, mixed $output): array
    {
        $run = $this->requirePublicRun($slug, $runId, $token);
        if ($run->getStatus() !== ArtifactRun::STATUS_PENDING) {
            throw new ConflictHttpException(sprintf('Ce run n’est plus en attente (%s).', $run->getStatus()));
        }
        if ($run->isExpired()) {
            throw new GoneHttpException('Ce run a expiré.');
        }
        $schema = $run->getIo()['output']['schema'] ?? null;
        $errors = \is_array($schema) ? ArtifactIo::validateValue($schema, $output, '') : [];
        if ($errors !== []) {
            throw new BadRequestHttpException('Sortie non conforme : '.implode(' ', \array_slice($errors, 0, 5)));
        }
        $run->finish(ArtifactRun::STATUS_COMPLETED, $output);
        $this->em->flush();
        $this->notify($run, $run->getCreatedBy());
        $this->events->dispatch(new ArtifactRunFinished($run));

        return ['runId' => $run->getId()->toRfc4122(), 'status' => $run->getStatus()];
    }

    /**
     * Fichier de sortie (io.output.files) envoyé par le viewer avant la sortie : `<clé>.<ext>`.
     *
     * @return array{name: string, contentType: string, sizeBytes: int, sha256: string}
     */
    public function publicPutFile(string $slug, string $runId, string $token, string $name, string $bytes): array
    {
        $run = $this->requirePublicRun($slug, $runId, $token);
        if ($run->getStatus() !== ArtifactRun::STATUS_PENDING || $run->isExpired()) {
            throw new ConflictHttpException('Ce run n’est plus en attente.');
        }
        $declared = $run->getIo()['output']['files'] ?? [];
        $dot = strrpos($name, '.');
        $key = $dot === false ? $name : substr($name, 0, $dot);
        if (!\is_array($declared) || !\array_key_exists($key, $declared)) {
            throw new BadRequestHttpException(sprintf('Fichier « %s » non déclaré dans io.output.files.', $key));
        }
        foreach ($this->files->list($run) as $existing) {
            $existingKey = explode('.', $existing['name'])[0];
            if ($existingKey === $key && $existing['name'] !== $name) {
                throw new ConflictHttpException(sprintf('Le fichier « %s » a déjà été envoyé (%s).', $key, $existing['name']));
            }
        }

        return $this->files->put($run, $name, $bytes);
    }

    /** @return array{contentType: string, sizeBytes: int, sha256: string, data: string} */
    public function readFile(User $user, string $runId, string $name): array
    {
        $file = $this->files->read($this->requireRun($user, $runId), $name);
        if ($file === null) {
            throw new NotFoundHttpException('Fichier introuvable.');
        }

        return $file;
    }

    private function requirePublicRun(string $slug, string $runId, string $token): ArtifactRun
    {
        $run = Uuid::isValid($runId) ? $this->runs->find(Uuid::fromString($runId)) : null;
        if (!$run instanceof ArtifactRun || $run->getArtifact()->getSlug() !== $slug || !$run->matchesToken($token)) {
            throw new NotFoundHttpException('Run introuvable.');
        }

        return $run;
    }

    private static function withRunParams(string $url, string $runId, string $token): string
    {
        $hash = '';
        if (($pos = strpos($url, '#')) !== false) {
            $hash = substr($url, $pos);
            $url = substr($url, 0, $pos);
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query(['run' => $runId, 'rt' => $token]).$hash;
    }

    /**
     * Annule un run en attente (interactive / embed) : le lien ne sert plus, webhook artifact.run.failed.
     *
     * @return array<string, mixed>
     */
    public function cancel(User $user, string $runId): array
    {
        $run = $this->requireRun($user, $runId);
        if ($run->getStatus() !== ArtifactRun::STATUS_PENDING) {
            throw new ConflictHttpException('Seul un run en attente peut être annulé.');
        }
        $run->finish(ArtifactRun::STATUS_CANCELLED, null, ['Run annulé.']);
        $this->em->flush();
        $this->notify($run, $user);
        $this->events->dispatch(new ArtifactRunFinished($run));

        return $this->serialize($run);
    }

    /**
     * Ménage : runs en attente expirés → `expired` (webhook artifact.run.failed), puis suppression
     * des runs terminés plus vieux que la rétention.
     *
     * @return array{expired: int, purged: int}
     */
    public function sweep(int $limit = 500, bool $purge = true): array
    {
        $now = new \DateTimeImmutable();
        $expired = $this->runs->findExpiredPending($now, $limit);
        foreach ($expired as $run) {
            $run->finish(ArtifactRun::STATUS_EXPIRED, null, ['Délai dépassé : aucune sortie reçue avant l’expiration du run.']);
        }
        if ($expired !== []) {
            $this->em->flush();
            foreach ($expired as $run) {
                $this->notify($run, $run->getCreatedBy());
                $this->events->dispatch(new ArtifactRunFinished($run));
            }
        }
        $purged = $purge ? $this->runs->purgeFinishedBefore($now->sub(new \DateInterval(self::RETENTION))) : 0;

        return ['expired' => \count($expired), 'purged' => $purged];
    }

    /**
     * Essai headless d'un brouillon (document passé tel quel, rien n'est enregistré ni notifié) :
     * vérifier un contrat io avant de publier.
     *
     * @return array<string, mixed>
     */
    public function dryRun(mixed $wireDocument, mixed $input): array
    {
        $wire = ArtifactFiles::expand($wireDocument);
        $document = json_decode((string) json_encode($wire), true);
        $io = \is_array($document) ? ($document['io'] ?? null) : null;
        if (!\is_array($io)) {
            throw new BadRequestHttpException('Le brouillon ne déclare pas de contrat io.');
        }
        $declErrors = ArtifactIo::validateDeclaration($io, $document);
        if ($declErrors !== []) {
            return ['dryRun' => true, 'status' => ArtifactRun::STATUS_FAILED, 'output' => null, 'errors' => array_map(static fn (array $e): string => $e['path'].' : '.$e['message'], $declErrors)];
        }
        if (!\in_array('headless', $io['modes'], true)) {
            throw new BadRequestHttpException('Essai d’un brouillon : seul le mode headless s’exécute (les autres se testent dans l’aperçu de l’atelier).');
        }
        $input ??= new \stdClass();
        $inputErrors = \is_array($io['input']['schema'] ?? null) ? ArtifactIo::validateValue($io['input']['schema'], $input, '') : [];
        if ($inputErrors !== []) {
            return ['dryRun' => true, 'status' => ArtifactRun::STATUS_FAILED, 'output' => null, 'errors' => array_map(static fn (string $e): string => 'Entrée invalide : '.$e, $inputErrors)];
        }

        return ['dryRun' => true] + $this->headless($wire, $io, $input);
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
        $r = $this->headless($wire, $io, $input);
        $run->finish($r['status'], $r['output'], $r['errors'], $r['metrics']);
    }

    /**
     * Exécution headless (sans persistance).
     *
     * @param array<string, mixed> $io
     *
     * @return array{status: string, output: mixed, errors: list<string>, metrics: array<string, mixed>}
     */
    private function headless(mixed $wire, array $io, mixed $input): array
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
            return ['status' => ArtifactRun::STATUS_FAILED, 'output' => null, 'errors' => array_values(array_map('strval', (array) ($result['errors'] ?? ['Échec du réducteur.']))), 'metrics' => $metrics];
        }
        $output = ArtifactIo::pluck($result['finalState'] ?? null, $plan['path']);
        if ($plan['until'] !== null && ($result['untilReached'] ?? false) !== true) {
            return ['status' => ArtifactRun::STATUS_INCOMPLETE, 'output' => $output, 'errors' => [sprintf('Condition io.output.when jamais vraie en %d pas (augmenter io.headless.maxSteps ou ajouter des actions).', (int) ($result['steps'] ?? 0))], 'metrics' => $metrics];
        }
        $outputErrors = \is_array($io['output']['schema'] ?? null) ? ArtifactIo::validateValue($io['output']['schema'], $output, '') : [];
        if ($outputErrors !== []) {
            return ['status' => ArtifactRun::STATUS_FAILED, 'output' => $output, 'errors' => array_map(static fn (string $e): string => 'Sortie non conforme : '.$e, \array_slice($outputErrors, 0, 10)), 'metrics' => $metrics];
        }

        return ['status' => ArtifactRun::STATUS_COMPLETED, 'output' => $output, 'errors' => [], 'metrics' => $metrics];
    }

    private function notify(ArtifactRun $run, ?User $user): void
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
    public function serializeEntity(ArtifactRun $run): array
    {
        return $this->serialize($run);
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
            'status' => $run->getStatus() === ArtifactRun::STATUS_PENDING && $run->isExpired() ? 'expired' : $run->getStatus(),
            'createdAt' => $run->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'completedAt' => $run->getCompletedAt()?->format(\DateTimeInterface::ATOM),
            'expiresAt' => $run->getExpiresAt()?->format(\DateTimeInterface::ATOM),
            'errors' => $run->getErrors(),
            'metrics' => (object) $run->getMetrics(),
        ];
        if ($run->getWorkflowRun() !== null) {
            $out['workflowRunId'] = $run->getWorkflowRun()->getId()->toRfc4122();
            $out['workflowStep'] = $run->getWorkflowStep();
        }
        if ($withData) {
            $out['input'] = $run->getInput();
            $out['output'] = $run->getOutput();
            $base = '/api/artifacts/'.$run->getArtifact()->getSlug().'/runs/'.$run->getId()->toRfc4122().'/files/';
            $files = \is_array($run->getIo()['output']['files'] ?? null) ? $this->files->list($run) : [];
            if ($files !== []) {
                $out['files'] = array_map(static fn (array $f): array => $f + ['url' => $base.$f['name']], $files);
            }
        }

        return $out;
    }
}
