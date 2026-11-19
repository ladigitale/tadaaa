<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\JsonErrorFormatSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class JsonErrorFormatSubscriberTest extends TestCase
{
    public function testAgentWithoutHtmlAcceptGetsJson(): void
    {
        $request = Request::create('/mcp', 'POST', server: ['HTTP_ACCEPT' => '*/*']);
        $this->dispatch($request);

        self::assertSame('json', $request->getPreferredFormat());
    }

    public function testBrowserKeepsHtml(): void
    {
        $request = Request::create('/oauth/authorize', server: ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml,*/*;q=0.8']);
        $this->dispatch($request);

        self::assertSame('html', $request->getPreferredFormat());
    }

    private function dispatch(Request $request): void
    {
        $event = new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException(),
        );
        (new JsonErrorFormatSubscriber())->onKernelException($event);
    }
}
