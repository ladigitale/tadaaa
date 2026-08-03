<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GoogleCalendarConnection;
use App\Entity\User;
use App\Repository\GoogleCalendarConnectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GoogleOAuthClient
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';
    private const SCOPES = 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.readonly https://www.googleapis.com/auth/userinfo.email';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SecretBox $secrets,
        private readonly GoogleCalendarConnectionRepository $connections,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%env(string:GOOGLE_CLIENT_ID)%')]
        private readonly string $clientId,
        #[Autowire('%env(string:GOOGLE_CLIENT_SECRET)%')]
        private readonly string $clientSecret,
        #[Autowire('%env(string:APP_PUBLIC_URL)%')]
        private readonly string $appPublicUrl,
        #[Autowire('%env(string:DEFAULT_URI)%')]
        private readonly string $apiPublicUrl,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    public function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new ServiceUnavailableHttpException(null, 'Google Calendar OAuth is not configured (GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET).');
        }
    }

    public function callbackRedirectUri(): string
    {
        return rtrim($this->apiPublicUrl !== '' ? $this->apiPublicUrl : 'https://localhost:8443', '/').'/api/google-calendar/callback';
    }

    /**
     * @return array{authorizeUrl: string, state: string}
     */
    public function buildAuthorizeUrl(User $user): array
    {
        $this->assertConfigured();
        $state = $this->signState($user->getId()->toRfc4122());
        $query = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->callbackRedirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);

        return [
            'authorizeUrl' => self::AUTH_URL.'?'.$query,
            'state' => $state,
        ];
    }

    public function handleCallback(string $code, string $state): GoogleCalendarConnection
    {
        $this->assertConfigured();
        $userId = $this->verifyState($state);
        try {
            $user = $this->entityManager->find(User::class, Uuid::fromString($userId));
        } catch (\InvalidArgumentException) {
            $user = null;
        }
        if ($user === null) {
            throw new BadRequestHttpException('Invalid OAuth state (user).');
        }

        $tokens = $this->exchangeCode($code);
        $email = $this->fetchEmail($tokens['access_token']);

        $connection = $this->connections->findOneByUser($user);
        if ($connection === null) {
            $connection = new GoogleCalendarConnection($user);
            $this->entityManager->persist($connection);
        }

        $connection->setGoogleAccountEmail($email);
        if (isset($tokens['refresh_token']) && is_string($tokens['refresh_token']) && $tokens['refresh_token'] !== '') {
            $connection->setRefreshTokenEnc($this->secrets->seal($tokens['refresh_token']));
        } elseif ($connection->getRefreshTokenEnc() === '') {
            throw new BadRequestHttpException('Google did not return a refresh token. Revoke Tadaaa access in Google Account and reconnect.');
        }
        $connection->setAccessTokenEnc($this->secrets->seal($tokens['access_token']));
        $expiresIn = isset($tokens['expires_in']) ? (int) $tokens['expires_in'] : 3600;
        $connection->setAccessTokenExpiresAt(new \DateTimeImmutable('+'.$expiresIn.' seconds'));
        $connection->setStatus(GoogleCalendarConnection::STATUS_ACTIVE);
        $this->entityManager->flush();

        return $connection;
    }

    public function frontendRedirect(string $status, ?string $error = null): string
    {
        $base = rtrim($this->appPublicUrl !== '' ? $this->appPublicUrl : 'http://localhost:5173', '/');
        $query = ['google' => $status];
        if ($error !== null && $error !== '') {
            $query['error'] = $error;
        }

        return $base.'/connectivity/calendar?'.http_build_query($query);
    }

    public function getValidAccessToken(GoogleCalendarConnection $connection): string
    {
        $expires = $connection->getAccessTokenExpiresAt();
        $enc = $connection->getAccessTokenEnc();
        if ($enc !== null && $expires !== null && $expires > new \DateTimeImmutable('+60 seconds')) {
            return $this->secrets->open($enc);
        }

        return $this->refreshAccessToken($connection);
    }

    public function refreshAccessToken(GoogleCalendarConnection $connection): string
    {
        $this->assertConfigured();
        try {
            $refresh = $this->secrets->open($connection->getRefreshTokenEnc());
        } catch (\Throwable) {
            $connection->setStatus(GoogleCalendarConnection::STATUS_NEEDS_REAUTH);
            $this->entityManager->flush();
            throw new BadRequestHttpException('Google connection needs re-authorization.');
        }

        try {
            $response = $this->httpClient->request('POST', self::TOKEN_URL, [
                'body' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'refresh_token' => $refresh,
                    'grant_type' => 'refresh_token',
                ],
                'timeout' => 15,
            ]);
            /** @var array{access_token?: string, expires_in?: int|string} $data */
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $connection->setStatus(GoogleCalendarConnection::STATUS_NEEDS_REAUTH);
            $this->entityManager->flush();
            throw new BadRequestHttpException('Failed to refresh Google token: '.$e->getMessage());
        }

        if (!isset($data['access_token']) || !is_string($data['access_token'])) {
            $connection->setStatus(GoogleCalendarConnection::STATUS_NEEDS_REAUTH);
            $this->entityManager->flush();
            throw new BadRequestHttpException('Google token refresh failed.');
        }

        $connection->setAccessTokenEnc($this->secrets->seal($data['access_token']));
        $expiresIn = isset($data['expires_in']) ? (int) $data['expires_in'] : 3600;
        $connection->setAccessTokenExpiresAt(new \DateTimeImmutable('+'.$expiresIn.' seconds'));
        $connection->setStatus(GoogleCalendarConnection::STATUS_ACTIVE);
        $this->entityManager->flush();

        return $data['access_token'];
    }

    public function revoke(GoogleCalendarConnection $connection): void
    {
        try {
            $token = $this->secrets->open($connection->getRefreshTokenEnc());
            $this->httpClient->request('POST', self::REVOKE_URL, [
                'body' => ['token' => $token],
                'timeout' => 10,
            ]);
        } catch (\Throwable) {
            // best-effort revoke
        }
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_in?: int|string}
     */
    private function exchangeCode(string $code): array
    {
        $response = $this->httpClient->request('POST', self::TOKEN_URL, [
            'body' => [
                'code' => $code,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => $this->callbackRedirectUri(),
                'grant_type' => 'authorization_code',
            ],
            'timeout' => 15,
        ]);
        /** @var array{access_token?: string, refresh_token?: string, expires_in?: int|string, error?: string} $data */
        $data = $response->toArray(false);
        if (!isset($data['access_token']) || !is_string($data['access_token'])) {
            $msg = isset($data['error']) && is_string($data['error']) ? $data['error'] : 'token exchange failed';
            throw new BadRequestHttpException('Google OAuth: '.$msg);
        }

        return $data;
    }

    private function fetchEmail(string $accessToken): string
    {
        $response = $this->httpClient->request('GET', self::USERINFO_URL, [
            'headers' => ['Authorization' => 'Bearer '.$accessToken],
            'timeout' => 10,
        ]);
        /** @var array{email?: string} $data */
        $data = $response->toArray(false);
        $email = isset($data['email']) && is_string($data['email']) ? $data['email'] : '';
        if ($email === '') {
            throw new BadRequestHttpException('Could not read Google account email.');
        }

        return $email;
    }

    private function signState(string $userId): string
    {
        $payload = [
            'uid' => $userId,
            'exp' => time() + 600,
            'nonce' => bin2hex(random_bytes(8)),
        ];
        $body = rtrim(strtr(base64_encode(json_encode($payload, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $body, $this->clientSecret.$this->clientId, true);
        $sigB64 = rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');

        return $body.'.'.$sigB64;
    }

    private function verifyState(string $state): string
    {
        $parts = explode('.', $state, 2);
        if (\count($parts) !== 2) {
            throw new BadRequestHttpException('Invalid OAuth state.');
        }
        [$body, $sigB64] = $parts;
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $this->clientSecret.$this->clientId, true)), '+/', '-_'), '=');
        if (!hash_equals($expected, $sigB64)) {
            throw new BadRequestHttpException('Invalid OAuth state signature.');
        }
        $json = base64_decode(strtr($body, '-_', '+/'), true);
        if ($json === false) {
            throw new BadRequestHttpException('Invalid OAuth state payload.');
        }
        /** @var array{uid?: string, exp?: int} $payload */
        $payload = json_decode($json, true);
        if (!is_array($payload) || !isset($payload['uid'], $payload['exp']) || !is_string($payload['uid'])) {
            throw new BadRequestHttpException('Invalid OAuth state payload.');
        }
        if ((int) $payload['exp'] < time()) {
            throw new BadRequestHttpException('OAuth state expired.');
        }

        return $payload['uid'];
    }
}
