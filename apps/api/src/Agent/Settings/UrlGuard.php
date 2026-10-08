<?php

declare(strict_types=1);

namespace App\Agent\Settings;

/**
 * Contrôle d'une URL de base libre saisie par un utilisateur, avant enregistrement.
 * Le serveur appellera cette adresse : on refuse ce qui viserait le réseau interne
 * (le blocage effectif, y compris contre le DNS rebinding, est fait à l'appel par
 * NoPrivateNetworkHttpClient ; ce contrôle-ci sert à donner une erreur claire tôt).
 */
final class UrlGuard
{
    /** @var \Closure(string): list<string> */
    private \Closure $resolve;

    /** @param (\Closure(string): list<string>)|null $resolve résolution DNS (injectable en test) */
    public function __construct(?\Closure $resolve = null)
    {
        $this->resolve = $resolve ?? static function (string $host): array {
            $ips = [];
            foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
                $ips[] = $record['ip'] ?? $record['ipv6'] ?? null;
            }

            return array_values(array_filter($ips, 'is_string'));
        };
    }

    /** Message d'erreur, ou null si l'URL est acceptable. */
    public function check(string $url, bool $allowPrivate): ?string
    {
        if (\strlen($url) > 512) {
            return 'URL trop longue (512 caractères max).';
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return 'URL invalide (ex. https://mon-serveur.example/v1).';
        }
        if (!\in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return 'Seuls http et https sont acceptés.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Pas d’identifiants dans l’URL : utilise le champ clé API.';
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            return 'Pas de paramètres ni de fragment dans l’URL de base.';
        }
        if ($allowPrivate) {
            return null;
        }
        $host = trim($parts['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolve)($host);
        if ($ips === []) {
            return sprintf('Impossible de résoudre « %s ».', $host);
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return sprintf('« %s » pointe vers une adresse privée ou réservée (%s) : refusé sur ce serveur.', $host, $ip);
            }
        }

        return null;
    }
}
