<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Artifact;
use App\Entity\Dataset;
use App\Entity\Tag;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Catégories des artefacts : les étiquettes (`Tag`) de l'espace de l'artefact, données par leur nom.
 * Un nom inconnu crée l'étiquette dans l'espace (comme `create_tag`) ; la première est la catégorie
 * principale, affichée sur les cartes de la vitrine et de la galerie.
 */
final class ArtifactTags
{
    public const MAX = 6;
    public const NAME_MAX = 40;

    /** @var array<string, list<Tag>> étiquettes vivantes par espace, le temps d'une requête */
    private array $cache = [];

    public function __construct(
        private readonly TagRepository $tags,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Noms nettoyés : chaînes, sans espaces superflus, sans doublon (casse ignorée), bornés.
     *
     * @return list<string>
     */
    public static function normalizeNames(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        // Client qui envoie la liste en texte (schéma d'outil pas à jour) : JSON, sinon noms séparés par des virgules.
        if (\is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = \is_array($decoded) ? $decoded : explode(',', $raw);
        }
        if (!\is_array($raw) || !array_is_list($raw)) {
            throw new BadRequestHttpException('tags : liste de noms attendue, ex. ["Jeu", "3D"].');
        }
        $out = [];
        $seen = [];
        foreach ($raw as $name) {
            if (!\is_string($name)) {
                throw new BadRequestHttpException('tags : chaque étiquette est un nom (chaîne).');
            }
            $name = trim((string) preg_replace('/\s+/u', ' ', $name));
            if ($name === '') {
                continue;
            }
            if (mb_strlen($name) > self::NAME_MAX) {
                throw new BadRequestHttpException(\sprintf('tags : « %s » dépasse %d caractères.', $name, self::NAME_MAX));
            }
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $name;
        }
        if (\count($out) > self::MAX) {
            throw new BadRequestHttpException(\sprintf('tags : %d étiquettes au plus.', self::MAX));
        }

        return $out;
    }

    /**
     * Range l'artefact sous ces étiquettes (dans l'ordre donné), en créant celles qui manquent.
     *
     * @param list<string> $names
     */
    public function assign(Artifact $artifact, array $names): void
    {
        $dataset = $artifact->getDataset();
        $byName = [];
        foreach ($this->live($dataset) as $tag) {
            $byName[mb_strtolower($tag->getName())] ??= $tag;
        }
        $ids = [];
        foreach ($names as $name) {
            $tag = $byName[mb_strtolower($name)] ?? null;
            if ($tag === null) {
                $tag = new Tag('tag-'.bin2hex(random_bytes(8)), $dataset);
                $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
                $tag->setName($name);
                $tag->setColor('default');
                $tag->setFieldVersions(['name' => $now, 'color' => $now]);
                $this->em->persist($tag);
                $byName[mb_strtolower($name)] = $tag;
                $dataset->touch();
            }
            $ids[] = $tag->getId();
        }
        $artifact->setTagIds($ids);
        unset($this->cache[$dataset->getId()->toRfc4122()]);
    }

    /**
     * Étiquettes de l'artefact encore présentes dans son espace, dans son ordre.
     *
     * @return list<array{id: string, name: string, color: string}>
     */
    public function describe(Artifact $artifact): array
    {
        $ids = $artifact->getTagIds();
        if ($ids === []) {
            return [];
        }
        $byId = [];
        foreach ($this->live($artifact->getDataset()) as $tag) {
            $byId[$tag->getId()] = $tag;
        }
        $out = [];
        foreach ($ids as $id) {
            $tag = $byId[$id] ?? null;
            if ($tag !== null) {
                $out[] = ['id' => $tag->getId(), 'name' => $tag->getName(), 'color' => $tag->getColor()];
            }
        }

        return $out;
    }

    /** @return list<Tag> */
    private function live(Dataset $dataset): array
    {
        return $this->cache[$dataset->getId()->toRfc4122()] ??= array_values(array_filter($this->tags->findChangedSince($dataset, null), static fn (Tag $t) => !$t->isDeleted()));
    }
}
