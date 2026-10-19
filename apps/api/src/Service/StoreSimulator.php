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
        $run = SubprocessRunner::run($cmd, $this->toolDir, $payload, self::TIMEOUT_S, self::MAX_OUTPUT, ['PATH' => (string) getenv('PATH')]);
        if ($run['status'] === 'spawn') {
            return ['ok' => false, 'errors' => ['Node introuvable sur ce serveur.']];
        }
        if ($run['status'] === 'timeout') {
            return ['ok' => false, 'errors' => [sprintf('Délai dépassé (%d s) : réducteur trop lent ou boucle infinie.', self::TIMEOUT_S)]];
        }
        if ($run['status'] === 'toolarge') {
            return ['ok' => false, 'errors' => ['Sortie trop volumineuse : réduire maxSteps ou utiliser snapshots.']];
        }
        $out = $run['stdout'];
        $err = $run['stderr'];
        $decoded = json_decode($out, true);
        if (!\is_array($decoded)) {
            return ['ok' => false, 'errors' => ['Simulateur : sortie invalide.'.($err !== '' ? ' '.mb_substr(trim($err), 0, 300) : '')]];
        }

        return $decoded;
    }
}
