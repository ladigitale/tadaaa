<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Suivi des ressources copiées dans un artefact : `resources` (provenance, voir {@see ArtifactFiles})
 * garde la ref, la version et le sha256 de ce qui a été copié dans `files`. Ce service compare avec la
 * version courante de la bibliothèque (« mise à jour disponible ») et applique la mise à jour.
 */
final class ArtifactResourceSync
{
    public function __construct(
        private readonly ArtifactService $artifacts,
        private readonly Resources $resources,
    ) {
    }

    /**
     * @return list<array{file: string, ref: string, usedVersion: int, latestVersion: int|null, updateAvailable: bool, modified: bool, missing: bool}>
     */
    public function updates(User $user, string $idOrSlug): array
    {
        $doc = $this->artifacts->getForUser($user, $idOrSlug)['document'] ?? null;
        $doc = json_decode((string) json_encode($doc), true);
        if (!\is_array($doc)) {
            return [];
        }
        $files = \is_array($doc['files'] ?? null) ? $doc['files'] : [];
        $out = [];
        foreach (\is_array($doc['resources'] ?? null) ? $doc['resources'] : [] as $file => $entry) {
            if (!\is_array($entry) || !\is_string($entry['ref'] ?? null)) {
                continue;
            }
            $file = (string) $file;
            $used = (int) ($entry['version'] ?? 1);
            $latest = null;
            $missing = false;
            try {
                $latest = (int) $this->resources->read($user, $entry['ref'])['meta']['version'];
            } catch (NotFoundHttpException) {
                $missing = true;
            }
            $current = \is_string($files[$file] ?? null) ? $files[$file] : null;
            $modified = isset($entry['sha256']) && $current !== null && hash('sha256', $current) !== $entry['sha256'];
            $out[] = [
                'file' => $file,
                'ref' => $entry['ref'],
                'usedVersion' => $used,
                'latestVersion' => $latest,
                'updateAvailable' => $latest !== null && $latest > $used,
                'modified' => $modified,
                'missing' => $missing,
            ];
        }

        return $out;
    }

    /**
     * Remplace le contenu des fichiers dont une version plus récente existe et crée une nouvelle version
     * de l'artefact. Un fichier modifié à la main n'est écrasé qu'avec `$force`.
     *
     * @param list<string>|null $only noms de fichiers à mettre à jour (null : tous ceux qui ont une mise à jour)
     *
     * @return array{artifact: array<string, mixed>|null, updated: list<string>, skipped: list<array{file: string, reason: string}>}
     */
    public function apply(User $user, string $idOrSlug, ?array $only = null, bool $force = false, ?string $ip = null): array
    {
        $set = [];
        $ops = [];
        $updated = [];
        $skipped = [];
        foreach ($this->updates($user, $idOrSlug) as $u) {
            if ($only !== null && !\in_array($u['file'], $only, true)) {
                continue;
            }
            if (!$u['updateAvailable']) {
                if ($only !== null) {
                    $skipped[] = ['file' => $u['file'], 'reason' => $u['missing'] ? 'ressource introuvable' : 'déjà à jour'];
                }
                continue;
            }
            if ($u['modified'] && !$force) {
                $skipped[] = ['file' => $u['file'], 'reason' => 'modifié dans l’artefact (force requis)'];
                continue;
            }
            $read = $this->resources->read($user, $u['ref']);
            $text = $read['data'];
            if (!mb_check_encoding($text, 'UTF-8') || \strlen($text) > ArtifactFiles::MAX_FILE_BYTES) {
                $skipped[] = ['file' => $u['file'], 'reason' => 'contenu non texte ou trop gros'];
                continue;
            }
            $set[$u['file']] = $text;
            $ops[] = (object) [
                'op' => 'replace',
                'path' => '/resources/'.str_replace(['~', '/'], ['~0', '~1'], $u['file']),
                'value' => (object) ['ref' => $u['ref'], 'version' => (int) $read['meta']['version'], 'sha256' => hash('sha256', $text)],
            ];
            $updated[] = $u['file'];
        }
        if ($set === []) {
            return ['artifact' => null, 'updated' => [], 'skipped' => $skipped];
        }
        $note = 'Mise à jour des ressources : '.implode(', ', $updated);
        $artifact = $this->artifacts->patchDocument($user, $idOrSlug, $ops, [], $note, $ip, false, $set);

        return ['artifact' => $artifact, 'updated' => $updated, 'skipped' => $skipped];
    }
}
