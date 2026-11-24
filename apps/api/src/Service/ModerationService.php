<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Artifact;
use App\Entity\ArtifactPlace;
use App\Entity\ArtifactPlaceMessage;
use App\Entity\ArtifactPlaceVisitor;
use App\Entity\ArtifactRoute;
use App\Entity\ModerationCase;
use App\Entity\User;
use App\Repository\ModerationCaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Modération de tous les artefacts (super admin) : chaque signalement ouvre ou alimente un dossier, et prévient le
 * super admin ; la file liste les dossiers avec le contenu visé ; une décision rétablit, masque ou retire ce contenu.
 */
final class ModerationService
{
    public const QUEUE_LIMIT = 200;
    public const ACTIONS = ['keep', 'hide', 'delete'];

    public function __construct(
        private readonly ModerationCaseRepository $cases,
        private readonly EntityManagerInterface $em,
        private readonly SuperAdminNotifier $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** À appeler après le flush d'un signalement. Ne lève jamais : un signalement doit toujours aboutir. */
    public function reported(Artifact $artifact, string $kind, Uuid $targetId, int $reports, bool $hidden, string $what): void
    {
        try {
            $c = $this->cases->findTarget($kind, $targetId);
            if ($c === null) {
                $c = new ModerationCase($artifact, $kind, $targetId);
                $this->em->persist($c);
            }
            $c->reported($reports);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('Dossier de modération non enregistré : '.$e->getMessage());
        }
        $this->notifier->report($artifact->getTitle(), $what, $reports, $hidden);
    }

    /** @return array{items: list<array<string, mixed>>, open: int} */
    public function queue(?string $status): array
    {
        if ($status !== null && !\in_array($status, ModerationCase::STATUSES, true)) {
            throw new BadRequestHttpException('status : open, kept, hidden ou deleted.');
        }
        $items = [];
        foreach ($this->cases->queue($status, self::QUEUE_LIMIT) as $c) {
            $items[] = $this->row($c);
        }

        return ['items' => $items, 'open' => $this->cases->countOpen()];
    }

    /** @return array<string, mixed> */
    public function decide(string $caseId, string $action, User $by): array
    {
        if (!\in_array($action, self::ACTIONS, true)) {
            throw new BadRequestHttpException('action : keep, hide ou delete.');
        }
        $c = Uuid::isValid($caseId) ? $this->cases->find(Uuid::fromString($caseId)) : null;
        if ($c === null) {
            throw new NotFoundHttpException('Dossier introuvable.');
        }
        $target = $this->target($c);
        if ($target === null) {
            // Contenu déjà parti (retiré par son auteur, lieu supprimé) : le dossier se ferme.
            $c->decide('deleted', $by);
            $this->em->flush();

            return $this->row($c);
        }
        match (true) {
            $target instanceof ArtifactPlaceMessage => $this->applyMessage($target, $action),
            $target instanceof ArtifactPlaceVisitor => $action === 'delete' ? $this->wipeVisitor($target) : $target->moderate($action === 'hide'),
            $action === 'delete' => $this->em->remove($target),
            default => $target->moderate($action === 'hide'),
        };
        $c->decide(['keep' => 'kept', 'hide' => 'hidden', 'delete' => 'deleted'][$action], $by);
        $this->em->flush();

        return $this->row($c);
    }

    private function applyMessage(ArtifactPlaceMessage $m, string $action): void
    {
        $wasVisible = !$m->isHidden() && !$m->isDeleted();
        if ($action === 'delete') {
            $m->markDeleted('moderation');
            $m->moderate(false);
        } else {
            $m->moderate($action === 'hide');
        }
        $visible = !$m->isHidden() && !$m->isDeleted();
        if ($wasVisible !== $visible) {
            $m->getPlace()->addMessages($visible ? 1 : -1);
        }
    }

    /** Blaze retiré : pseudo et avatar effacés, masqué (ses messages restent, sans blaze). */
    private function wipeVisitor(ArtifactPlaceVisitor $v): void
    {
        $v->update(null, null);
        $v->moderate(true);
    }

    private function target(ModerationCase $c): ?object
    {
        $class = match ($c->getKind()) {
            'place' => ArtifactPlace::class,
            'message' => ArtifactPlaceMessage::class,
            'visitor' => ArtifactPlaceVisitor::class,
            'route' => ArtifactRoute::class,
            default => null,
        };
        $t = $class !== null ? $this->em->find($class, $c->getTargetId()) : null;
        if ($t instanceof ArtifactPlaceMessage && $t->isDeleted() && $t->getDeletedBy() === 'auteur') {
            return null;
        }

        return $t;
    }

    /** @return array<string, mixed> */
    private function row(ModerationCase $c): array
    {
        $a = $c->getArtifact();
        $t = $this->target($c);

        return [
            'id' => $c->getId()->toRfc4122(),
            'kind' => $c->getKind(),
            'status' => $c->getStatus(),
            'reports' => $c->getReports(),
            'firstReportedAt' => $c->getFirstReportedAt()->format(\DateTimeInterface::ATOM),
            'lastReportedAt' => $c->getLastReportedAt()->format(\DateTimeInterface::ATOM),
            'decidedAt' => $c->getDecidedAt()?->format(\DateTimeInterface::ATOM),
            'decidedBy' => $c->getDecidedBy()?->getEmail(),
            'artifact' => ['slug' => $a->getSlug(), 'title' => $a->getTitle()],
            'content' => $t === null ? null : self::content($t),
        ];
    }

    /**
     * Ce que le modérateur doit voir. Les médias restent des data: URL (image ou son), affichés en <img>/<audio>.
     *
     * @return array<string, mixed>
     */
    public static function content(object $t): array
    {
        if ($t instanceof ArtifactPlaceMessage) {
            return ['label' => 'Message', 'hidden' => $t->isHidden(), 'deleted' => $t->isDeleted(), 'text' => $t->getText(), 'pseudo' => $t->getVisitor()?->getPseudo() ?? $t->getPseudo(),
                'image' => $t->getKind() === 'drawing' ? self::safeMedia($t->getMedia(), 'image') : null,
                'audio' => $t->getKind() === 'voice' ? self::safeMedia($t->getMedia(), 'audio') : null,
                'at' => $t->getCreatedAt()->format(\DateTimeInterface::ATOM)];
        }
        if ($t instanceof ArtifactPlaceVisitor) {
            return ['label' => 'Blaze', 'hidden' => $t->isHidden(), 'deleted' => false, 'text' => null, 'pseudo' => $t->getPseudo(),
                'image' => self::safeMedia($t->getAvatar() !== null ? 'data:image/svg+xml;base64,'.base64_encode(ArtifactPlaceVisitorService::stripMetadata(ArtifactPlaceVisitorService::svgOf($t->getAvatar()))) : null, 'image'),
                'audio' => null, 'at' => $t->getUpdatedAt()->format(\DateTimeInterface::ATOM)];
        }
        if ($t instanceof ArtifactPlace) {
            $b = $t->getBody();

            return ['label' => 'Balise', 'hidden' => $t->isHidden(), 'deleted' => false, 'text' => \is_string($b['message'] ?? null) ? mb_substr($b['message'], 0, 500) : null,
                'pseudo' => $t->getVisitor()?->getPseudo(),
                'image' => self::safeMedia(ArtifactPlaceService::mediaUrl($b['dessin'] ?? null), 'image'),
                'audio' => self::safeMedia(ArtifactPlaceService::mediaUrl($b['voix'] ?? null), 'audio'),
                'at' => $t->getCreatedAt()->format(\DateTimeInterface::ATOM)];
        }
        if ($t instanceof ArtifactRoute) {
            return ['label' => 'Parcours', 'hidden' => $t->isHidden(), 'deleted' => false, 'text' => trim($t->getTitle()."\n".($t->getDescription() ?? '')),
                'pseudo' => $t->getPseudo(), 'image' => null, 'audio' => null, 'at' => $t->getCreatedAt()->format(\DateTimeInterface::ATOM)];
        }

        return ['label' => 'Contenu', 'hidden' => false, 'deleted' => false, 'text' => null, 'pseudo' => null, 'image' => null, 'audio' => null, 'at' => null];
    }

    /** Média affichable : data: URL du bon type, ou https. */
    private static function safeMedia(?string $v, string $type): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (str_starts_with($v, 'data:'.$type.'/') || str_starts_with($v, 'https://')) {
            return $v;
        }

        return null;
    }
}
