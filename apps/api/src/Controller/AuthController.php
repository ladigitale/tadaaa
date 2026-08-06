<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\DeleteAccountInput;
use App\Dto\RegisterInput;
use App\Dto\ResendVerificationInput;
use App\Dto\VerifyEmailInput;
use App\Entity\Dataset;
use App\Entity\User;
use App\Entity\UserStatus;
use App\Repository\UserRepository;
use App\Service\AccountMailer;
use App\Service\BandwidthQuota;
use App\Service\StorageQuota;
use App\Service\UserAccountDeletion;
use App\Service\UserDataExport;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/auth')]
final class AuthController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly RequestStack $requestStack,
        private readonly AccountMailer $accountMailer,
        private readonly StorageQuota $storageQuota,
        private readonly BandwidthQuota $bandwidthQuota,
        private readonly UserDataExport $userDataExport,
        private readonly UserAccountDeletion $userAccountDeletion,
        #[Autowire(service: 'limiter.register')]
        private readonly RateLimiterFactoryInterface $registerLimiter,
        #[Autowire(service: 'limiter.account_gdpr')]
        private readonly RateLimiterFactoryInterface $accountGdprLimiter,
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

        // Honeypot: pretend success, do not create an account.
        if (trim($input->website) !== '') {
            return $this->json([
                'status' => UserStatus::Pending->value,
                'message' => 'Vérifiez votre email pour activer le compte.',
            ], Response::HTTP_CREATED);
        }

        $email = strtolower(trim($input->email));
        if ($this->users->findOneBy(['email' => $email]) !== null) {
            return $this->json(['error' => 'Cet email est déjà utilisé.'], Response::HTTP_CONFLICT);
        }

        if ($this->registrationAutoApprove) {
            $user = new User($email, UserStatus::Active);
            $user->setPassword($this->passwordHasher->hashPassword($user, $input->password));
            $user->setEmailVerifiedAt(new \DateTimeImmutable());
            $user->setTermsAcceptedAt(new \DateTimeImmutable());
            $defaultDataset = new Dataset('Mon jeu');
            $user->addDataset($defaultDataset);
            $user->setActiveDataset($defaultDataset);
            $this->entityManager->persist($defaultDataset);
            $this->entityManager->persist($user);
            $this->entityManager->flush();

            return $this->json([
                'status' => UserStatus::Active->value,
                'token' => $this->jwtManager->create($user),
                'user' => $this->serializeUser($user),
            ], Response::HTTP_CREATED);
        }

        $user = new User($email, UserStatus::Pending);
        $user->setPassword($this->passwordHasher->hashPassword($user, $input->password));
        $user->setTermsAcceptedAt(new \DateTimeImmutable());
        $rawToken = $this->issueEmailVerifyToken($user);

        $defaultDataset = new Dataset('Mon jeu');
        $user->addDataset($defaultDataset);
        $user->setActiveDataset($defaultDataset);

        $this->entityManager->persist($defaultDataset);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        try {
            $this->accountMailer->sendEmailVerification($user, $rawToken);
        } catch (\Throwable) {
            // Account exists; user can resend. Avoid leaking mailer failures as 500 with secrets.
            return $this->json([
                'status' => UserStatus::Pending->value,
                'message' => 'Compte créé, mais l’email n’a pas pu être envoyé. Réessayez via « renvoyer le lien ».',
            ], Response::HTTP_CREATED);
        }

        return $this->json([
            'status' => UserStatus::Pending->value,
            'message' => 'Vérifiez votre email pour activer le compte (lien envoyé).',
        ], Response::HTTP_CREATED);
    }

    #[Route('/verify-email', name: 'api_auth_verify_email', methods: ['POST'])]
    public function verifyEmail(
        #[MapRequestPayload] VerifyEmailInput $input,
    ): JsonResponse {
        $hash = hash('sha256', $input->token);
        $user = $this->users->findOneBy(['emailVerifyToken' => $hash]);
        if ($user === null) {
            return $this->json(['error' => 'Lien invalide ou déjà utilisé.'], Response::HTTP_BAD_REQUEST);
        }

        $expires = $user->getEmailVerifyExpiresAt();
        if ($expires === null || $expires < new \DateTimeImmutable()) {
            return $this->json(['error' => 'Lien expiré — demandez un nouvel email.'], Response::HTTP_BAD_REQUEST);
        }

        $user->setStatus(UserStatus::Active);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->clearEmailVerificationChallenge();
        $this->entityManager->flush();

        return $this->json([
            'status' => UserStatus::Active->value,
            'token' => $this->jwtManager->create($user),
            'user' => $this->serializeUser($user),
            'message' => 'Email confirmé — compte activé.',
        ]);
    }

    #[Route('/resend-verification', name: 'api_auth_resend_verification', methods: ['POST'])]
    public function resendVerification(
        #[MapRequestPayload] ResendVerificationInput $input,
    ): JsonResponse {
        $request = $this->requestStack->getCurrentRequest();
        $limiter = $this->registerLimiter->create('resend-'.($request?->getClientIp() ?? 'anonymous'));
        if (!$limiter->consume(1)->isAccepted()) {
            return $this->json(
                ['error' => 'Trop de tentatives — réessayez plus tard.'],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        $email = strtolower(trim($input->email));
        $user = $this->users->findOneBy(['email' => $email]);
        // Always same response to avoid account enumeration.
        $ok = [
            'message' => 'Si un compte en attente existe pour cet email, un nouveau lien a été envoyé.',
        ];

        if ($user === null || $user->getStatus() !== UserStatus::Pending) {
            return $this->json($ok);
        }

        $rawToken = $this->issueEmailVerifyToken($user);
        $this->entityManager->flush();
        try {
            $this->accountMailer->sendEmailVerification($user, $rawToken);
        } catch (\Throwable) {
            // swallow
        }

        return $this->json($ok);
    }

    #[Route('/me/export', name: 'api_auth_me_export', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function exportMe(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $limiter = $this->accountGdprLimiter->create('export-'.$user->getId()->toRfc4122());
        if (!$limiter->consume(1)->isAccepted()) {
            return $this->json(
                ['error' => 'Trop de tentatives — réessayez plus tard.'],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        return $this->json($this->userDataExport->export($user));
    }

    #[Route('/me', name: 'api_auth_me_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    public function deleteMe(
        #[MapRequestPayload] DeleteAccountInput $input,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();
        $limiter = $this->accountGdprLimiter->create('delete-'.$user->getId()->toRfc4122());
        if (!$limiter->consume(1)->isAccepted()) {
            return $this->json(
                ['error' => 'Trop de tentatives — réessayez plus tard.'],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        $confirm = strtolower(trim($input->confirmEmail));
        if ($confirm !== $user->getEmail()) {
            return $this->json(
                ['error' => 'L’email de confirmation ne correspond pas.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $email = $user->getEmail();
        $this->userAccountDeletion->delete($user);

        return $this->json(['deleted' => true, 'email' => $email]);
    }

    #[Route('/me', name: 'api_auth_me', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json([
            'user' => $this->serializeUser($user),
            'quotas' => $this->serializeQuotas($user),
        ]);
    }

    private function issueEmailVerifyToken(User $user): string
    {
        $raw = bin2hex(random_bytes(32));
        $user->setEmailVerifyToken(hash('sha256', $raw));
        $user->setEmailVerifyExpiresAt(new \DateTimeImmutable('+48 hours'));

        return $raw;
    }

    /**
     * @return array{
     *   id: string,
     *   email: string,
     *   createdAt: string,
     *   activeDatasetId: ?string,
     *   status: string,
     *   roles: list<string>,
     *   linkDetectors: list<array{id: string, name: string, pattern: string, urlTemplate: string}>,
     *   emailVerifiedAt: ?string
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
            'linkDetectors' => $user->getLinkDetectors(),
            'emailVerifiedAt' => $user->getEmailVerifiedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array{storage: array<string, mixed>, bandwidth: array<string, mixed>}
     */
    private function serializeQuotas(User $user): array
    {
        return [
            'storage' => $this->storageQuota->report($user),
            'bandwidth' => $this->bandwidthQuota->report($user),
        ];
    }
}
