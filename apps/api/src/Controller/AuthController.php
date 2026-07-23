<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\RegisterInput;
use App\Entity\Dataset;
use App\Entity\User;
use App\Entity\UserStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\RequestStack;

#[Route('/api/auth')]
final class AuthController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly RequestStack $requestStack,
        #[Autowire(service: 'limiter.register')]
        private readonly RateLimiterFactoryInterface $registerLimiter,
        #[Autowire('%env(bool:REGISTRATION_AUTO_APPROVE)%')]
        private readonly bool $registrationAutoApprove,
    ) {
    }

    #[Route('/register', name: 'api_auth_register', methods: ['POST'])]
    public function register(
        #[MapRequestPayload] RegisterInput $input,
    ): JsonResponse {
        $request = $this->requestStack->getCurrentRequest();
        $limiter = $this->registerLimiter->create($request?->getClientIp() ?? 'anonymous');
        if (!$limiter->consume(1)->isAccepted()) {
            return $this->json(
                ['error' => 'Trop de tentatives — réessayez plus tard.'],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        $email = strtolower(trim($input->email));
        if ($this->users->findOneBy(['email' => $email]) !== null) {
            return $this->json(['error' => 'Cet email est déjà utilisé.'], Response::HTTP_CONFLICT);
        }

        $status = $this->registrationAutoApprove ? UserStatus::Active : UserStatus::Pending;
        $user = new User($email, $status);
        $user->setPassword($this->passwordHasher->hashPassword($user, $input->password));

        $defaultDataset = new Dataset('Mon jeu');
        $user->addDataset($defaultDataset);
        $user->setActiveDataset($defaultDataset);

        $this->entityManager->persist($defaultDataset);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        if ($status === UserStatus::Pending) {
            return $this->json([
                'status' => UserStatus::Pending->value,
                'message' => 'Demande enregistrée — un administrateur doit valider votre compte.',
            ], Response::HTTP_CREATED);
        }

        return $this->json([
            'status' => UserStatus::Active->value,
            'token' => $this->jwtManager->create($user),
            'user' => $this->serializeUser($user),
        ], Response::HTTP_CREATED);
    }

    #[Route('/me', name: 'api_auth_me', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json(['user' => $this->serializeUser($user)]);
    }

    /**
     * @return array{
     *   id: string,
     *   email: string,
     *   createdAt: string,
     *   activeDatasetId: ?string,
     *   status: string,
     *   roles: list<string>
     * }
     */
    private function serializeUser(User $user): array
    {
        return [
            'id' => (string) $user->getId(),
            'email' => $user->getEmail(),
            'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'activeDatasetId' => $user->getActiveDataset()?->getId()->toRfc4122(),
            'status' => $user->getStatus()->value,
            'roles' => $user->getRoles(),
        ];
    }
}
