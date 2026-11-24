<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\DeleteAccountInput;
use App\Dto\ForgotPasswordInput;
use App\Dto\RegisterInput;
use App\Dto\ResetPasswordInput;
use App\Dto\ResendVerificationInput;
use App\Dto\VerifyEmailInput;
use App\Entity\Dataset;
use App\Entity\User;
use App\Entity\UserStatus;
use App\Repository\UserRepository;
use App\Service\AccountMailer;
use App\Service\ProdDeploy;
use App\Service\SuperAdminNotifier;
use App\Service\AuthHandoffService;
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
        private readonly AuthHandoffService $authHandoff,
        private readonly SuperAdminNotifier $adminNotifier,
        private readonly ProdDeploy $superAdmin,
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
            $this->adminNotifier->accountCreated($user);

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
        $this->adminNotifier->accountRequested($user);

        try {
            $this->accountMailer->sendEmailVerification($user, $rawToken, $input->returnTo);
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

        $wasActive = $user->getStatus() === UserStatus::Active;
        $user->setStatus(UserStatus::Active);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->clearEmailVerificationChallenge();
        $this->entityManager->flush();
        if (!$wasActive) {
            $this->adminNotifier->accountCreated($user);
        }

        return $this->json([
            'status' => UserStatus::Active->value,
            'token' => $this->jwtManager->create($user),
            'user' => $this->serializeUser($user),
            'message' => 'Email confirmé — compte activé.',
        ]);
    }

    /**
     * Mot de passe oublié : envoie un lien de réinitialisation (60 min, usage unique). Même réponse que le compte
     * existe ou non (pas d'énumération) ; rien pour un compte refusé ou désactivé.
     */
    #[Route('/password/forgot', name: 'api_auth_password_forgot', methods: ['POST'])]
    public function forgotPassword(
        #[MapRequestPayload] ForgotPasswordInput $input,
    ): JsonResponse {
        $request = $this->requestStack->getCurrentRequest();
        if (!$this->registerLimiter->create('forgot-'.($request?->getClientIp() ?? 'anonymous'))->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop de tentatives — réessayez plus tard.'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        $ok = ['message' => 'Si un compte existe pour cette adresse, un lien pour choisir un nouveau mot de passe vient d’être envoyé.'];
        $user = $this->users->findOneBy(['email' => strtolower(trim($input->email))]);
        if ($user === null || !\in_array($user->getStatus(), [UserStatus::Active, UserStatus::Pending], true)) {
            return $this->json($ok);
        }
        $raw = bin2hex(random_bytes(32));
        $user->setPasswordResetChallenge(hash('sha256', $raw), new \DateTimeImmutable(sprintf('+%d minutes', AccountMailer::RESET_MINUTES)));
        $this->entityManager->flush();
        try {
            $this->accountMailer->sendPasswordReset($user, $raw, $input->returnTo);
        } catch (\Throwable) {
            // Même réponse : l'utilisateur pourra redemander un lien.
        }

        return $this->json($ok);
    }

    /** Nouveau mot de passe avec le jeton reçu par email ; l'adresse est confirmée par la même occasion. */
    #[Route('/password/reset', name: 'api_auth_password_reset', methods: ['POST'])]
    public function resetPassword(
        #[MapRequestPayload] ResetPasswordInput $input,
    ): JsonResponse {
        $request = $this->requestStack->getCurrentRequest();
        if (!$this->registerLimiter->create('reset-'.($request?->getClientIp() ?? 'anonymous'))->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop de tentatives — réessayez plus tard.'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        $user = $this->users->findOneBy(['passwordResetToken' => hash('sha256', $input->token)]);
        if ($user === null || !\in_array($user->getStatus(), [UserStatus::Active, UserStatus::Pending], true)) {
            return $this->json(['error' => 'Lien invalide ou déjà utilisé : demandez-en un nouveau.'], Response::HTTP_BAD_REQUEST);
        }
        $expires = $user->getPasswordResetExpiresAt();
        if ($expires === null || $expires < new \DateTimeImmutable()) {
            $user->setPasswordResetChallenge(null, null);
            $this->entityManager->flush();

            return $this->json(['error' => 'Lien expiré : demandez-en un nouveau.'], Response::HTTP_BAD_REQUEST);
        }
        $user->setPassword($this->passwordHasher->hashPassword($user, $input->password));
        $user->setPasswordResetChallenge(null, null);
        // Le lien reçu prouve l'adresse : un compte en attente de confirmation devient actif.
        $wasActive = $user->getStatus() === UserStatus::Active;
        if (!$wasActive) {
            $user->setStatus(UserStatus::Active);
            $user->clearEmailVerificationChallenge();
        }
        if ($user->getEmailVerifiedAt() === null) {
            $user->setEmailVerifiedAt(new \DateTimeImmutable());
        }
        $this->entityManager->flush();
        if (!$wasActive) {
            $this->adminNotifier->accountCreated($user);
        }

        return $this->json([
            'status' => UserStatus::Active->value,
            'token' => $this->jwtManager->create($user),
            'user' => $this->serializeUser($user),
            'message' => 'Mot de passe changé : vous êtes connecté.',
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
            $this->accountMailer->sendEmailVerification($user, $rawToken, $input->returnTo);
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

    /** Create a short-lived one-time code for sister apps (Belts, …). */
    #[Route('/handoff', name: 'api_auth_handoff_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function createHandoff(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $code = $this->authHandoff->issue($user);

        return $this->json([
            'code' => $code,
            'expiresIn' => $this->authHandoff->ttlSeconds(),
        ]);
    }

    /** Exchange a handoff code for a JWT (public). */
    #[Route('/handoff/exchange', name: 'api_auth_handoff_exchange', methods: ['POST'])]
    public function exchangeHandoff(\Symfony\Component\HttpFoundation\Request $request): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];
        $code = \is_string($body['code'] ?? null) ? $body['code'] : '';
        $email = $this->authHandoff->consume($code);
        if ($email === null) {
            return $this->json(['error' => 'Code invalide ou expiré.'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->users->findOneBy(['email' => $email]);
        if ($user === null || $user->getStatus() !== UserStatus::Active) {
            return $this->json(['error' => 'Compte indisponible.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'token' => $this->jwtManager->create($user),
            'user' => $this->serializeUser($user),
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
     *   emailVerifiedAt: ?string,
     *   themeId: string
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
            'superAdmin' => $this->superAdmin->isAllowed($user),
            'linkDetectors' => $user->getLinkDetectors(),
            'emailVerifiedAt' => $user->getEmailVerifiedAt()?->format(\DateTimeInterface::ATOM),
            'themeId' => $user->getThemeId(),
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
