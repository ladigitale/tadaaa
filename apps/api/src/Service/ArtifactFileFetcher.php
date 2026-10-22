<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Récupère le contenu d'un fichier d'artefact (`files`) depuis une URL https d'un hôte autorisé
 * (GitHub raw par défaut), pour éviter de recopier à la main un gros binaire (glb) en base64 :
 * une copie manuelle de plusieurs dizaines de Ko perd ou altère des caractères.
 *
 * Garde-fous (SSRF) : https seulement, hôtes en liste blanche, pas d'IP, pas d'identifiants, aucune
 * redirection suivie, taille bornée, empreinte sha256 optionnelle vérifiée sur les octets reçus.
 */
final class ArtifactFileFetcher
{
    /** @var list<string> */
    public const DEFAULT_HOSTS = ['raw.githubusercontent.com', 'gist.githubusercontent.com'];
    private const MAX_URL_LENGTH = 2048;

    /** @var list<string> */
    private readonly array $hosts;

    public function __construct(
        private readonly HttpClientInterface $http,
        string $allowedHosts = '',
    ) {
        $custom = array_values(array_filter(array_map(static fn (string $h): string => strtolower(trim($h)), explode(',', $allowedHosts))));
        $this->hosts = $custom !== [] ? $custom : self::DEFAULT_HOSTS;
    }

    /**
     * @param string $encoding "base64" (octets reçus encodés en base64 sur une seule ligne) ou "text"
     *
     * @return string contenu à stocker dans files[nom]
     */
    public function fetch(string $url, string $encoding = 'text', ?string $sha256 = null): string
    {
        $this->assertUrl($url);
        if (!\in_array($encoding, ['text', 'base64'], true)) {
            throw new BadRequestHttpException('fetch.encoding : "text" ou "base64".');
        }
        try {
            $response = $this->http->request('GET', $url, ['max_redirects' => 0, 'timeout' => 10, 'max_duration' => 20]);
            if ($response->getStatusCode() !== 200) {
                throw new BadRequestHttpException(sprintf('fetch %s : HTTP %d.', $url, $response->getStatusCode()));
            }
            $maxRaw = $encoding === 'base64' ? intdiv(ArtifactFiles::MAX_FILE_BYTES * 3, 4) : ArtifactFiles::MAX_FILE_BYTES;
            $bytes = '';
            foreach ($this->http->stream($response) as $chunk) {
                $bytes .= $chunk->getContent();
                if (\strlen($bytes) > $maxRaw) {
                    $response->cancel();
                    throw new BadRequestHttpException(sprintf('fetch %s : fichier trop gros (%d Ko max avant encodage).', $url, intdiv($maxRaw, 1024)));
                }
            }
        } catch (ExceptionInterface $e) {
            throw new BadRequestHttpException(sprintf('fetch %s : %s', $url, $e->getMessage()));
        }
        if ($sha256 !== null && $sha256 !== '' && !hash_equals(strtolower($sha256), hash('sha256', $bytes))) {
            throw new BadRequestHttpException(sprintf('fetch %s : empreinte sha256 différente (reçu %s).', $url, hash('sha256', $bytes)));
        }

        return $encoding === 'base64' ? base64_encode($bytes) : $bytes;
    }

    private function assertUrl(string $url): void
    {
        $url = trim($url);
        if ($url === '' || \strlen($url) > self::MAX_URL_LENGTH) {
            throw new BadRequestHttpException('fetch.url invalide.');
        }
        $parts = parse_url($url);
        $host = \is_array($parts) && \is_string($parts['host'] ?? null) ? strtolower($parts['host']) : '';
        if (!\is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new BadRequestHttpException('fetch.url doit être une URL https sans identifiants ni port.');
        }
        if (filter_var(trim($host, '[]'), \FILTER_VALIDATE_IP) !== false || !\in_array($host, $this->hosts, true)) {
            throw new BadRequestHttpException(sprintf('fetch.url : hôte non autorisé (%s).', implode(', ', $this->hosts)));
        }
    }
}
