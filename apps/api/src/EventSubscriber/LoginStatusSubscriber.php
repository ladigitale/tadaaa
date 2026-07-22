<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
/**
 * Bloque le login JSON si le compte n’est pas actif (avant émission du JWT).
 */
final class LoginStatusSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 8]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->getPathInfo() !== '/api/auth/login' || !$request->isMethod('POST')) {
            return;
        }

        /** @var array{email?: mixed}|null $payload */
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || !is_string($payload['email'] ?? null)) {
            return;
        }

        $email = strtolower(trim($payload['email']));
        $user = $this->users->findOneBy(['email' => $email]);
        if ($user instanceof User && !$user->getStatus()->isUsable()) {
            $event->setResponse(new JsonResponse(
                ['error' => $user->getStatus()->denialMessage()],
                Response::HTTP_FORBIDDEN,
            ));
        }
    }
}
