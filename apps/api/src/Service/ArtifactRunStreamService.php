<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ArtifactRun;
use App\Entity\User;
use App\Repository\ArtifactRunRepository;
use App\Webhook\WebhookEventType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\Uid\Uuid;

/**
 * Flux d'un run (contrat {@see ArtifactStream}) : messages poussés par un émetteur (jeton
 * d'émission ou compte), validés, numérotés, journalisés puis relayés sur le hub Mercure ;
 * le viewer qui exécute le run et les spectateurs s'y abonnent.
 *
 * Topics (clé secrète du run dans le chemin, lecture par JWT d'abonné) :
 * - `in`  : messages de l'émetteur ({events: [{seq, t, type, …}]}) ;
 * - `out` : progression publiée par le viewer ({events: [{type: "progress", value, t}]}).
 *
 * Le serveur garde l'état utile à un spectateur qui arrive en cours : document courant (après
 * document.patch), partition en cours, dernier instantané d'état du viewer et les messages récents.
 */
final class ArtifactRunStreamService
{
    /** Activité du flux : le run reste ouvert 24 h après le dernier message… */
    public const IDLE_TTL = 'PT24H';
    /** … dans la limite de 7 jours après sa création. */
    public const MAX_LIFETIME = 'P7D';
    public const MAX_RUN_MESSAGES = 500_000;
    /** Messages gardés pour rattraper un spectateur (au-delà : instantané du viewer). */
    public const LOG_KEEP = 1000;
    public const SUBSCRIBER_TTL = 3600;
    public const PROGRESS_HOOK_EVERY = 10;
    private const TOPIC_BASE = 'https://tadaaa.app/runs/';

    public function __construct(
        private readonly ArtifactRunRepository $runs,
        private readonly ArtifactService $artifacts,
        private readonly ArtifactDocumentValidator $validator,
        private readonly DatasetAccessService $access,
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly HubInterface $hub,
        private readonly MercureFeature $mercure,
        private readonly WebhookDispatcher $webhooks,
        private readonly UsageMeter $usage,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'cache.rate_limiter')]
        private readonly CacheItemPoolInterface $limiterPool,
    ) {
    }

    public static function topic(ArtifactRun $run, string $direction): string
    {
        return self::TOPIC_BASE.$run->getId()->toRfc4122().'/'.$run->getStreamKey().'/'.$direction;
    }

    /**
     * Ouvre le flux d'un run qui vient d'être créé (contrat io.stream, mode interactive / embed).
     *
     * @return array<string, mixed> à renvoyer une seule fois au créateur du run
     */
    public function open(ArtifactRun $run, ?string $viewerUrl): array
    {
        $tokens = $run->openStream();
        $base = '/api/public/artifacts/'.$run->getArtifact()->getSlug().'/runs/'.$run->getId()->toRfc4122();

        return array_filter([
            'emitToken' => $tokens['emitToken'],
            'watchToken' => $tokens['watchToken'],
            'eventsUrl' => $base.'/events',
            'joinUrl' => $base.'/stream',
            'watchUrl' => $viewerUrl !== null ? self::withParams($viewerUrl, ['run' => $run->getId()->toRfc4122(), 'wt' => $tokens['watchToken']]) : null,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * Messages d'un émetteur sans compte (jeton d'émission).
     *
     * @return array<string, mixed>
     */
    public function emitWithToken(string $slug, string $runId, string $emitToken, mixed $body): array
    {
        $run = $this->findRun($slug, $runId);
        if (!$run->matchesEmitToken($emitToken)) {
            throw new NotFoundHttpException('Run introuvable.');
        }

        return $this->emit($run, $body);
    }

    /**
     * Messages d'un membre du jeu (MCP, API authentifiée).
     *
     * @return array<string, mixed>
     */
    public function emitAsUser(User $user, string $runId, mixed $body): array
    {
        $run = Uuid::isValid($runId) ? $this->runs->find(Uuid::fromString($runId)) : null;
        if (!$run instanceof ArtifactRun || $this->access->getRole($user, $run->getArtifact()->getDataset())?->canWrite() !== true) {
            throw new NotFoundHttpException('Run introuvable.');
        }

        return $this->emit($run, $body);
    }

    /**
     * État du flux pour un viewer (jeton du run), un spectateur (jeton de spectateur) ou un
     * émetteur : identifiants Mercure, document et partition courants, messages à rejouer.
     *
     * @return array<string, mixed>
     */
    public function join(string $slug, string $runId, string $token, int $after = -1, ?string $requestBaseUrl = null): array
    {
        $run = $this->findRun($slug, $runId);
        $role = match (true) {
            $run->matchesToken($token) => 'performer',
            $run->matchesWatchToken($token) => 'watcher',
            $run->matchesEmitToken($token) => 'emitter',
            default => throw new NotFoundHttpException('Run introuvable.'),
        };
        $io = $run->getIo() ?? [];
        $snapshot = $run->getStreamSnapshot();
        $snapshotSeq = $snapshot instanceof \stdClass && \is_int($snapshot->seq ?? null) ? $snapshot->seq : 0;
        $from = $after >= 0 ? $after : $snapshotSeq;
        // Rattrapage : document et partition sont donnés à jour, on ne rejoue que l'état.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT seq, message FROM artifact_run_stream_events WHERE run_id = :r AND seq > :s ORDER BY seq LIMIT :l',
            ['r' => $run->getId()->toRfc4122(), 's' => $from, 'l' => self::LOG_KEEP],
            ['l' => \Doctrine\DBAL\ParameterType::INTEGER],
        );
        $events = [];
        $first = null;
        foreach ($rows as $row) {
            $first ??= (int) $row['seq'];
            $msg = json_decode((string) $row['message'], false);
            if ($after >= 0 || \in_array($msg->type ?? null, ['action', 'state.snapshot', 'state.delta'], true)) {
                $events[] = $msg;
            }
        }
        $seq = $run->getStreamSeq();
        // Complet si le journal couvre tout depuis $from (sinon : partir de l'instantané du viewer).
        $complete = $first !== null ? $first === $from + 1 : $seq === $from;

        $status = $run->getStatus() === ArtifactRun::STATUS_PENDING && $run->isExpired() ? 'expired' : $run->getStatus();
        $out = [
            'runId' => $run->getId()->toRfc4122(),
            'role' => $role,
            'status' => $status,
            'version' => $run->getArtifactVersion(),
            'stream' => \is_array($io['stream'] ?? null) ? ArtifactStream::describe($io['stream']) : null,
            'store' => ArtifactStream::store($io),
            'seq' => $seq,
            'ended' => $run->isStreamEnded(),
            'serverTime' => self::nowMs(),
            'events' => $events,
            'complete' => $complete,
        ];
        if ($after < 0) {
            $out['snapshot'] = $snapshot;
            $out['score'] = $run->getStreamScore();
            if ($run->getStreamDocument() !== null) {
                $out['document'] = $this->artifacts->expandAssets($run->getArtifact(), $run->getStreamDocument(), $requestBaseUrl);
            }
            if ($role !== 'emitter') {
                $out['input'] = $run->getInput();
            }
            $out['progress'] = $run->getStreamMeta()['progress'] ?? null;
        }
        if ($status === ArtifactRun::STATUS_PENDING) {
            $out['mercure'] = $this->subscriberCredentials($run);
        }

        return $out;
    }

    /**
     * Messages du viewer qui exécute le run (jeton du run) : progress, snapshot.
     *
     * @return array<string, mixed>
     */
    public function publishFromViewer(string $slug, string $runId, string $token, mixed $body): array
    {
        $run = $this->findRun($slug, $runId);
        if (!$run->matchesToken($token)) {
            throw new NotFoundHttpException('Run introuvable.');
        }
        $this->assertOpen($run, false);
        $list = self::messages($body);
        $relay = [];
        $progress = null;
        foreach ($list as $i => $raw) {
            [$msg, $errors] = ArtifactStream::normalizeOut($raw);
            if ($msg === null) {
                throw new BadRequestHttpException(sprintf('events[%d] : %s', $i, $errors[0]));
            }
            if ($msg->type === 'snapshot') {
                if ($msg->seq > $run->getStreamSeq()) {
                    throw new BadRequestHttpException('snapshot : seq au-delà du dernier message.');
                }
                $current = $run->getStreamSnapshot();
                if (!$current instanceof \stdClass || ($current->seq ?? -1) <= $msg->seq) {
                    $run->setStreamSnapshot(['seq' => $msg->seq, 'state' => $msg->state, 'at' => self::nowMs()]);
                }
                continue;
            }
            $msg->t = self::nowMs();
            $relay[] = $msg;
            $progress = $msg->value;
        }
        $meta = $run->getStreamMeta();
        $hook = false;
        if ($relay !== []) {
            $meta['progress'] = $progress;
            $meta['progressAt'] = self::nowMs();
            if (time() - (int) ($meta['progressHookAt'] ?? 0) >= self::PROGRESS_HOOK_EVERY) {
                $meta['progressHookAt'] = time();
                $hook = true;
            }
            $run->setStreamMeta($meta);
        }
        $this->em->flush();
        if ($relay !== []) {
            $this->publish($run, 'out', ['events' => $relay]);
        }
        if ($hook) {
            $this->webhooks->dispatch($run->getArtifact()->getDataset(), WebhookEventType::ARTIFACT_RUN_PROGRESS, [
                'runId' => $run->getId()->toRfc4122(),
                'slug' => $run->getArtifact()->getSlug(),
                'progress' => $progress,
                'seq' => $run->getStreamSeq(),
            ], $run->getCreatedBy());
        }

        return ['ok' => true, 'seq' => $run->getStreamSeq()];
    }

    /**
     * @return array<string, mixed>
     */
    private function emit(ArtifactRun $run, mixed $body): array
    {
        $list = self::messages($body);
        if ($list === []) {
            throw new BadRequestHttpException('Aucun message : {"events": [{type, …}]} ou un message seul.');
        }
        if (\count($list) > ArtifactStream::MAX_BATCH) {
            throw new BadRequestHttpException(sprintf('%d messages par envoi au plus.', ArtifactStream::MAX_BATCH));
        }
        if (\strlen((string) json_encode($list)) > ArtifactStream::MAX_BATCH_BYTES) {
            throw new BadRequestHttpException(sprintf('Envoi trop volumineux (%d octets max, découper en plusieurs envois).', ArtifactStream::MAX_BATCH_BYTES));
        }
        $this->assertOpen($run, true);
        $stream = (array) ($run->getIo()['stream'] ?? []);

        // Validation complète avant de toucher au run : un envoi est accepté ou refusé en entier.
        $messages = [];
        foreach ($list as $i => $raw) {
            [$msg, $errors] = ArtifactStream::normalizeIn($stream, $raw);
            if ($msg === null) {
                throw new BadRequestHttpException(sprintf('events[%d] : %s', $i, implode(' ', $errors)));
            }
            $messages[] = $msg;
        }
        $this->consumeRate($run, $stream, \count($messages));

        $published = [];
        $ended = false;
        $this->connection->beginTransaction();
        try {
            $this->em->refresh($run, LockMode::PESSIMISTIC_WRITE);
            $this->assertOpen($run, true);
            $meta = $run->getStreamMeta();
            $count = (int) ($meta['messages'] ?? 0);
            if ($count + \count($messages) > self::MAX_RUN_MESSAGES) {
                throw new ConflictHttpException(sprintf('Quota du flux atteint (%d messages par run) : lancer un nouveau run.', self::MAX_RUN_MESSAGES));
            }
            $seq = $run->getStreamSeq();
            $document = null;
            $now = self::nowMs();
            foreach ($messages as $i => $msg) {
                if ($msg->type === 'document.patch') {
                    // Décodé en objets pour JsonPatch : {} reste distinct de [].
                    $document ??= $run->getStreamDocument()
                        ?? json_decode((string) json_encode($this->artifacts->versionWireDocument($run->getArtifact(), $run->getArtifactVersion())), false);
                    $document = $this->applyDocumentPatch($document, $msg->patch, $i);
                }
                $msg->seq = ++$seq;
                $msg->t = $now;
                if ($msg->type === 'score') {
                    $run->setStreamScore(['score' => $msg->score, 't' => $now, 'seq' => $msg->seq]);
                } elseif ($msg->type === 'score.stop') {
                    $run->setStreamScore(null);
                } elseif ($msg->type === 'end') {
                    $ended = true;
                }
                $published[] = $msg;
            }
            if ($document !== null) {
                $run->setStreamDocument($document);
                // Le document relayé aux viewers : assets résolus comme dans le document servi.
                foreach ($published as $msg) {
                    if ($msg->type === 'document.patch') {
                        foreach ($msg->patch as $op) {
                            if (property_exists($op, 'value')) {
                                $op->value = $this->artifacts->expandAssets($run->getArtifact(), $op->value);
                            }
                        }
                    }
                }
            }
            $run->setStreamSeq($seq);
            $meta['messages'] = $count + \count($messages);
            $meta['lastAt'] = $now;
            if ($ended) {
                $meta['endedAt'] = $now;
            }
            $run->setStreamMeta($meta);
            $created = $run->getCreatedAt();
            $run->extendExpiry(
                (new \DateTimeImmutable())->add(new \DateInterval(self::IDLE_TTL)),
                $created->add(new \DateInterval(self::MAX_LIFETIME)),
            );
            $this->em->flush();

            $nowSql = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            foreach ($published as $msg) {
                $this->connection->insert('artifact_run_stream_events', [
                    'run_id' => $run->getId()->toRfc4122(),
                    'seq' => $msg->seq,
                    'message' => json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                    'created_at' => $nowSql,
                ]);
            }
            if ($seq > self::LOG_KEEP && intdiv($seq, 50) !== intdiv($seq - \count($published), 50)) {
                $this->connection->executeStatement(
                    'DELETE FROM artifact_run_stream_events WHERE run_id = :r AND seq <= :s',
                    ['r' => $run->getId()->toRfc4122(), 's' => $seq - self::LOG_KEEP],
                );
            }
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        $this->publish($run, 'in', ['events' => $published]);
        $owner = $run->getArtifact()->getDataset()->getOwner();
        try {
            $this->usage->increment($owner, $run->getArtifact()->getDataset(), UsageMeter::ARTIFACT_STREAM_MESSAGES, \count($published));
        } catch (\Throwable) {
            // L'observabilité ne bloque pas le flux.
        }
        if ($ended) {
            $this->webhooks->dispatch($run->getArtifact()->getDataset(), WebhookEventType::ARTIFACT_RUN_STREAM_ENDED, [
                'runId' => $run->getId()->toRfc4122(),
                'slug' => $run->getArtifact()->getSlug(),
                'seq' => $run->getStreamSeq(),
                'messages' => $run->getStreamMeta()['messages'] ?? 0,
            ], $run->getCreatedBy());
        }

        return [
            'accepted' => \count($published),
            'seq' => $run->getStreamSeq(),
            'firstSeq' => $published[0]->seq,
            'ended' => $run->isStreamEnded(),
            'expiresAt' => $run->getExpiresAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /** Document patché puis validé comme une publication (sinon l'envoi est refusé). */
    private function applyDocumentPatch(mixed $document, array $ops, int $index): mixed
    {
        try {
            $next = JsonPatch::apply($document, $ops);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException(sprintf('events[%d] : %s', $index, $e->getMessage()));
        }
        $check = $this->validator->validate(json_decode((string) json_encode($next), true));
        if (($check['valid'] ?? false) !== true) {
            $first = $check['errors'][0] ?? ['path' => '', 'message' => 'document invalide'];
            throw new BadRequestHttpException(sprintf('events[%d] : document refusé après patch (%s : %s).', $index, $first['path'], $first['message']));
        }

        return $next;
    }

    private function assertOpen(ArtifactRun $run, bool $emitting): void
    {
        if (!$run->hasStream()) {
            throw new BadRequestHttpException('Ce run n’a pas de flux (io.stream absent du contrat, ou mode headless).');
        }
        if ($run->getStatus() !== ArtifactRun::STATUS_PENDING) {
            throw new ConflictHttpException(sprintf('Ce run est terminé (%s).', $run->getStatus()));
        }
        if ($run->isExpired()) {
            throw new GoneHttpException('Ce run a expiré.');
        }
        if ($emitting && $run->isStreamEnded()) {
            throw new ConflictHttpException('Le flux de ce run est terminé (message end reçu).');
        }
    }

    /** @param array<string, mixed> $stream */
    private function consumeRate(ArtifactRun $run, array $stream, int $n): void
    {
        $rate = ArtifactStream::rate($stream);
        $factory = new RateLimiterFactory([
            'id' => 'artifact_stream_'.$rate,
            'policy' => 'token_bucket',
            // Rafale : 2 s de messages (un émetteur qui regroupe ses envois n'est pas pénalisé).
            'limit' => max(ArtifactStream::MAX_BATCH, $rate * 2),
            'rate' => ['interval' => '1 second', 'amount' => $rate],
        ], new CacheStorage($this->limiterPool));
        $limit = $factory->create($run->getId()->toRfc4122())->consume($n);
        if (!$limit->isAccepted()) {
            $retry = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($retry, sprintf('Débit du flux dépassé (io.stream.rate = %d messages/s).', $rate));
        }
    }

    /** @param array<string, mixed> $data */
    private function publish(ArtifactRun $run, string $direction, array $data): void
    {
        if (!$this->mercure->isEnabled()) {
            return;
        }
        try {
            $last = $data['events'][\count($data['events']) - 1] ?? null;
            $id = $direction === 'in' && $last instanceof \stdClass ? $run->getId()->toRfc4122().':'.$last->seq : null;
            $this->hub->publish(new Update(
                self::topic($run, $direction),
                (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                true,
                $id,
                'stream',
            ));
        } catch (\Throwable $e) {
            // Les viewers rattrapent par numéro de séquence (GET …/stream?after=).
            $this->logger->warning('Flux de run : publication Mercure impossible', ['run' => $run->getId()->toRfc4122(), 'error' => $e->getMessage()]);
        }
    }

    /** @return array<string, mixed>|null */
    private function subscriberCredentials(ArtifactRun $run): ?array
    {
        if (!$this->mercure->isEnabled() || $this->hub->getFactory() === null) {
            return null;
        }
        $topics = ['in' => self::topic($run, 'in'), 'out' => self::topic($run, 'out')];
        $token = $this->hub->getFactory()->create(
            array_values($topics),
            null,
            ['exp' => new \DateTimeImmutable('+'.self::SUBSCRIBER_TTL.' seconds')],
        );

        return ['hubUrl' => $this->hub->getPublicUrl(), 'token' => $token, 'topics' => $topics, 'expiresIn' => self::SUBSCRIBER_TTL];
    }

    private function findRun(string $slug, string $runId): ArtifactRun
    {
        $run = Uuid::isValid($runId) ? $this->runs->find(Uuid::fromString($runId)) : null;
        if (!$run instanceof ArtifactRun || $run->getArtifact()->getSlug() !== $slug) {
            throw new NotFoundHttpException('Run introuvable.');
        }

        return $run;
    }

    /** @return list<mixed> */
    private static function messages(mixed $body): array
    {
        if ($body instanceof \stdClass && property_exists($body, 'events')) {
            return \is_array($body->events) && array_is_list($body->events) ? $body->events : throw new BadRequestHttpException('events : liste de messages.');
        }
        if (\is_array($body) && array_is_list($body)) {
            return $body;
        }

        return $body instanceof \stdClass ? [$body] : throw new BadRequestHttpException('JSON attendu : un message {type, …}, une liste, ou {"events": […]}.');
    }

    private static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /** @param array<string, string> $params */
    private static function withParams(string $url, array $params): string
    {
        $hash = '';
        if (($pos = strpos($url, '#')) !== false) {
            $hash = substr($url, $pos);
            $url = substr($url, 0, $pos);
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($params).$hash;
    }
}
