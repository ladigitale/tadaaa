<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Simule un réducteur JSONata de store (outil MCP `simulate_store`) dans un sous-processus Node
 * bridé : modèle de permissions Node (pas de réseau, de processus enfant ni d'écriture ; lecture
 * limitée à tools/simulate-store), mémoire plafonnée, environnement vide, délai dur, sortie bornée.
 */
final class StoreSimulator
{
    public const TIMEOUT_S = 8;
    public const MAX_OUTPUT = 262144;
    public const MAX_REQUEST = 2097152;

    public function __construct(
        #[Autowire('%kernel.project_dir%/tools/simulate-store')]
        private readonly string $toolDir,
        #[Autowire('%env(default::SIMULATE_STORE_NODE)%')]
        private readonly ?string $nodeBinary = null,
    ) {
    }

    /**
     * @param array<string, mixed> $request {document, store, actions, until?, maxSteps?, snapshots?, tickMs?}
     *
     * @return array<string, mixed>
     */
    public function simulate(array $request): array
    {
        $payload = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false || \strlen($payload) > self::MAX_REQUEST) {
            return ['ok' => false, 'errors' => ['Requête illisible ou trop volumineuse.']];
        }
        $script = $this->toolDir.'/simulate-store.mjs';
        if (!is_file($script) || !is_dir($this->toolDir.'/node_modules/jsonata')) {
            return ['ok' => false, 'errors' => ['Simulateur indisponible (dépendances Node non installées sur ce serveur).']];
        }
        $cmd = [
            $this->nodeBinary ?: 'node',
            '--permission',
            '--allow-fs-read='.$this->toolDir,
            '--max-old-space-size=128',
            $script,
        ];
        $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->toolDir, ['PATH' => (string) getenv('PATH')]);
        if (!\is_resource($proc)) {
            return ['ok' => false, 'errors' => ['Node introuvable sur ce serveur.']];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        fwrite($pipes[0], $payload);
        fclose($pipes[0]);

        $out = '';
        $err = '';
        $deadline = microtime(true) + self::TIMEOUT_S;
        $timedOut = false;
        while (true) {
            $out .= (string) fread($pipes[1], 65536);
            $err .= (string) fread($pipes[2], 8192);
            if (!proc_get_status($proc)['running']) {
                $out .= (string) stream_get_contents($pipes[1]);
                $err .= (string) stream_get_contents($pipes[2]);
                break;
            }
            if (\strlen($out) > self::MAX_OUTPUT || microtime(true) > $deadline) {
                $timedOut = microtime(true) > $deadline;
                proc_terminate($proc, 9);
                break;
            }
            usleep(5000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        if ($timedOut) {
            return ['ok' => false, 'errors' => [sprintf('Délai dépassé (%d s) : réducteur trop lent ou boucle infinie.', self::TIMEOUT_S)]];
        }
        if (\strlen($out) > self::MAX_OUTPUT) {
            return ['ok' => false, 'errors' => ['Sortie trop volumineuse : réduire maxSteps ou utiliser snapshots.']];
        }
        $decoded = json_decode($out, true);
        if (!\is_array($decoded)) {
            return ['ok' => false, 'errors' => ['Simulateur : sortie invalide.'.($err !== '' ? ' '.mb_substr(trim($err), 0, 300) : '')]];
        }

        return $decoded;
    }
}
