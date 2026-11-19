<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * API host: errors render as JSON problem details unless the client explicitly asks for HTML (browser).
 * Runs after the security ExceptionListener (1) and before the ErrorListener (-128).
 */
final class JsonErrorFormatSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', -64]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (in_array('text/html', $request->getAcceptableContentTypes(), true)) {
            return;
        }

        $request->setRequestFormat('json');
    }
}
