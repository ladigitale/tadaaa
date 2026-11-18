<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ArtifactRun;
use App\Entity\ArtifactRunLink;
use App\Entity\User;
use App\Event\ArtifactRunEmitted;
use App\Repository\ArtifactRunLinkRepository;
use App\Repository\ArtifactRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Liens entre runs en flux : la file d'envoi (`io.stream.emit`) d'un run alimente le flux d'un
 * autre. Le viewer du run source publie ses éléments {id, data} ; chaque lien actif les transforme
 * (JSONata `map` évalué sur {id, data, from: {slug, runId}}, résultat : un message, une liste ou
 * rien) puis les pousse dans le run cible avec les mêmes validations et le même débit que
 * n'importe quel émetteur. Aucun jeton ne quitte le serveur.
 *
 * Exemples de map :
 *   data                                             (l'élément est déjà un message du flux cible)
 *   {"type": "action", "name": "reaction", "payload": {"n": data.force}}
 *   data.pad = "feu" ? {"type": "state.delta", "patch": [{"op": "add", "path": "/states/maison.0", "value": {"burning": 1}}]}
 */
final class ArtifactRunLinkService
{
    public const MAX_LINKS_PER_RUN = 8;
    public const MAX_MAP_LENGTH = 4000;

    public function __construct(
        private readonly ArtifactRunLinkRepository $links,
        private readonly ArtifactRunRepository $runs,
        private readonly ArtifactRunStreamService $streams,
        private readonly DatasetAccessService $access,
        private readonly StoreSimulator $jsonata,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array<string, mixed> */
    public function create(User $user, string $fromRunId, string $toRunId, ?string $map): array
    {
        $from = $this->writableRun($user, $fromRunId, 'fromRunId');
        $to = $this->writableRun($user, $toRunId, 'toRunId');
        if ($from === $to) {
            throw new BadRequestHttpException('Un run ne peut pas se relier à lui-même.');
        }
        foreach ([[$from, 'source'], [$to, 'cible']] as [$run, $label]) {
            if ($run->getStatus() !== ArtifactRun::STATUS_PENDING || $run->isExpired() || !$run->hasStream()) {
                throw new ConflictHttpException(sprintf('Run %s : il doit être en cours, en mode interactive ou embed, avec un flux (io.stream).', $label));
            }
        }
        if (!ArtifactStream::emits($from->getIo() ?? [])) {
            throw new BadRequestHttpException(sprintf('« %s » n’a pas de file d’envoi : déclarer io.stream.emit = {from: "store.file", ack?: "store"}.', $from->getArtifact()->getSlug()));
        }
        if (!ArtifactStream::accepts($to->getIo() ?? [])) {
            throw new BadRequestHttpException(sprintf('« %s » n’accepte aucun message (io.stream : actions, state, document ou score).', $to->getArtifact()->getSlug()));
        }
        if ($this->links->countActiveFrom($from) >= self::MAX_LINKS_PER_RUN) {
            throw new ConflictHttpException(sprintf('%d liens actifs au plus par run source.', self::MAX_LINKS_PER_RUN));
        }
        $map = $map !== null && trim($map) !== '' ? trim($map) : null;
        if ($map !== null) {
            if (mb_strlen($map) > self::MAX_MAP_LENGTH) {
                throw new BadRequestHttpException(sprintf('map : %d caractères au plus.', self::MAX_MAP_LENGTH));
            }
            $errors = $this->jsonata->checkExpressions(['map' => self::wrap($map)]);
            if ($errors !== []) {
                throw new BadRequestHttpException('map (JSONata) : '.$errors[0]['message']);
            }
        }
        $link = new ArtifactRunLink($from, $to, $map, $user);
        $this->em->persist($link);
        $this->em->flush();

        return $link->serialize();
    }

    /** @return array<string, mixed> */
    public function delete(User $user, string $linkId): array
    {
        $link = Uuid::isValid($linkId) ? $this->links->find(Uuid::fromString($linkId)) : null;
        if (!$link instanceof ArtifactRunLink || $this->access->getRole($user, $link->getFromRun()->getArtifact()->getDataset())?->canWrite() !== true) {
            throw new NotFoundHttpException('Lien introuvable.');
        }
        $link->deactivate();
        $this->em->flush();

        return $link->serialize();
    }

    /** @return list<array<string, mixed>> */
    public function forRun(ArtifactRun $run): array
    {
        return array_map(static fn (ArtifactRunLink $l): array => $l->serialize(), $this->links->findForRun($run));
    }

    /** Éléments publiés par la file d'envoi d'un run : renvoyés par ses liens actifs. */
    #[AsEventListener]
    public function onEmitted(ArtifactRunEmitted $event): void
    {
        if ($event->items === []) {
            return;
        }
        $from = $event->run;
        foreach ($this->links->findActiveFrom($from) as $link) {
            $to = $link->getToRun();
            if ($to->getStatus() !== ArtifactRun::STATUS_PENDING || $to->isExpired() || $to->isStreamEnded()) {
                $link->deactivate();
                continue;
            }
            try {
                $messages = $this->transform($link, $event->items);
                foreach (array_chunk($messages, ArtifactStream::MAX_BATCH) as $chunk) {
                    $this->streams->emitFromLink($to, $chunk);
                }
                $link->recordSuccess(\count($messages));
            } catch (HttpExceptionInterface|\RuntimeException $e) {
                $link->recordFailure($e->getMessage());
                if ($e instanceof ConflictHttpException || str_contains($e->getMessage(), 'expiré')) {
                    $link->deactivate();
                }
            } catch (\Throwable $e) {
                $link->recordFailure('Erreur interne.');
                $this->logger->error('Lien de runs : transfert impossible', ['link' => $link->getId()->toRfc4122(), 'error' => $e->getMessage()]);
            }
        }
        $this->em->flush();
    }

    /**
     * @param list<\stdClass> $items éléments {id, data}
     *
     * @return list<\stdClass> messages pour le flux cible
     */
    public function transform(ArtifactRunLink $link, array $items): array
    {
        $from = $link->getFromRun();
        $context = array_map(static fn (\stdClass $i): \stdClass => (object) [
            'id' => $i->id,
            'data' => $i->data ?? null,
            'from' => (object) ['slug' => $from->getArtifact()->getSlug(), 'runId' => $from->getId()->toRfc4122()],
        ], $items);

        if ($link->getMap() === null) {
            $results = array_map(static fn (\stdClass $c): mixed => $c->data, $context);
        } else {
            $eval = $this->jsonata->evaluate(['map' => self::wrap($link->getMap())], (object) ['items' => $context]);
            if ($eval['errors'] !== []) {
                throw new BadRequestHttpException('map (JSONata) : '.$eval['errors'][0]['message']);
            }
            $results = $eval['results']['map'] ?? [];
            $results = \is_array($results) ? $results : [$results];
        }

        return self::flatten($results);
    }

    /** Applique `map` à chaque élément ; le résultat est toujours une liste (un élément = une entrée). */
    private static function wrap(string $map): string
    {
        return '$map(items, function($it) { $it.(' . $map . ') })';
    }

    /** @return list<\stdClass> */
    private static function flatten(array $results): array
    {
        $out = [];
        foreach ($results as $r) {
            if ($r === null) {
                continue;
            }
            if (\is_array($r)) {
                foreach ($r as $m) {
                    if ($m instanceof \stdClass) {
                        $out[] = $m;
                    } elseif ($m !== null) {
                        throw new BadRequestHttpException('map : chaque message doit être un objet {type, …}.');
                    }
                }
            } elseif ($r instanceof \stdClass) {
                $out[] = $r;
            } else {
                throw new BadRequestHttpException('map : le résultat doit être un message {type, …}, une liste de messages, ou rien.');
            }
        }

        return $out;
    }

    private function writableRun(User $user, string $runId, string $field): ArtifactRun
    {
        $run = Uuid::isValid($runId) ? $this->runs->find(Uuid::fromString($runId)) : null;
        if (!$run instanceof ArtifactRun || $this->access->getRole($user, $run->getArtifact()->getDataset())?->canWrite() !== true) {
            throw new NotFoundHttpException(sprintf('%s : run introuvable.', $field));
        }

        return $run;
    }
}
