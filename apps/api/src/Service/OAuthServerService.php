<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\OAuthAuthCode;
use App\Entity\OAuthClient;
use App\Entity\OAuthToken;
use App\Entity\User;
use App\Repository\OAuthAuthCodeRepository;
use App\Repository\OAuthClientRepository;
use App\Repository\OAuthTokenRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class OAuthServerService
{
    public const ACCESS_PREFIX = 'tdoa_';
    public const REFRESH_PREFIX = 'tdor_';
    public const ACCESS_TTL_SECONDS = 3600;
    public const REFRESH_TTL_SECONDS = 2_592_000; // 30 days
    public const CODE_TTL_SECONDS = 600;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OAuthClientRepository $clients,
        private readonly OAuthAuthCodeRepository $authCodes,
        private readonly OAuthTokenRepository $tokens,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        #[Autowire('%kernel.secret%')]
        private readonly string $appSecret,
    ) {
    }

    /**
     * @param list<string> $redirectUris
     */
    public function registerClient(
        array $redirectUris,
        ?string $clientName = null,
        string $tokenEndpointAuthMethod = 'none',
    ): OAuthClient {
        $uris = [];
        foreach ($redirectUris as $uri) {
            if (!is_string($uri) || $uri === '') {
                continue;
            }
            if (!$this->isAllowedRedirectUri($uri)) {
                throw new \InvalidArgumentException('redirect_uri not allowed: '.$uri);
            }
            $uris[] = $uri;
        }
        if ($uris === []) {
            throw new \InvalidArgumentException('At least one redirect_uri is required.');
        }

        $clientId = bin2hex(random_bytes(16));
        $client = new OAuthClient(
            $clientId,
            $uris,
            $clientName !== null && $clientName !== '' ? $clientName : null,
            $tokenEndpointAuthMethod !== '' ? $tokenEndpointAuthMethod : 'none',
        );

        $this->entityManager->persist($client);
        $this->entityManager->flush();

        return $client;
    }

    public function findClient(string $clientId): ?OAuthClient
    {
        return $this->clients->findOneByClientId($clientId);
    }

    public function isAllowedRedirectUri(string $uri): bool
    {
        if ($uri === 'https://claude.ai/api/mcp/auth_callback') {
            return true;
        }

        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $path = $parts['path'];

        // Claude Code loopback (RFC 8252) — port-agnostic
        if (
            $scheme === 'http'
            && ($host === '127.0.0.1' || $host === 'localhost')
            && $path === '/callback'
        ) {
            return true;
        }

        return false;
    }

    public function redirectUriMatches(OAuthClient $client, string $redirectUri): bool
    {
        foreach ($client->getRedirectUris() as $registered) {
            if ($this->sameRedirectUri($registered, $redirectUri)) {
                return true;
            }
        }

        return false;
    }

    public function sameRedirectUri(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        $pa = parse_url($a);
        $pb = parse_url($b);
        if ($pa === false || $pb === false) {
            return false;
        }

        $hostA = strtolower((string) ($pa['host'] ?? ''));
        $hostB = strtolower((string) ($pb['host'] ?? ''));
        $schemeA = strtolower((string) ($pa['scheme'] ?? ''));
        $schemeB = strtolower((string) ($pb['scheme'] ?? ''));
        $pathA = (string) ($pa['path'] ?? '');
        $pathB = (string) ($pb['path'] ?? '');

        if ($schemeA !== $schemeB || $hostA !== $hostB || $pathA !== $pathB) {
            return false;
        }

        // Ignore port for loopback
        if ($hostA === '127.0.0.1' || $hostA === 'localhost') {
            return true;
        }

        $portA = $pa['port'] ?? ($schemeA === 'https' ? 443 : 80);
        $portB = $pb['port'] ?? ($schemeB === 'https' ? 443 : 80);

        return $portA === $portB;
    }

    /**
     * @param list<string> $scopes
     */
    public function createAuthorizationCode(
        OAuthClient $client,
        User $user,
        string $redirectUri,
        string $codeChallenge,
        array $scopes,
        string $codeChallengeMethod = 'S256',
    ): string {
        if ($codeChallengeMethod !== 'S256') {
            throw new \InvalidArgumentException('Only S256 PKCE is supported.');
        }
        if ($codeChallenge === '') {
            throw new \InvalidArgumentException('code_challenge is required.');
        }

        $plain = bin2hex(random_bytes(32));
        $code = new OAuthAuthCode(
            hash('sha256', $plain),
            $client,
            $user,
            $redirectUri,
            $codeChallenge,
            $this->normalizeScopes($scopes),
            new \DateTimeImmutable('+'.self::CODE_TTL_SECONDS.' seconds'),
            $codeChallengeMethod,
        );
        $this->entityManager->persist($code);
        $this->entityManager->flush();

        return $plain;
    }

    /**
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, scope: string}
     */
    public function exchangeAuthorizationCode(
        string $code,
        string $clientId,
        string $redirectUri,
        string $codeVerifier,
    ): array {
        $client = $this->findClient($clientId);
        if ($client === null) {
            throw new OAuthServerException('invalid_client', 'Unknown client_id.', 401);
        }
        if (!$this->redirectUriMatches($client, $redirectUri)) {
            throw new OAuthServerException('invalid_grant', 'redirect_uri mismatch.');
        }

        $authCode = $this->authCodes->findActiveByHash(hash('sha256', $code));
        if ($authCode === null) {
            throw new OAuthServerException('invalid_grant', 'Invalid or expired authorization code.');
        }
        if ($authCode->getClient()->getClientId() !== $client->getClientId()) {
            throw new OAuthServerException('invalid_grant', 'Code was not issued to this client.');
        }
        if (!$this->sameRedirectUri($authCode->getRedirectUri(), $redirectUri)) {
            throw new OAuthServerException('invalid_grant', 'redirect_uri mismatch.');
        }
        if (!$this->verifyPkce($codeVerifier, $authCode->getCodeChallenge())) {
            throw new OAuthServerException('invalid_grant', 'PKCE verification failed.');
        }

        $authCode->markUsed();
        $tokens = $this->issueTokenPair($client, $authCode->getUser(), $authCode->getScopes());
        $this->entityManager->flush();

        return $tokens;
    }

    /**
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, scope: string}
     */
    public function refreshAccessToken(string $refreshToken, string $clientId): array
    {
        if (!str_starts_with($refreshToken, self::REFRESH_PREFIX)) {
            throw new OAuthServerException('invalid_grant', 'Invalid refresh token.');
        }

        $client = $this->findClient($clientId);
        if ($client === null) {
            throw new OAuthServerException('invalid_client', 'Unknown client_id.', 401);
        }

        $existing = $this->tokens->findActiveRefreshByHash(hash('sha256', $refreshToken));
        if ($existing === null) {
            throw new OAuthServerException('invalid_grant', 'Invalid or expired refresh token.');
        }
        if ($existing->getClient()->getClientId() !== $client->getClientId()) {
            throw new OAuthServerException('invalid_grant', 'Refresh token client mismatch.');
        }

        $user = $existing->getUser();
        $scopes = $existing->getScopes();
        $existing->revoke();
        $tokens = $this->issueTokenPair($client, $user, $scopes);
        $this->entityManager->flush();

        return $tokens;
    }

    public function revokeToken(string $token, ?string $clientId = null): void
    {
        $hash = hash('sha256', $token);
        $row = null;
        if (str_starts_with($token, self::ACCESS_PREFIX)) {
            $row = $this->tokens->findOneBy(['accessTokenHash' => $hash]);
        } elseif (str_starts_with($token, self::REFRESH_PREFIX)) {
            $row = $this->tokens->findOneBy(['refreshTokenHash' => $hash]);
        }

        if (!$row instanceof OAuthToken || $row->isRevoked()) {
            return;
        }
        if ($clientId !== null && $row->getClient()->getClientId() !== $clientId) {
            return;
        }

        $row->revoke();
        $this->entityManager->flush();
    }

    public function authenticateAccessToken(string $plainToken): ?User
    {
        if (!str_starts_with($plainToken, self::ACCESS_PREFIX)) {
            return null;
        }

        $token = $this->tokens->findActiveAccessByHash(hash('sha256', $plainToken));
        if ($token === null) {
            return null;
        }

        $token->touch();
        $this->entityManager->flush();

        return $token->getUser();
    }

    public function authenticateUser(string $email, string $password): ?User
    {
        $user = $this->users->findOneBy(['email' => strtolower(trim($email))]);
        if (!$user instanceof User) {
            return null;
        }
        if (!$this->passwordHasher->isPasswordValid($user, $password)) {
            return null;
        }
        if (!$user->getStatus()->isUsable()) {
            return null;
        }

        return $user;
    }

    /**
     * HMAC form token so authorize stays session-less.
     *
     * @param array<string, string> $params
     */
    public function signAuthorizeForm(array $params): string
    {
        $payload = json_encode($params, JSON_THROW_ON_ERROR);
        $sig = hash_hmac('sha256', $payload, $this->appSecret);

        return base64_encode($payload).'.'.$sig;
    }

    /**
     * @return array<string, string>|null
     */
    public function verifyAuthorizeForm(string $token): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$encoded, $sig] = $parts;
        $payload = base64_decode($encoded, true);
        if ($payload === false) {
            return null;
        }
        $expected = hash_hmac('sha256', $payload, $this->appSecret);
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $exp = isset($data['exp']) ? (int) $data['exp'] : 0;
        if ($exp < time()) {
            return null;
        }

        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $out[$key] = $value;
            } elseif (is_string($key) && is_int($value)) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $scopes
     *
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, scope: string}
     */
    private function issueTokenPair(OAuthClient $client, User $user, array $scopes): array
    {
        $accessPlain = self::ACCESS_PREFIX.bin2hex(random_bytes(24));
        $refreshPlain = self::REFRESH_PREFIX.bin2hex(random_bytes(24));
        $now = new \DateTimeImmutable();
        $token = new OAuthToken(
            hash('sha256', $accessPlain),
            hash('sha256', $refreshPlain),
            $client,
            $user,
            $this->normalizeScopes($scopes),
            $now->modify('+'.self::ACCESS_TTL_SECONDS.' seconds'),
            $now->modify('+'.self::REFRESH_TTL_SECONDS.' seconds'),
        );
        $this->entityManager->persist($token);

        return [
            'access_token' => $accessPlain,
            'refresh_token' => $refreshPlain,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL_SECONDS,
            'scope' => implode(' ', $this->normalizeScopes($scopes)),
        ];
    }

    private function verifyPkce(string $codeVerifier, string $codeChallenge): bool
    {
        if ($codeVerifier === '' || strlen($codeVerifier) < 43) {
            return false;
        }
        $computed = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        return hash_equals($codeChallenge, $computed);
    }

    /**
     * @param list<string> $scopes
     *
     * @return list<string>
     */
    private function normalizeScopes(array $scopes): array
    {
        $allowed = ['mcp', 'offline_access'];
        $out = [];
        foreach ($scopes as $scope) {
            if (in_array($scope, $allowed, true) && !in_array($scope, $out, true)) {
                $out[] = $scope;
            }
        }
        if (!in_array('mcp', $out, true)) {
            array_unshift($out, 'mcp');
        }

        return $out;
    }
}
