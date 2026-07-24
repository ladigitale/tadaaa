<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Dataset;
use App\Entity\DatasetInvite;
use App\Entity\DatasetMember;
use App\Entity\DatasetMemberRole;
use App\Entity\User;
use App\Entity\UserStatus;
use App\Repository\DatasetInviteRepository;
use App\Repository\DatasetMemberRepository;
use App\Repository\UserRepository;
use App\Util\BaseIdParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

final class DatasetShareService
{
    private const INVITE_TTL_DAYS = 7;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DatasetAccessService $access,
        private readonly DatasetMemberRepository $members,
        private readonly DatasetInviteRepository $invites,
        private readonly DatasetRealtimePublisher $realtime,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @return array{token: string, urlPath: string, role: string, expiresAt: string}
     */
    public function createInvite(User $owner, Dataset $dataset, DatasetMemberRole $role): array
    {
        $this->access->assertIsOwner($owner, $dataset);

        return $this->persistInvite($dataset, $owner, $role);
    }

    /**
     * Create an invite and push it to the invitee's Mercure user topic when an active account exists.
     *
     * @return array{
     *   token: string,
     *   urlPath: string,
     *   role: string,
     *   expiresAt: string,
     *   notified: bool,
     *   email: string,
     * }
     */
    public function inviteByEmail(
        User $owner,
        Dataset $dataset,
        string $email,
        DatasetMemberRole $role,
    ): array {
        $this->access->assertIsOwner($owner, $dataset);

        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BadRequestHttpException('Adresse e-mail invalide.');
        }

        if ($email === strtolower($owner->getEmail())) {
            throw new BadRequestHttpException('Vous ne pouvez pas vous inviter vous-même.');
        }

        $invitee = $this->users->findOneBy(['email' => $email]);
        if ($invitee !== null && $this->access->getRole($invitee, $dataset) !== null) {
            throw new BadRequestHttpException('Cette personne a déjà accès à ce jeu.');
        }

        $invite = $this->persistInvite($dataset, $owner, $role);
        $notified = false;

        if (
            $invitee !== null
            && $invitee->getStatus() === UserStatus::Active
        ) {
            $this->realtime->publishDatasetInvite($invitee, $dataset, $owner, $invite);
            $notified = true;
        }

        return [
            ...$invite,
            'notified' => $notified,
            'email' => $email,
        ];
    }

    /**
     * @return array{token: string, urlPath: string, role: string, expiresAt: string}
     */
    private function persistInvite(Dataset $dataset, User $owner, DatasetMemberRole $role): array
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTimeImmutable())->modify('+'.self::INVITE_TTL_DAYS.' days');
        $invite = new DatasetInvite($dataset, $owner, $token, $role, $expiresAt);
        $this->entityManager->persist($invite);
        $this->entityManager->flush();

        return [
            'token' => $token,
            'urlPath' => '/invite?token='.$token,
            'role' => $role->value,
            'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array{datasetName: string, role: string, expiresAt: string, usable: bool}
     */
    public function previewInvite(string $token): array
    {
        $invite = $this->requireInvite($token);

        return [
            'datasetName' => $invite->getDataset()->getName(),
            'role' => $invite->getRole()->value,
            'expiresAt' => $invite->getExpiresAt()->format(\DateTimeInterface::ATOM),
            'usable' => $invite->isUsable(),
        ];
    }

    /**
     * @return array{
     *   dataset: array{id: string, baseId: string, name: string, role: string},
     * }
     */
    public function acceptInvite(User $user, string $token): array
    {
        if ($user->getStatus() !== UserStatus::Active) {
            throw new AccessDeniedHttpException('Compte non actif.');
        }

        $invite = $this->requireInvite($token);
        if (!$invite->isUsable()) {
            throw new BadRequestHttpException('Cette invitation n’est plus valide.');
        }

        $dataset = $invite->getDataset();
        if ($this->access->getRole($user, $dataset) !== null) {
            throw new BadRequestHttpException('Vous avez déjà accès à ce jeu.');
        }

        $member = new DatasetMember($dataset, $user, $invite->getRole());
        $invite->accept($user);
        $this->entityManager->persist($member);
        $this->entityManager->flush();

        $role = $this->access->getRole($user, $dataset);
        $roleValue = $role?->value ?? $invite->getRole()->value;
        $this->realtime->publishMemberJoined($dataset, $user, $roleValue);

        return [
            'dataset' => [
                'id' => $dataset->getId()->toRfc4122(),
                'baseId' => BaseIdParser::format($dataset->getBaseId()),
                'name' => $dataset->getName(),
                'role' => $roleValue,
            ],
        ];
    }

    /**
     * @return list<array{id: string, userId: string, email: string, role: string, createdAt: string}>
     */
    public function listMembers(User $owner, Dataset $dataset): array
    {
        $this->access->assertIsOwner($owner, $dataset);

        $rows = [];
        $rows[] = [
            'id' => 'owner',
            'userId' => $dataset->getOwner()->getId()->toRfc4122(),
            'email' => $dataset->getOwner()->getEmail(),
            'role' => 'owner',
            'createdAt' => $dataset->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];

        foreach ($this->members->findAllForDataset($dataset) as $member) {
            $rows[] = [
                'id' => $member->getId()->toRfc4122(),
                'userId' => $member->getUser()->getId()->toRfc4122(),
                'email' => $member->getUser()->getEmail(),
                'role' => $member->getRole()->value,
                'createdAt' => $member->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return $rows;
    }

    public function removeMember(User $owner, Dataset $dataset, string $userId): void
    {
        $this->access->assertIsOwner($owner, $dataset);

        try {
            $uuid = Uuid::fromString($userId);
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('userId invalide.');
        }

        if ($dataset->getOwner()->getId()->equals($uuid)) {
            throw new BadRequestHttpException('Impossible de retirer le propriétaire.');
        }

        $target = null;
        foreach ($this->members->findAllForDataset($dataset) as $member) {
            if ($member->getUser()->getId()->equals($uuid)) {
                $target = $member;
                break;
            }
        }
        if ($target === null) {
            throw new NotFoundHttpException('Membre introuvable.');
        }

        $memberUser = $target->getUser();
        if ($memberUser->getActiveDataset()?->getId()->equals($dataset->getId())) {
            $memberUser->setActiveDataset(null);
        }

        $this->entityManager->remove($target);
        $this->entityManager->flush();
    }

    private function requireInvite(string $token): DatasetInvite
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 64) {
            throw new BadRequestHttpException('Token d’invitation invalide.');
        }

        $invite = $this->invites->findOneByToken($token);
        if ($invite === null) {
            throw new NotFoundHttpException('Invitation introuvable.');
        }

        return $invite;
    }
}
