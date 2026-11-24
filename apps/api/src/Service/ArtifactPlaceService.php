<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Artifact;
use App\Entity\ArtifactPlace;
use App\Entity\ArtifactPlaceMessage;
use App\Entity\ArtifactPlaceVisitor;
use App\Entity\ArtifactVisibility;
use App\Entity\User;
use App\Repository\ArtifactPlaceMessageRepository;
use App\Repository\ArtifactPlaceRepository;
use App\Repository\ArtifactRepository;
use App\Repository\ArtifactVersionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Lieux partagés d'un artefact (`data.places.<nom> = {maxBytes, maxRecords, radius}`) : n'importe quel visiteur
 * pose un objet JSON à une position GPS ; tous les visiteurs lisent ceux qui sont proches d'eux.
 *
 * Garde-fous : taille par lieu (maxBytes ≤ HARD_MAX_BYTES), nombre de lieux par artefact (maxRecords), débit par
 * IP (limiteur HTTP + plafond quotidien), signalements (masqué à HIDE_AT), suppression par le poseur (jeton) ou
 * par un membre du jeu de données de l'artefact. Mêmes droits de lecture que l'artefact.
 */
final class ArtifactPlaceService
{
    /** Rayon autour du lieu pour participer sur place (mètres), plus la précision GPS plafonnée. */
    public const ON_SITE_METERS = 150;
    public const ON_SITE_ACCURACY_CAP = 100.0;
    public const HARD_MAX_BYTES = 400_000;
    public const DEFAULT_MAX_BYTES = 150_000;
    public const HARD_MAX_RECORDS = 5000;
    public const DEFAULT_MAX_RECORDS = 1000;
    public const MAX_RADIUS = 5000;
    public const LIST_LIMIT = 80;
    public const DAILY_PER_IP = 40;
    /** Surfaces de remplacement gardées par lieu (sonic-anchor en lit autant). */
    public const MAX_EXTRA_REFS = 4;
    /** Discussion d'un lieu : messages servis (les plus récents), plafond quotidien par réseau et par artefact. */
    public const THREAD_LIMIT = 100;
    /** Champs d'un lieu que son poseur peut retoucher. */
    public const EDITABLE = ['message', 'effet', 'dessin', 'cadreDessin', 'voix'];
    public const DAILY_MESSAGES_PER_IP = 200;
    public const HIDE_AT = 3;
    private const SUMMARY_KEYS = 16;
    /** Carte : au-delà, groupes par case de grille (MAP_GRID × MAP_GRID cases sur la zone). */
    public const MAP_LIMIT = 300;
    private const MAP_GRID = 16;
    private const SUMMARY_STRING = 200;

    public function __construct(
        private readonly ArtifactRepository $artifacts,
        private readonly ArtifactVersionRepository $versions,
        private readonly ArtifactPlaceRepository $places,
        private readonly ArtifactPlaceMessageRepository $messages,
        private readonly DatasetAccessService $access,
        private readonly EntityManagerInterface $em,
        private readonly ArtifactPlaceVisitors $visitors,
        private readonly ModerationService $moderation,
    ) {
    }

    /**
     * Lieux à moins de `radius` m du point (ou les plus récents sans point), du plus proche au plus lointain.
     *
     * @return array{items: list<array<string, mixed>>, radius: int, moderator: bool}
     */
    public function list(string $slug, ?string $linkToken, string $collection, ?float $lat, ?float $lon, ?int $radius, ?User $user, bool $withHidden = false): array
    {
        [$artifact, $conf] = $this->open($slug, $linkToken, $user, $collection);
        $moderator = $this->isModerator($artifact, $user);
        $withHidden = $withHidden && $moderator;
        $r = max(10, min(self::MAX_RADIUS, $radius ?? $conf['radius']));
        if ($lat === null || $lon === null) {
            $rows = $this->places->findLatest($artifact, $collection, $withHidden, self::LIST_LIMIT);
            $items = array_map(fn (ArtifactPlace $p) => $this->row($p, null), $rows);

            return ['items' => $items, 'radius' => $r, 'moderator' => $moderator];
        }
        self::checkPoint($lat, $lon);
        $dLat = $r / 111_320;
        $dLon = $r / (111_320 * max(0.01, cos(deg2rad($lat))));
        $rows = $this->places->findInBox($artifact, $collection, $lat - $dLat, $lat + $dLat, $lon - $dLon, $lon + $dLon, $withHidden, 400);
        $items = [];
        foreach ($rows as $p) {
            $d = self::distance($lat, $lon, $p->getLat(), $p->getLon());
            if ($d <= $r) {
                $items[] = $this->row($p, $d);
            }
        }
        usort($items, static fn (array $a, array $b) => $a['distance'] <=> $b['distance']);

        return ['items' => \array_slice($items, 0, self::LIST_LIMIT), 'radius' => $r, 'moderator' => $moderator];
    }

    /**
     * Lieux d'une zone de carte (bbox = [ouest, sud, est, nord] en degrés) : les lieux eux-mêmes (champs courts)
     * jusqu'à MAP_LIMIT, au-delà des groupes par cases de grille {lat, lon, count} pour dézoomer sans tout charger.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     *
     * @return array{items: list<array<string, mixed>>, clusters: list<array{lat: float, lon: float, count: int}>, total: int, bbox: list<float>}
     */
    public function listBox(string $slug, ?string $linkToken, string $collection, array $bbox, ?User $user): array
    {
        [$artifact] = $this->open($slug, $linkToken, $user, $collection);
        [$w, $s, $e, $n] = $bbox;
        foreach ($bbox as $v) {
            if (!is_finite($v)) {
                throw new BadRequestHttpException('bbox : quatre nombres ouest,sud,est,nord.');
            }
        }
        $s = max(-90.0, min(90.0, $s));
        $n = max(-90.0, min(90.0, $n));
        if ($s > $n) {
            [$s, $n] = [$n, $s];
        }
        // Zone à cheval sur l'antiméridien ou plus large que le globe : tout le tour.
        if ($w > $e || $e - $w >= 360) {
            [$w, $e] = [-180.0, 180.0];
        }
        $w = max(-180.0, $w);
        $e = min(180.0, $e);
        $total = $this->places->countInBox($artifact, $collection, $s, $n, $w, $e);
        $box = [round($w, 6), round($s, 6), round($e, 6), round($n, 6)];
        if ($total <= self::MAP_LIMIT) {
            $rows = $this->places->findInBox($artifact, $collection, $s, $n, $w, $e, false, self::MAP_LIMIT);

            return ['items' => array_map(fn (ArtifactPlace $p) => $this->row($p, null), $rows), 'clusters' => [], 'total' => $total, 'bbox' => $box];
        }
        $cellLat = max(1e-5, ($n - $s) / self::MAP_GRID);
        $cellLon = max(1e-5, ($e - $w) / self::MAP_GRID);

        return ['items' => [], 'clusters' => $this->places->clustersInBox($artifact, $collection, $s, $n, $w, $e, $cellLat, $cellLon, self::MAP_GRID * self::MAP_GRID), 'total' => $total, 'bbox' => $box];
    }

    /** @return array<string, mixed> */
    public function get(string $slug, ?string $linkToken, string $collection, string $id, ?User $user): array
    {
        [$artifact] = $this->open($slug, $linkToken, $user, $collection);
        $p = $this->find($artifact, $collection, $id);
        if ($p->isHidden() && !$this->isModerator($artifact, $user)) {
            throw new NotFoundHttpException('Lieu introuvable.');
        }

        return $this->row($p, null) + ['body' => $p->getBody()];
    }

    /**
     * @param mixed $body objet JSON du lieu
     *
     * @return array{id: string, deleteToken: string, createdAt: string}
     */
    public function create(string $slug, ?string $linkToken, string $collection, mixed $lat, mixed $lon, mixed $body, int $sizeBytes, ?User $user, string $ip, ?string $device = null): array
    {
        [$artifact, $conf] = $this->open($slug, $linkToken, $user, $collection);
        if (!is_numeric($lat) || !is_numeric($lon)) {
            throw new BadRequestHttpException('lat et lon (nombres) requis.');
        }
        $lat = (float) $lat;
        $lon = (float) $lon;
        self::checkPoint($lat, $lon);
        if (!\is_array($body) || ($body !== [] && array_is_list($body))) {
            throw new BadRequestHttpException('body : objet JSON attendu.');
        }
        if ($sizeBytes > $conf['maxBytes']) {
            throw new BadRequestHttpException(sprintf('Lieu trop volumineux (%d Ko au plus).', intdiv($conf['maxBytes'], 1024)));
        }
        if ($this->places->countFor($artifact, $collection) >= $conf['maxRecords']) {
            throw new ConflictHttpException('Cet artefact a atteint son nombre maximal de lieux.');
        }
        $ipHash = hash('sha256', $artifact->getId()->toRfc4122().'|'.$ip);
        if ($this->places->countRecentByIp($artifact, $ipHash, new \DateTimeImmutable('-1 day')) >= self::DAILY_PER_IP) {
            throw new TooManyRequestsHttpException(3600, 'Trop de lieux posés aujourd’hui depuis ce réseau.');
        }
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $place = new ArtifactPlace($artifact, $collection, round($lat, 7), round($lon, 7), $body, self::summary($body), $sizeBytes, hash('sha256', $token), $ipHash);
        $place->setAuthor($user);
        $place->setVisitor($this->visitors->resolve($artifact, $collection, $device, $user, true));
        $this->em->persist($place);
        $this->em->flush();

        return ['id' => $place->getId()->toRfc4122(), 'deleteToken' => $token, 'createdAt' => $place->getCreatedAt()->format(\DateTimeInterface::ATOM)];
    }

    public function delete(string $slug, ?string $linkToken, string $collection, string $id, ?string $deleteToken, ?User $user): void
    {
        [$artifact] = $this->open($slug, $linkToken, $user, $collection);
        $p = $this->find($artifact, $collection, $id);
        $byToken = $deleteToken !== null && $deleteToken !== '' && hash_equals($p->getDeleteTokenHash(), hash('sha256', $deleteToken));
        $byAuthor = $user !== null && $p->getAuthor() !== null && $p->getAuthor()->getId()->equals($user->getId());
        if (!$byToken && !$byAuthor && !$this->isModerator($artifact, $user)) {
            throw new AccessDeniedHttpException('Seul le poseur ou un membre de l’artefact peut supprimer ce lieu.');
        }
        $this->em->remove($p);
        $this->em->flush();
    }

    /**
     * Surface de remplacement d'un lieu (mur repeint, affiche changée) : n'importe quel visiteur peut en ajouter
     * une ; la référence d'origine reste, les MAX_EXTRA_REFS plus récentes sont gardées.
     *
     * @return array{refs: int, sizeBytes: int}
     */
    public function addRef(string $slug, ?string $linkToken, string $collection, string $id, mixed $ref, ?User $user, mixed $at = null): array
    {
        [$artifact, $conf] = $this->open($slug, $linkToken, $user, $collection);
        if (!\is_array($ref) || $ref === [] || array_is_list($ref)) {
            throw new BadRequestHttpException('ref : objet JSON attendu (référence de sonic-anchor-ref).');
        }
        $p = $this->find($artifact, $collection, $id);
        // le mur a changé : il n'est plus reconnu, seule la position compte
        if (!self::near($p, $at)) {
            throw new AccessDeniedHttpException('Pour remplacer la surface, il faut être devant le mur.');
        }
        $size = $p->addRef($ref, self::MAX_EXTRA_REFS, $conf['maxBytes']);
        if ($size === null) {
            throw new BadRequestHttpException(sprintf('Surface trop volumineuse pour ce lieu (%d Ko au plus).', intdiv($conf['maxBytes'], 1024)));
        }
        $this->em->flush();
        $refs = $p->getBody()['refs'] ?? [];

        return ['refs' => \is_array($refs) ? \count($refs) : 0, 'sizeBytes' => $size];
    }

    /**
     * Discussion d'un lieu : le message posé avec le lieu (`body.message`) en tête, puis les messages des visiteurs.
     *
     * @return array{place: string, messages: list<array<string, mixed>>, count: int}
     */
    public function thread(string $slug, ?string $linkToken, string $collection, string $id, ?User $user): array
    {
        [$artifact] = $this->open($slug, $linkToken, $user, $collection);
        $p = $this->find($artifact, $collection, $id);
        if ($p->isHidden()) {
            throw new NotFoundHttpException('Lieu introuvable.');
        }
        $out = [];
        $at = $p->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $body = $p->getBody();
        // Blaze de l'auteur, tel qu'il est aujourd'hui (pseudo et avatar redessinés compris).
        $blaze = static fn (?ArtifactPlaceVisitor $v, ?string $pseudo): array => [
            'pseudo' => ($v !== null && !$v->isHidden() ? $v->getPseudo() : null) ?? $pseudo,
            'avatar' => ArtifactPlaceVisitors::avatarPath($slug, $collection, $v),
            'auteur' => $v?->getId()->toRfc4122(),
        ];
        $poseur = $blaze($p->getVisitor(), null);
        // Ce qui a été posé avec le lieu ouvre la discussion (média inclus : deux au plus).
        if (\is_string($body['message'] ?? null) && trim($body['message']) !== '' && ($body['effet'] ?? 'message') !== 'dessin') {
            $out[] = ['id' => 'origine', 'kind' => 'text', 'text' => mb_substr($body['message'], 0, ArtifactPlaceMessage::MAX_TEXT), 'at' => $at, 'origin' => true, 'onSite' => true] + $poseur;
        }
        // Pièces jointes à la pose : data: URL, ou {url, mime, …} (sonic-anchor-ref).
        $dessin = self::mediaUrl($body['dessin'] ?? null);
        if ($dessin !== null && self::mediaKind($dessin) === 'drawing') {
            $out[] = ['id' => 'origine-dessin', 'kind' => 'drawing', 'text' => '', 'at' => $at, 'origin' => true, 'onSite' => true, 'media' => $dessin, 'cadre' => $body['cadreDessin'] ?? null] + $poseur;
        }
        $voix = self::mediaUrl($body['voix'] ?? null);
        if ($voix !== null && self::mediaKind($voix) === 'voice') {
            $out[] = ['id' => 'origine-voix', 'kind' => 'voice', 'text' => '', 'at' => $at, 'origin' => true, 'onSite' => true, 'media' => $voix] + $poseur;
        }
        foreach ($this->messages->latestFor($p, self::THREAD_LIMIT) as $m) {
            $row = ['id' => $m->getId()->toRfc4122(), 'kind' => $m->getKind(), 'text' => $m->getText(), 'at' => $m->getCreatedAt()->format(\DateTimeInterface::ATOM), 'onSite' => $m->isOnSite()] + $blaze($m->getVisitor(), $m->getPseudo());
            // Retiré ou masqué : une trace sans contenu, pour qu'on sache qu'il y avait quelque chose.
            if ($m->isDeleted() || $m->isHidden()) {
                $out[] = ['id' => $row['id'], 'kind' => $m->getKind(), 'text' => '', 'pseudo' => null, 'at' => $row['at'], 'onSite' => $m->isOnSite(),
                    'deleted' => $m->isDeleted() ? $m->getDeletedBy() : 'signalements', 'deletedAt' => $m->getDeletedAt()?->format(\DateTimeInterface::ATOM)];
                continue;
            }
            if ($m->getEditedAt() !== null) {
                $row['editedAt'] = $m->getEditedAt()->format(\DateTimeInterface::ATOM);
            }
            if ($m->getRefId() !== null) {
                $row['refId'] = $m->getRefId();
            }
            if ($m->getMedia() !== null) {
                $row['hasMedia'] = true;
            }
            if ($m->getFrame() !== null) {
                $row['cadre'] = $m->getFrame();
            }
            $out[] = $row;
        }

        return ['place' => $p->getId()->toRfc4122(), 'messages' => $out, 'count' => $p->getMessageCount()];
    }

    /** Média d'un message (vocal ou dessin), servi à part pour que la liste reste légère. @return array{id: string, kind: string, media: string} */
    public function messageMedia(string $slug, ?string $linkToken, string $collection, string $id, string $messageId, ?User $user): array
    {
        [$artifact] = $this->open($slug, $linkToken, $user, $collection);
        $p = $this->find($artifact, $collection, $id);
        $m = Uuid::isValid($messageId) ? $this->messages->find(Uuid::fromString($messageId)) : null;
        if ($m === null || $m->isHidden() || $m->isDeleted() || $p->isHidden() || !$m->getPlace()->getId()->equals($p->getId()) || $m->getMedia() === null) {
            throw new NotFoundHttpException('Média introuvable.');
        }

        return ['id' => $m->getId()->toRfc4122(), 'kind' => $m->getKind(), 'media' => $m->getMedia()];
    }

    /** URL d'un média joint : la chaîne elle-même, ou le champ `url` d'un objet {url, mime, …}. */
    public static function mediaUrl(mixed $v): ?string
    {
        if (\is_string($v)) {
            return $v;
        }
        if (\is_array($v) && \is_string($v['url'] ?? null)) {
            return $v['url'];
        }

        return null;
    }

    /** voice | drawing pour une data: URL base64 acceptée, sinon null. */
    public static function mediaKind(mixed $v): ?string
    {
        if (!\is_string($v) || \strlen($v) > ArtifactPlaceMessage::MAX_MEDIA) {
            return null;
        }
        if (preg_match('#^data:image/(svg\+xml|png|jpeg|webp)(;[a-z0-9=.-]+)*(;base64)?,#i', $v) === 1) {
            return 'drawing';
        }
        if (preg_match('#^data:audio/(webm|ogg|mp4|mpeg|wav|aac|x-m4a)(;[a-z0-9=.-]+)*;base64,#i', $v) === 1) {
            return 'voice';
        }

        return null;
    }

    /** @return array{id: string, deleteToken: string, at: string} */
    /**
     * Message dans la discussion d'un lieu. Sur place (position `at` = {lat, lon, accuracy} près du lieu, et
     * `at.seen` : le mur a été reconnu par la caméra) : texte, vocal ou dessin. À distance : un commentaire
     * texte seulement, marqué `onSite: false` dans le fil.
     */
    public function postMessage(string $slug, ?string $linkToken, string $collection, string $id, mixed $text, mixed $pseudo, ?User $user, string $ip, mixed $media = null, mixed $at = null, mixed $frame = null, mixed $refId = null, ?string $device = null): array
    {
        [$artifact] = $this->open($slug, $linkToken, $user, $collection);
        $p = $this->find($artifact, $collection, $id);
        if ($p->isHidden()) {
            throw new NotFoundHttpException('Lieu introuvable.');
        }
        $text = \is_string($text) ? trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '') : '';
        $kind = 'text';
        if ($media !== null && $media !== '') {
            $kind = self::mediaKind($media);
            if ($kind === null) {
                throw new BadRequestHttpException(sprintf('media : vocal (data:audio/…;base64) ou dessin (data:image/…) de %d Ko au plus.', intdiv(ArtifactPlaceMessage::MAX_MEDIA, 1024)));
            }
        } elseif ($text === '') {
            throw new BadRequestHttpException('text : message vide.');
        }
        $onSite = self::near($p, $at) && \is_array($at) && ($at['seen'] ?? false) === true;
        if ($kind !== 'text' && !$onSite) {
            throw new AccessDeniedHttpException('Pour laisser un vocal ou un dessin, va sur place et vise le mur.');
        }
        if (mb_strlen($text) > ArtifactPlaceMessage::MAX_TEXT) {
            throw new BadRequestHttpException(sprintf('Message trop long (%d caractères au plus).', ArtifactPlaceMessage::MAX_TEXT));
        }
        $pseudo = \is_string($pseudo) ? trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $pseudo) ?? '') : '';
        $pseudo = $pseudo === '' ? null : mb_substr($pseudo, 0, ArtifactPlaceMessage::MAX_PSEUDO);
        $ipHash = hash('sha256', $artifact->getId()->toRfc4122().'|'.$ip);
        if ($this->messages->countRecentByIp($p, $ipHash, new \DateTimeImmutable('-1 day')) >= self::DAILY_MESSAGES_PER_IP) {
            throw new TooManyRequestsHttpException(3600, 'Trop de messages aujourd’hui depuis ce réseau.');
        }
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $m = new ArtifactPlaceMessage($p, $kind, $text, $kind === 'text' ? null : (string) $media, $pseudo, $ipHash, hash('sha256', $token), $onSite, is_numeric($frame) ? (int) $frame : null);
        if ($kind === 'drawing') {
            $m->setRefId(self::refId($refId));
        }
        $m->setVisitor($this->visitors->resolve($artifact, $collection, $device, $user, true));
        $p->addMessages(1);
        $this->em->persist($m);
        $this->em->flush();

        return ['id' => $m->getId()->toRfc4122(), 'kind' => $kind, 'onSite' => $onSite, 'deleteToken' => $token, 'at' => $m->getCreatedAt()->format(\DateTimeInterface::ATOM)];
    }

    /** Signale un message (masqué au 3e signalement) ; ou le retire, avec le jeton de l'appareil qui l'a posé. */
    public function moderateMessage(string $slug, ?string $linkToken, string $collection, string $id, string $messageId, ?string $deleteToken, ?User $user): void
    {
        [$artifact] = $this->open($slug, $linkToken, $user, $collection);
        $p = $this->find($artifact, $collection, $id);
        $m = Uuid::isValid($messageId) ? $this->messages->find(Uuid::fromString($messageId)) : null;
        if ($m === null || !$m->getPlace()->getId()->equals($p->getId())) {
            throw new NotFoundHttpException('Message introuvable.');
        }
        $wasVisible = !$m->isHidden();
        if ($deleteToken !== null && $deleteToken !== '') {
            $byAuthor = hash_equals($m->getDeleteTokenHash(), hash('sha256', $deleteToken));
            if (!$byAuthor && !$this->isModerator($artifact, $user)) {
                throw new AccessDeniedHttpException('Seul l’auteur ou un membre de l’artefact peut retirer ce message.');
            }
            if ($m->isDeleted()) {
                return;
            }
            // Le contenu part ; la ligne reste dans le fil (« retiré par son auteur / par la modération »).
            $m->markDeleted($byAuthor ? 'auteur' : 'moderation');
            if ($wasVisible) {
                $p->addMessages(-1);
            }
        } else {
            $m->report(self::HIDE_AT);
            if ($wasVisible && $m->isHidden()) {
                $p->addMessages(-1);
            }
            $this->em->flush();
            $this->moderation->reported($artifact, 'message', $m->getId(), $m->getReports(), $m->isHidden(),
                ['text' => 'message', 'voice' => 'vocal', 'drawing' => 'dessin'][$m->getKind()].($m->getText() !== '' ? ' « '.mb_substr($m->getText(), 0, 60).' »' : ''));

            return;
        }
        $this->em->flush();
    }

    /**
     * Retouche d'un message par son auteur (jeton de l'appareil qui l'a posé). Texte : nouveau texte. Vocal et dessin :
     * nouveau média du même genre, sur place seulement (comme à la pose) ; un dessin garde ou change son cadre et la
     * référence du mur. Le fil le marque « modifié ».
     *
     * @return array{id: string, editedAt: string}
     */
    public function editMessage(string $slug, ?string $linkToken, string $collection, string $id, string $messageId, ?string $token, mixed $text, mixed $media, mixed $at, mixed $frame, mixed $refId, ?User $user): array
    {
        [$artifact] = $this->open($slug, $linkToken, $user, $collection);
        $p = $this->find($artifact, $collection, $id);
        $m = Uuid::isValid($messageId) ? $this->messages->find(Uuid::fromString($messageId)) : null;
        if ($m === null || $p->isHidden() || $m->isHidden() || $m->isDeleted() || !$m->getPlace()->getId()->equals($p->getId())) {
            throw new NotFoundHttpException('Message introuvable.');
        }
        if ($token === null || $token === '' || !hash_equals($m->getDeleteTokenHash(), hash('sha256', $token))) {
            throw new AccessDeniedHttpException('Seul l’auteur peut retoucher ce message, depuis l’appareil qui l’a posé.');
        }
        $text = \is_string($text) ? trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '') : $m->getText();
        if (mb_strlen($text) > ArtifactPlaceMessage::MAX_TEXT) {
            throw new BadRequestHttpException(sprintf('Message trop long (%d caractères au plus).', ArtifactPlaceMessage::MAX_TEXT));
        }
        $newMedia = null;
        if ($m->getKind() === 'text') {
            if ($text === '') {
                throw new BadRequestHttpException('text : message vide.');
            }
        } elseif ($media !== null && $media !== '') {
            if (self::mediaKind($media) !== $m->getKind()) {
                throw new BadRequestHttpException($m->getKind() === 'drawing' ? 'media : un dessin (data:image/…) est attendu.' : 'media : un vocal (data:audio/…;base64) est attendu.');
            }
            if (!(self::near($p, $at) && \is_array($at) && ($at['seen'] ?? false) === true)) {
                throw new AccessDeniedHttpException('Pour retoucher un vocal ou un dessin, va sur place et vise le mur.');
            }
            $newMedia = (string) $media;
        }
        $m->edit($text, $newMedia, is_numeric($frame) ? (int) $frame : null, $refId === null ? null : self::refId($refId));
        $this->em->flush();

        return ['id' => $m->getId()->toRfc4122(), 'editedAt' => $m->getEditedAt()?->format(\DateTimeInterface::ATOM) ?? ''];
    }

    /**
     * Retouche d'un lieu par son poseur (jeton de l'appareil, ou compte auteur) : texte, effet, dessin (et son cadre),
     * vocal. Un nouveau dessin ou vocal demande d'être sur place, mur reconnu. Les surfaces (refs) ne changent pas ici.
     *
     * @return array{id: string, editedAt: string}
     */
    public function updatePlace(string $slug, ?string $linkToken, string $collection, string $id, ?string $token, mixed $patch, mixed $at, ?User $user): array
    {
        [$artifact, $conf] = $this->open($slug, $linkToken, $user, $collection);
        $p = $this->find($artifact, $collection, $id);
        $byToken = $token !== null && $token !== '' && hash_equals($p->getDeleteTokenHash(), hash('sha256', $token));
        $byAuthor = $user !== null && $p->getAuthor() !== null && $p->getAuthor()->getId()->equals($user->getId());
        if (!$byToken && !$byAuthor) {
            throw new AccessDeniedHttpException('Seul le poseur peut retoucher cette balise, depuis l’appareil qui l’a posée.');
        }
        if (!\is_array($patch) || $patch === [] || array_is_list($patch)) {
            throw new BadRequestHttpException('body : objet JSON attendu.');
        }
        $allowed = array_intersect_key($patch, array_flip(self::EDITABLE));
        if ($allowed === []) {
            throw new BadRequestHttpException(sprintf('Champs modifiables : %s.', implode(', ', self::EDITABLE)));
        }
        if (\array_key_exists('message', $allowed)) {
            $t = \is_string($allowed['message']) ? trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $allowed['message']) ?? '') : '';
            if (mb_strlen($t) > ArtifactPlaceMessage::MAX_TEXT) {
                throw new BadRequestHttpException(sprintf('Message trop long (%d caractères au plus).', ArtifactPlaceMessage::MAX_TEXT));
            }
            $allowed['message'] = $t;
        }
        foreach (['dessin' => 'drawing', 'voix' => 'voice'] as $k => $kind) {
            if (!\array_key_exists($k, $allowed) || $allowed[$k] === null || $allowed[$k] === '') {
                continue;
            }
            if (self::mediaKind($allowed[$k]) !== $kind) {
                throw new BadRequestHttpException(sprintf('%s : %s attendu.', $k, $kind === 'drawing' ? 'un dessin (data:image/…)' : 'un vocal (data:audio/…;base64)'));
            }
            if (!(self::near($p, $at) && \is_array($at) && ($at['seen'] ?? false) === true)) {
                throw new AccessDeniedHttpException('Pour changer le dessin ou le vocal, va sur place et vise le mur.');
            }
        }
        $body = array_merge($p->getBody(), $allowed, ['editedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)]);
        $size = \strlen((string) json_encode($body, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
        if ($size > $conf['maxBytes']) {
            throw new BadRequestHttpException(sprintf('Lieu trop volumineux (%d Ko au plus).', intdiv($conf['maxBytes'], 1024)));
        }
        $p->replaceBody($body, self::summary($body), $size);
        $this->em->flush();

        return ['id' => $p->getId()->toRfc4122(), 'editedAt' => $body['editedAt']];
    }

    /** Référence de surface (id de sonic-anchor-ref) : courte, sûre, sinon null. */
    private static function refId(mixed $v): ?string
    {
        return \is_string($v) && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $v) === 1 ? $v : null;
    }

    /** @return array{reports: int, hidden: bool} */
    public function report(string $slug, ?string $linkToken, string $collection, string $id, ?User $user): array
    {
        [$artifact] = $this->open($slug, $linkToken, $user, $collection);
        $p = $this->find($artifact, $collection, $id);
        $p->report(self::HIDE_AT);
        $this->em->flush();
        $this->moderation->reported($artifact, 'place', $p->getId(), $p->getReports(), $p->isHidden(), 'balise'.(\is_string($p->getBody()['message'] ?? null) ? ' « '.mb_substr($p->getBody()['message'], 0, 60).' »' : ''));

        return ['reports' => $p->getReports(), 'hidden' => $p->isHidden()];
    }

    /** Membre : masque ou réaffiche un lieu signalé. */
    public function setHidden(string $slug, ?string $linkToken, string $collection, string $id, bool $hidden, ?User $user): void
    {
        [$artifact] = $this->open($slug, $linkToken, $user, $collection);
        if (!$this->isModerator($artifact, $user)) {
            throw new AccessDeniedHttpException('Réservé aux membres de l’artefact.');
        }
        $this->find($artifact, $collection, $id)->setHidden($hidden);
        $this->em->flush();
    }

    /**
     * Configuration d'une collection de lieux déclarée par le document.
     *
     * @return array{maxBytes: int, maxRecords: int, radius: int}
     */
    public static function config(mixed $def): array
    {
        $d = \is_array($def) ? $def : [];

        return [
            'maxBytes' => max(1024, min(self::HARD_MAX_BYTES, \is_int($d['maxBytes'] ?? null) ? $d['maxBytes'] : self::DEFAULT_MAX_BYTES)),
            'maxRecords' => max(1, min(self::HARD_MAX_RECORDS, \is_int($d['maxRecords'] ?? null) ? $d['maxRecords'] : self::DEFAULT_MAX_RECORDS)),
            'radius' => max(10, min(self::MAX_RADIUS, \is_int($d['radius'] ?? null) ? $d['radius'] : 300)),
        ];
    }

    /**
     * Position déclarée `{lat, lon, accuracy?}` à moins de ON_SITE_METERS du lieu, plus la précision annoncée
     * (plafonnée à ON_SITE_ACCURACY_CAP) : le GPS en ville dérive de quelques dizaines de mètres.
     */
    public static function near(ArtifactPlace $p, mixed $at): bool
    {
        if (!\is_array($at) || !is_numeric($at['lat'] ?? null) || !is_numeric($at['lon'] ?? null)) {
            return false;
        }
        $lat = (float) $at['lat'];
        $lon = (float) $at['lon'];
        if (!is_finite($lat) || !is_finite($lon) || abs($lat) > 90 || abs($lon) > 180) {
            return false;
        }
        $acc = is_numeric($at['accuracy'] ?? null) ? (float) $at['accuracy'] : 50.0;
        $acc = is_finite($acc) ? min(self::ON_SITE_ACCURACY_CAP, max(0.0, $acc)) : self::ON_SITE_ACCURACY_CAP;

        return self::distance($lat, $lon, $p->getLat(), $p->getLon()) <= self::ON_SITE_METERS + $acc;
    }

    /** Distance en mètres (haversine). */
    public static function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lon2 - $lon1) / 2) ** 2;

        return 2 * 6_371_008.8 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Champs courts du corps, servis dans les listes : textes ≤ 200 caractères, nombres, booléens (16 au plus).
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public static function summary(array $body): array
    {
        $out = [];
        foreach ($body as $k => $v) {
            if (\count($out) >= self::SUMMARY_KEYS) {
                break;
            }
            if (!\is_string($k) || \in_array($k, ['id', 'lat', 'lon', 'distance', 'mine', 'hidden', 'reports'], true)) {
                continue;
            }
            if (\is_string($v) && mb_strlen($v) <= self::SUMMARY_STRING && !str_starts_with($v, 'data:')) {
                $out[$k] = $v;
            } elseif (\is_int($v) || \is_float($v) || \is_bool($v)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    private static function checkPoint(float $lat, float $lon): void
    {
        if (!is_finite($lat) || !is_finite($lon) || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            throw new BadRequestHttpException('Position invalide.');
        }
    }

    /** @return array<string, mixed> */
    private function row(ArtifactPlace $p, ?float $distance): array
    {
        $row = $p->getSummary() + [
            'id' => $p->getId()->toRfc4122(),
            'lat' => $p->getLat(),
            'lon' => $p->getLon(),
            'createdAt' => $p->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'sizeBytes' => $p->getSizeBytes(),
            'messages' => $p->getMessageCount(),
        ];
        if ($distance !== null) {
            $row['distance'] = round($distance, 1);
        }
        if ($p->isHidden() || $p->getReports() > 0) {
            $row['reports'] = $p->getReports();
            $row['hidden'] = $p->isHidden();
        }

        return $row;
    }

    /**
     * Pour les parcours : la collection ouverte au visiteur, ses lieux et la modération.
     *
     * @return array{0: Artifact, 1: array{maxBytes: int, maxRecords: int, radius: int}}
     */
    public function openCollection(string $slug, ?string $linkToken, ?User $user, string $collection): array
    {
        return $this->open($slug, $linkToken, $user, $collection);
    }

    public function findPlace(Artifact $artifact, string $collection, string $id): ArtifactPlace
    {
        return $this->find($artifact, $collection, $id);
    }

    public function canModerate(Artifact $artifact, ?User $user): bool
    {
        return $this->isModerator($artifact, $user);
    }

    private function find(Artifact $artifact, string $collection, string $id): ArtifactPlace
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Lieu introuvable.');
        }
        $p = $this->places->find(Uuid::fromString($id));
        if ($p === null || !$p->getArtifact()->getId()->equals($artifact->getId()) || $p->getCollection() !== $collection) {
            throw new NotFoundHttpException('Lieu introuvable.');
        }

        return $p;
    }

    private function isModerator(Artifact $artifact, ?User $user): bool
    {
        return $user !== null && $this->access->getRole($user, $artifact->getDataset()) !== null;
    }

    /**
     * Artefact lisible par le visiteur, dont la version en ligne déclare `data.places.<collection>`.
     *
     * @return array{0: Artifact, 1: array{maxBytes: int, maxRecords: int, radius: int}}
     */
    private function open(string $slug, ?string $linkToken, ?User $user, string $collection): array
    {
        $artifact = $this->artifacts->findOneBySlug($slug);
        if ($artifact === null) {
            throw new NotFoundHttpException('Artefact introuvable.');
        }
        $vis = $artifact->getVisibility();
        $open = $vis === ArtifactVisibility::Public
            || ($vis === ArtifactVisibility::Link && $artifact->getLinkToken() !== null && $linkToken !== null && hash_equals($artifact->getLinkToken(), $linkToken))
            || ($user !== null && $this->access->getRole($user, $artifact->getDataset()) !== null);
        if (!$open) {
            throw new NotFoundHttpException('Artefact introuvable.');
        }
        $version = $this->versions->findOneForArtifactVersion($artifact, $artifact->getLiveVersion());
        $def = $version?->getDocument()['data']['places'][$collection] ?? null;
        if (!\is_array($def)) {
            throw new NotFoundHttpException('Cet artefact ne déclare pas ces lieux.');
        }

        return [$artifact, self::config($def)];
    }
}
