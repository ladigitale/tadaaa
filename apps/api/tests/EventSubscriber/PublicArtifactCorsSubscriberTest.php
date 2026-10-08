<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\PublicArtifactCorsSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class PublicArtifactCorsSubscriberTest extends TestCase
{
    private function dispatch(Request $request, ?Response $response = null): Response
    {
        $response ??= new Response('{}');
        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
        (new PublicArtifactCorsSubscriber())->onResponse($event);

        return $event->getResponse();
    }

    private function request(string $path, string $method = 'GET', ?string $origin = 'https://blog.example'): Request
    {
        $request = Request::create($path, $method);
        if ($origin !== null) {
            $request->headers->set('Origin', $origin);
        }

        return $request;
    }

    public function testPublicReadFromAnyOrigin(): void
    {
        $response = $this->dispatch($this->request('/api/public/artifacts/mon-jeu'));
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertNull($response->headers->get('Access-Control-Allow-Credentials'));
    }

    public function testPreflightAllowsIntakeWithoutAuthorization(): void
    {
        $preflight = new Response('', Response::HTTP_OK, ['Access-Control-Allow-Headers' => 'Content-Type, Authorization']);
        $response = $this->dispatch($this->request('/api/public/artifacts/mon-jeu/collections/scores/records', 'OPTIONS'), $preflight);
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('GET, POST, OPTIONS', $response->headers->get('Access-Control-Allow-Methods'));
        self::assertStringNotContainsString('Authorization', (string) $response->headers->get('Access-Control-Allow-Headers'));
    }

    public function testKeepsNelmioHeadersForConfiguredOrigins(): void
    {
        $existing = new Response('{}', 200, ['Access-Control-Allow-Origin' => 'https://artifacts.tadaaa.space']);
        $response = $this->dispatch($this->request('/api/public/artifacts/mon-jeu', 'GET', 'https://artifacts.tadaaa.space'), $existing);
        self::assertSame('https://artifacts.tadaaa.space', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function testIgnoresOtherPathsAndSameOriginRequests(): void
    {
        self::assertNull($this->dispatch($this->request('/api/artifacts/mon-jeu'))->headers->get('Access-Control-Allow-Origin'));
        self::assertNull($this->dispatch($this->request('/api/public/embeds/emb_x'))->headers->get('Access-Control-Allow-Origin'));
        self::assertNull($this->dispatch($this->request('/api/public/artifacts/mon-jeu', 'GET', null))->headers->get('Access-Control-Allow-Origin'));
    }
}
