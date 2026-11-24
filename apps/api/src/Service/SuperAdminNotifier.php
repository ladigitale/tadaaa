<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Notification\NotificationEventType;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Prévient le super admin (ROLE_ADMIN + OPS_DEPLOY_USERS) : signalement d'un contenu d'artefact, demande de compte,
 * compte activé. Push sur ses appareils ; email si aucun appareil n'a reçu la notification. Chaque type peut être
 * coupé dans ses préférences de notification. Un échec n'interrompt jamais l'action qui l'a déclenché.
 */
final class SuperAdminNotifier
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ProdDeploy $superAdmin,
        private readonly NotificationPreferencesService $prefs,
        private readonly PushNotificationDispatcher $push,
        private readonly AccountMailer $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(ARTIFACTS_PUBLIC_URL)%')]
        private readonly string $artifactsUrl,
        #[Autowire('%env(APP_PUBLIC_URL)%')]
        private readonly string $appUrl,
    ) {
    }

    /** Contenu signalé (ou masqué au seuil) : lien vers la page Modération du site Artefacts. */
    public function report(string $artifactTitle, string $what, int $reports, bool $hidden): void
    {
        $title = $hidden ? 'Contenu masqué après signalements' : 'Nouveau signalement';
        $body = sprintf('%s — %s (%d signalement%s)', $artifactTitle, $what, $reports, $reports > 1 ? 's' : '');
        $this->notify(NotificationEventType::ADMIN_REPORT, $title, $body, rtrim($this->artifactsUrl, '/').'/admin/moderation', 'tada-admin-report');
    }

    public function accountRequested(User $user): void
    {
        $this->notify(NotificationEventType::ADMIN_ACCOUNT_REQUEST, 'Demande de compte', $user->getEmail().' attend la confirmation de son email.', rtrim($this->appUrl, '/').'/account', 'tada-admin-account');
    }

    public function accountCreated(User $user): void
    {
        $this->notify(NotificationEventType::ADMIN_ACCOUNT_CREATED, 'Compte activé', $user->getEmail().' a un compte actif.', rtrim($this->appUrl, '/').'/account', 'tada-admin-account');
    }

    /** @return list<User> */
    public function superAdmins(): array
    {
        $out = [];
        $emails = $this->superAdmin->allowedEmails();
        foreach ($emails === [] ? [] : $this->users->findBy(['email' => $emails]) as $u) {
            if ($this->superAdmin->isAllowed($u)) {
                $out[] = $u;
            }
        }

        return $out;
    }

    private function notify(string $type, string $title, string $body, string $url, string $tag): void
    {
        try {
            foreach ($this->superAdmins() as $admin) {
                if (!$this->prefs->isEnabled($admin, $type)) {
                    continue;
                }
                // Le service worker de l'app n'ouvre que ses propres chemins : URL absolue dans `href`, chemin dans `url`.
                $sent = $this->push->pushToUser($admin, ['title' => $title, 'body' => $body, 'tag' => $tag, 'url' => '/account', 'href' => $url, 'type' => $type]);
                if ($sent === 0) {
                    $this->mailer->sendAdminNotice($admin, $title, $body, $url);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Notification super admin non envoyée : '.$e->getMessage(), ['type' => $type]);
        }
    }
}
