<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Mise à jour de production (`scripts/update-prod.sh --pull`) déclenchée depuis le MCP.
 *
 * L'API ne lance rien elle-même : elle dépose une demande dans `OPS_DIR/requests/`, un
 * répertoire partagé avec l'hôte. Le runner hôte (`scripts/ops/deploy-runner.sh`, lancé
 * par une unité systemd `.path`) la prend, exécute la mise à jour et écrit l'état et le
 * journal dans `OPS_DIR/runs/`. L'état survit ainsi à la recréation du conteneur PHP.
 *
 * Réservé aux comptes ROLE_ADMIN dont l'e-mail figure dans `OPS_DEPLOY_USERS`.
 * Sans `OPS_DIR` ni `OPS_DEPLOY_USERS`, la fonction est désactivée.
 */
final class ProdDeploy
{
    /** Au-delà, un run resté « running » est considéré comme interrompu. */
    public const STALE_AFTER_SECONDS = 5400;

    private const ID_PATTERN = '/^[A-Za-z0-9-]{8,64}$/';
    private const MAX_TAIL = 400;
    private const REASON_MAX = 500;

    /** @var list<string> */
    private readonly array $allowedUsers;

    public function __construct(
        private readonly string $opsDir,
        string $allowedUsers,
    ) {
        $this->allowedUsers = array_values(array_filter(array_map(
            static fn (string $e): string => strtolower(trim($e)),
            explode(',', $allowedUsers),
        ), static fn (string $e): bool => $e !== ''));
    }

    public function isAllowed(User $user): bool
    {
        return \in_array('ROLE_ADMIN', $user->getRoles(), true)
            && \in_array(strtolower($user->getEmail()), $this->allowedUsers, true);
    }

    public function assertAllowed(User $user): void
    {
        if (!$this->isAllowed($user)) {
            throw new AccessDeniedHttpException('Réservé au super admin (ROLE_ADMIN + OPS_DEPLOY_USERS).');
        }
        if (!$this->isConfigured()) {
            throw new ServiceUnavailableHttpException(null, 'Mise à jour prod non configurée (OPS_DIR absent ou non monté, voir .ops/deploy.md).');
        }
    }

    public function isConfigured(): bool
    {
        return $this->opsDir !== '' && is_dir($this->opsDir) && is_writable($this->opsDir);
    }

    /** @return array<string, mixed> */
    public function trigger(User $user, ?string $reason = null): array
    {
        $this->assertAllowed($user);
        $this->ensureDirs();

        $lock = fopen($this->opsDir.'/.trigger.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new ServiceUnavailableHttpException(null, 'Verrou de déclenchement indisponible.');
        }
        try {
            $active = $this->activeRun();
            if ($active !== null) {
                throw new ConflictHttpException(sprintf(
                    'Une mise à jour est déjà %s (runId %s).',
                    $active['status'] === 'queued' ? 'en attente' : 'en cours',
                    $active['id'],
                ));
            }

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $id = $now->format('Ymd-His').'-'.bin2hex(random_bytes(3));
            $reason = $reason === null ? null : mb_substr(trim($reason), 0, self::REASON_MAX);
            $request = [
                'id' => $id,
                'command' => 'update-prod --pull',
                'requestedBy' => $user->getEmail(),
                'requestedAt' => $now->format(\DateTimeInterface::ATOM),
                'reason' => $reason === '' ? null : $reason,
            ];
            $this->writeJsonAtomic($this->opsDir.'/requests/'.$id.'.json', $request);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $request + ['status' => 'queued'];
    }

    /** @return array<string, mixed> */
    public function status(User $user, ?string $id = null, int $tail = 60): array
    {
        $this->assertAllowed($user);
        $tail = max(0, min(self::MAX_TAIL, $tail));

        if ($id === null || trim($id) === '') {
            $ids = $this->allIds();
            if ($ids === []) {
                return ['run' => null, 'recent' => []];
            }
            $id = $ids[0];
            $recent = array_map(fn (string $i): array => $this->summary($this->read($i)), \array_slice($ids, 0, 5));
        } else {
            $id = trim($id);
            if (preg_match(self::ID_PATTERN, $id) !== 1) {
                throw new NotFoundHttpException('runId invalide.');
            }
        }

        $run = $this->read($id);
        if ($run === null) {
            throw new NotFoundHttpException(sprintf('Run %s introuvable.', $id));
        }

        $lines = $this->logTail($id, max($tail, 200));
        $run['lastStep'] = $this->lastStep($lines);
        $run['log'] = $tail === 0 ? [] : \array_slice($lines, -$tail);

        $out = ['run' => $run];
        if (isset($recent)) {
            $out['recent'] = $recent;
        }

        return $out;
    }

    /** @return array<string, mixed>|null run en attente ou en cours */
    private function activeRun(): ?array
    {
        foreach ($this->allIds() as $id) {
            $run = $this->read($id);
            if ($run !== null && \in_array($run['status'], ['queued', 'running'], true)) {
                return $run;
            }
        }

        return null;
    }

    /** @return list<string> ids triés du plus récent au plus ancien */
    private function allIds(): array
    {
        $ids = [];
        foreach (['requests', 'runs'] as $sub) {
            foreach (glob($this->opsDir.'/'.$sub.'/*.json') ?: [] as $path) {
                $name = basename($path, '.json');
                if (str_ends_with($name, '.request')) {
                    $name = substr($name, 0, -\strlen('.request'));
                }
                if (preg_match(self::ID_PATTERN, $name) === 1) {
                    $ids[$name] = true;
                }
            }
        }
        $ids = array_keys($ids);
        rsort($ids, SORT_STRING);

        return array_values(array_map('strval', $ids));
    }

    /** @return array<string, mixed>|null */
    private function read(string $id): ?array
    {
        $pending = $this->readJson($this->opsDir.'/requests/'.$id.'.json');
        $request = $this->readJson($this->opsDir.'/runs/'.$id.'.request.json');
        $state = $this->readJson($this->opsDir.'/runs/'.$id.'.json');

        if ($state === null) {
            if ($pending === null && $request === null) {
                return null;
            }

            return ($pending ?? $request) + ['id' => $id, 'status' => 'queued'];
        }

        // Le runner n'écrit que les champs qu'il maîtrise ; la demande garde l'auteur et le motif.
        $run = ['id' => $id] + ($request ?? []) + $state;
        $run['status'] = \is_string($state['status'] ?? null) ? $state['status'] : 'unknown';
        if ($run['status'] === 'running' && $this->isStale($state['startedAt'] ?? null)) {
            $run['status'] = 'stale';
        }

        return $run;
    }

    /** @param array<string, mixed>|null $run @return array<string, mixed> */
    private function summary(?array $run): array
    {
        if ($run === null) {
            return [];
        }

        return array_intersect_key($run, array_flip(['id', 'status', 'requestedBy', 'requestedAt', 'finishedAt', 'fromSha', 'toSha', 'exitCode']));
    }

    private function isStale(mixed $startedAt): bool
    {
        if (!\is_string($startedAt)) {
            return false;
        }
        try {
            return (new \DateTimeImmutable($startedAt))->getTimestamp() < time() - self::STALE_AFTER_SECONDS;
        } catch (\Exception) {
            return false;
        }
    }

    /** @return list<string> dernières lignes du journal, codes ANSI retirés */
    private function logTail(string $id, int $lines): array
    {
        $path = $this->opsDir.'/runs/'.$id.'.log';
        if (!is_file($path) || $lines <= 0) {
            return [];
        }
        $size = filesize($path) ?: 0;
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }
        $chunk = min($size, 128 * 1024);
        fseek($handle, $size - $chunk);
        $text = (string) fread($handle, $chunk);
        fclose($handle);

        $text = preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $text) ?? $text;
        $all = preg_split('/\r?\n|\r/', rtrim($text)) ?: [];
        if ($chunk < $size) {
            array_shift($all); // première ligne probablement tronquée
        }

        return array_values(\array_slice($all, -$lines));
    }

    /** @param list<string> $lines */
    private function lastStep(array $lines): ?string
    {
        for ($i = \count($lines) - 1; $i >= 0; --$i) {
            $line = trim($lines[$i]);
            if (str_starts_with($line, '→') || str_starts_with($line, '✗')) {
                return $line;
            }
        }

        return null;
    }

    private function ensureDirs(): void
    {
        foreach (['requests', 'runs'] as $sub) {
            $dir = $this->opsDir.'/'.$sub;
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new ServiceUnavailableHttpException(null, sprintf('Impossible de créer %s.', $dir));
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return \is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $data */
    private function writeJsonAtomic(string $path, array $data): void
    {
        $tmp = $path.'.tmp-'.bin2hex(random_bytes(4));
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        rename($tmp, $path);
    }
}
