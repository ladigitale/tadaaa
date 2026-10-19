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
     * Vérifie la syntaxe de toutes les expressions JSONata du document (réducteurs, transforms).
     * Retourne [] si tout est valide ou si le vérificateur est indisponible (contrôle best-effort).
     *
     * @param array<string, mixed> $document
     *
     * @return list<array{path: string, message: string}>
     */
    public function checkJsonata(array $document): array
    {
        $items = [];
        foreach ((\is_array($document['data']['stores'] ?? null) ? $document['data']['stores'] : []) as $name => $def) {
            if (\is_array($def) && \is_string($def['reducer'] ?? null)) {
                $items[] = ['path' => '/data/stores/'.$name.'/reducer', 'expr' => $def['reducer']];
            }
        }
        foreach ((\is_array($document['data']['transforms'] ?? null) ? $document['data']['transforms'] : []) as $name => $def) {
            if (\is_array($def) && \is_string($def['jsonata'] ?? null)) {
                $items[] = ['path' => '/data/transforms/'.$name.'/jsonata', 'expr' => $def['jsonata']];
            }
        }
        $walk = static function (mixed $node, string $path) use (&$walk, &$items): void {
            if (!\is_array($node)) {
                return;
            }
            $attrs = \is_array($node['attributes'] ?? null) ? $node['attributes'] : [];
            if (($node['tagName'] ?? null) === 'sonic-store' && \is_string($attrs['reducer'] ?? null)) {
                $items[] = ['path' => $path.'/attributes/reducer', 'expr' => $attrs['reducer']];
            }
            foreach (\is_array($node['nodes'] ?? null) ? $node['nodes'] : [] as $i => $child) {
                $walk($child, $path.'/nodes/'.$i);
            }
        };
        foreach (\is_array($document['views'] ?? null) ? array_values($document['views']) : [] as $i => $view) {
            if (\is_array($view) && \is_array($view['root'] ?? null)) {
                $walk($view['root'], '/views/'.$i.'/root');
            }
        }
        if ($items === [] || !is_dir($this->toolDir.'/node_modules/jsonata')) {
            return [];
        }
        $cmd = [$this->nodeBinary ?: 'node', '--permission', '--allow-fs-read='.$this->toolDir, '--max-old-space-size=128', $this->toolDir.'/check-jsonata.mjs'];
        $run = SubprocessRunner::run($cmd, $this->toolDir, (string) json_encode(['items' => $items], JSON_UNESCAPED_UNICODE), 5, self::MAX_OUTPUT, ['PATH' => (string) getenv('PATH')]);
        $decoded = $run['status'] === 'ok' ? json_decode($run['stdout'], true) : null;
        if (!\is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ((array) ($decoded['errors'] ?? []) as $e) {
            if (\is_array($e) && \is_string($e['path'] ?? null)) {
                $out[] = ['path' => $e['path'], 'message' => 'JSONata invalide : '.($e['message'] ?? '').(isset($e['position']) ? ' (position '.$e['position'].')' : '')];
            }
        }

        return $out;
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
