<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\OAuthIssuer;
use App\Service\OAuthServerException;
use App\Service\OAuthServerService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/oauth')]
final class OAuthController extends AbstractController
{
    public function __construct(
        private readonly OAuthServerService $oauth,
        private readonly OAuthIssuer $issuer,
    ) {
    }

    #[Route('/register', name: 'oauth_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        try {
            /** @var array<string, mixed> $body */
            $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->oauthError('invalid_client_metadata', 'Invalid JSON body.', 400);
        }

        $redirectUris = $body['redirect_uris'] ?? null;
        if (!is_array($redirectUris)) {
            return $this->oauthError('invalid_client_metadata', 'redirect_uris is required.', 400);
        }

        $uris = [];
        foreach ($redirectUris as $uri) {
            if (is_string($uri)) {
                $uris[] = $uri;
            }
        }

        $clientName = isset($body['client_name']) && is_string($body['client_name'])
            ? $body['client_name']
            : null;
        $authMethod = isset($body['token_endpoint_auth_method']) && is_string($body['token_endpoint_auth_method'])
            ? $body['token_endpoint_auth_method']
            : 'none';

        try {
            $client = $this->oauth->registerClient($uris, $clientName, $authMethod);
        } catch (\InvalidArgumentException $e) {
            return $this->oauthError('invalid_client_metadata', $e->getMessage(), 400);
        }

        return $this->json([
            'client_id' => $client->getClientId(),
            'client_id_issued_at' => $client->getCreatedAt()->getTimestamp(),
            'client_name' => $client->getClientName(),
            'redirect_uris' => $client->getRedirectUris(),
            'grant_types' => $client->getGrantTypes(),
            'response_types' => $client->getResponseTypes(),
            'token_endpoint_auth_method' => $client->getTokenEndpointAuthMethod(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/authorize', name: 'oauth_authorize', methods: ['GET', 'POST'])]
    public function authorize(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->authorizeSubmit($request);
        }

        $clientId = (string) $request->query->get('client_id', '');
        $redirectUri = (string) $request->query->get('redirect_uri', '');
        $responseType = (string) $request->query->get('response_type', '');
        $codeChallenge = (string) $request->query->get('code_challenge', '');
        $codeChallengeMethod = (string) $request->query->get('code_challenge_method', 'S256');
        $scope = (string) $request->query->get('scope', 'mcp');
        $state = (string) $request->query->get('state', '');
        $resource = (string) $request->query->get('resource', '');

        $error = $this->validateAuthorizeRequest(
            $clientId,
            $redirectUri,
            $responseType,
            $codeChallenge,
            $codeChallengeMethod,
            $resource,
        );
        if ($error !== null) {
            return $this->render('oauth/error.html.twig', [
                'error' => $error['error'],
                'description' => $error['description'],
            ], new Response('', Response::HTTP_BAD_REQUEST));
        }

        $client = $this->oauth->findClient($clientId);
        $formToken = $this->oauth->signAuthorizeForm([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'scope' => $scope,
            'state' => $state,
            'resource' => $resource,
            'exp' => (string) (time() + 900),
        ]);

        return $this->render('oauth/authorize.html.twig', [
            'clientName' => $client?->getClientName() ?: 'Claude',
            'redirectHost' => $this->redirectHostLabel($redirectUri),
            'mcpResource' => $this->issuer->mcpResource(),
            'formToken' => $formToken,
            'error' => null,
        ]);
    }

    #[Route('/token', name: 'oauth_token', methods: ['POST'])]
    public function token(Request $request): JsonResponse
    {
        $grantType = (string) $request->request->get('grant_type', '');
        $clientId = (string) $request->request->get('client_id', '');

        try {
            if ($grantType === 'authorization_code') {
                $result = $this->oauth->exchangeAuthorizationCode(
                    (string) $request->request->get('code', ''),
                    $clientId,
                    (string) $request->request->get('redirect_uri', ''),
                    (string) $request->request->get('code_verifier', ''),
                );
            } elseif ($grantType === 'refresh_token') {
                $result = $this->oauth->refreshAccessToken(
                    (string) $request->request->get('refresh_token', ''),
                    $clientId,
                );
            } else {
                return $this->oauthError('unsupported_grant_type', 'Only authorization_code and refresh_token are supported.');
            }
        } catch (OAuthServerException $e) {
            return $this->oauthError($e->error, $e->getMessage(), $e->statusCode);
        }

        return $this->json($result);
    }

    #[Route('/revoke', name: 'oauth_revoke', methods: ['POST'])]
    public function revoke(Request $request): Response
    {
        $token = (string) $request->request->get('token', '');
        $clientId = $request->request->get('client_id');
        $this->oauth->revokeToken(
            $token,
            is_string($clientId) && $clientId !== '' ? $clientId : null,
        );

        return new Response('', Response::HTTP_OK);
    }

    private function authorizeSubmit(Request $request): Response
    {
        $formToken = (string) $request->request->get('form_token', '');
        $params = $this->oauth->verifyAuthorizeForm($formToken);
        if ($params === null) {
            return $this->render('oauth/error.html.twig', [
                'error' => 'invalid_request',
                'description' => 'Form expired or invalid — restart the connection from Claude.',
            ], new Response('', Response::HTTP_BAD_REQUEST));
        }

        $clientId = $params['client_id'] ?? '';
        $redirectUri = $params['redirect_uri'] ?? '';
        $codeChallenge = $params['code_challenge'] ?? '';
        $codeChallengeMethod = $params['code_challenge_method'] ?? 'S256';
        $scope = $params['scope'] ?? 'mcp';
        $state = $params['state'] ?? '';
        $resource = $params['resource'] ?? '';

        $validation = $this->validateAuthorizeRequest(
            $clientId,
            $redirectUri,
            'code',
            $codeChallenge,
            $codeChallengeMethod,
            $resource,
        );
        if ($validation !== null) {
            return $this->render('oauth/error.html.twig', [
                'error' => $validation['error'],
                'description' => $validation['description'],
            ], new Response('', Response::HTTP_BAD_REQUEST));
        }

        $email = (string) $request->request->get('email', '');
        $password = (string) $request->request->get('password', '');
        $user = $this->oauth->authenticateUser($email, $password);
        if ($user === null) {
            $client = $this->oauth->findClient($clientId);

            return $this->render('oauth/authorize.html.twig', [
                'clientName' => $client?->getClientName() ?: 'Claude',
                'redirectHost' => $this->redirectHostLabel($redirectUri),
                'mcpResource' => $this->issuer->mcpResource(),
                'formToken' => $formToken,
                'error' => 'Invalid credentials or inactive account.',
            ], new Response('', Response::HTTP_UNAUTHORIZED));
        }

        $client = $this->oauth->findClient($clientId);
        if ($client === null) {
            return $this->render('oauth/error.html.twig', [
                'error' => 'invalid_client',
                'description' => 'Unknown client.',
            ], new Response('', Response::HTTP_BAD_REQUEST));
        }

        $scopes = array_values(array_filter(preg_split('/\s+/', trim($scope)) ?: []));
        $code = $this->oauth->createAuthorizationCode(
            $client,
            $user,
            $redirectUri,
            $codeChallenge,
            $scopes,
            $codeChallengeMethod,
        );

        $query = http_build_query(array_filter([
            'code' => $code,
            'state' => $state !== '' ? $state : null,
            'iss' => $this->issuer->issuer(),
        ], static fn ($v) => $v !== null));

        return new RedirectResponse($redirectUri.(str_contains($redirectUri, '?') ? '&' : '?').$query);
    }

    /**
     * @return array{error: string, description: string}|null
     */
    private function validateAuthorizeRequest(
        string $clientId,
        string $redirectUri,
        string $responseType,
        string $codeChallenge,
        string $codeChallengeMethod,
        string $resource,
    ): ?array {
        if ($clientId === '') {
            return ['error' => 'invalid_request', 'description' => 'client_id is required.'];
        }
        $client = $this->oauth->findClient($clientId);
        if ($client === null) {
            return ['error' => 'invalid_client', 'description' => 'Unknown client_id.'];
        }
        if ($responseType !== 'code') {
            return ['error' => 'unsupported_response_type', 'description' => 'Only response_type=code is supported.'];
        }
        if ($redirectUri === '' || !$this->oauth->redirectUriMatches($client, $redirectUri)) {
            return ['error' => 'invalid_request', 'description' => 'Invalid redirect_uri.'];
        }
        if ($codeChallenge === '' || $codeChallengeMethod !== 'S256') {
            return ['error' => 'invalid_request', 'description' => 'PKCE S256 code_challenge is required.'];
        }
        if ($resource !== '' && $resource !== $this->issuer->mcpResource()) {
            return ['error' => 'invalid_target', 'description' => 'resource must match the MCP URL.'];
        }

        return null;
    }

    private function redirectHostLabel(string $redirectUri): string
    {
        $parts = parse_url($redirectUri);
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';

        return $host !== '' ? $host : $redirectUri;
    }

    private function oauthError(string $error, string $description, int $status = 400): JsonResponse
    {
        return $this->json([
            'error' => $error,
            'error_description' => $description,
        ], $status);
    }
}
