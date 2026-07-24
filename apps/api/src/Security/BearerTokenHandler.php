<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\AccessTokenService;
use App\Service\OAuthServerService;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTDecodeFailureException;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Accepte un JWT Lexik, un PAT `tada_…`, ou un access token OAuth `tdoa_…`.
 */
final class BearerTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly AccessTokenService $accessTokens,
        private readonly OAuthServerService $oauth,
    ) {
    }

    public function getUserBadgeFrom(string $accessToken): UserBadge
    {
        if (str_starts_with($accessToken, 'tada_')) {
            $user = $this->accessTokens->authenticate($accessToken);
            if ($user === null) {
                throw new BadCredentialsException('Token d’accès invalide ou révoqué.');
            }

            return new UserBadge($user->getUserIdentifier());
        }

        if (str_starts_with($accessToken, OAuthServerService::ACCESS_PREFIX)) {
            $user = $this->oauth->authenticateAccessToken($accessToken);
            if ($user === null) {
                throw new BadCredentialsException('Token OAuth invalide ou expiré.');
            }

            return new UserBadge($user->getUserIdentifier());
        }

        try {
            $payload = $this->jwtManager->parse($accessToken);
        } catch (JWTDecodeFailureException $exception) {
            throw new BadCredentialsException('JWT invalide.', 0, $exception);
        }

        $username = $payload['username'] ?? $payload['email'] ?? null;
        if (!is_string($username) || $username === '') {
            throw new BadCredentialsException('JWT sans identifiant utilisateur.');
        }

        return new UserBadge($username);
    }
}
