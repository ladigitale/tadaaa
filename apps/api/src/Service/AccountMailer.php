<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Emails de compte (HTML + texte, gabarit commun `templates/emails/layout.*.twig`) : confirmation d'adresse,
 * réinitialisation du mot de passe, avis de modération, avis au super admin.
 */
final class AccountMailer
{
    public const VERIFY_HOURS = 48;
    public const RESET_MINUTES = 60;

    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%env(MAIL_FROM)%')]
        private readonly string $mailFrom,
        #[Autowire('%env(APP_PUBLIC_URL)%')]
        private readonly string $appPublicUrl,
    ) {
    }

    public function sendEmailVerification(User $user, string $rawToken, ?string $returnTo = null): void
    {
        $this->send($user->getEmail(), 'Confirmez votre adresse e-mail', [
            'preheader' => 'Un clic pour activer votre compte Tadaaa.',
            'heading' => 'Confirmez votre adresse e-mail',
            'paragraphs' => [
                'Bonjour,',
                'Merci pour votre inscription. Confirmez votre adresse pour activer votre compte Tadaaa.',
            ],
            'action_label' => 'Confirmer mon adresse',
            'action_url' => $this->verificationUrl($rawToken, $returnTo),
            'note' => sprintf('Ce lien est valable %d heures.', self::VERIFY_HOURS),
            'footer' => 'Vous recevez ce message parce qu’un compte Tadaaa a été créé avec cette adresse. Si ce n’est pas vous, ignorez-le : aucun compte ne sera activé.',
        ]);
    }

    /** Lien de confirmation ; `return_to` n'est gardé que s'il vise une app sœur. */
    public function verificationUrl(string $rawToken, ?string $returnTo = null): string
    {
        $base = rtrim($this->appPublicUrl, '/');
        $url = $base.'/account/verify?token='.rawurlencode($rawToken);
        $safe = SisterReturnUrl::sanitize($returnTo, $this->appPublicUrl);
        if ($safe !== null) {
            $url .= '&return_to='.rawurlencode($safe);
        }

        return $url;
    }

    public function sendPasswordReset(User $user, string $rawToken, ?string $returnTo = null): void
    {
        $url = rtrim($this->appPublicUrl, '/').'/account/reset?token='.rawurlencode($rawToken);
        $safe = SisterReturnUrl::sanitize($returnTo, $this->appPublicUrl);
        if ($safe !== null) {
            $url .= '&return_to='.rawurlencode($safe);
        }
        $this->send($user->getEmail(), 'Réinitialisez votre mot de passe', [
            'preheader' => 'Choisissez un nouveau mot de passe pour votre compte Tadaaa.',
            'heading' => 'Nouveau mot de passe',
            'paragraphs' => [
                'Bonjour,',
                'Une réinitialisation du mot de passe a été demandée pour votre compte Tadaaa. Choisissez-en un nouveau avec le bouton ci-dessous.',
            ],
            'action_label' => 'Choisir un nouveau mot de passe',
            'action_url' => $url,
            'note' => sprintf('Ce lien est valable %d minutes et ne sert qu’une fois. Votre mot de passe actuel reste valable tant que vous n’en choisissez pas un autre.', self::RESET_MINUTES),
            'footer' => 'Si vous n’avez rien demandé, ignorez ce message : votre compte ne change pas.',
        ]);
    }

    public function sendModerationNotice(User $user, string $action, string $personalMessage = ''): void
    {
        [$subject, $intro] = match ($action) {
            'disabled' => ['Votre compte Tadaaa a été désactivé', 'Votre compte a été désactivé : vous ne pouvez plus vous connecter ni synchroniser vos données.'],
            'rejected' => ['Votre demande de compte Tadaaa a été refusée', 'Votre demande de compte n’a pas été acceptée.'],
            'deleted' => ['Votre compte Tadaaa a été supprimé', 'Votre compte et les données qui y étaient liées ont été supprimés.'],
            'reactivated' => ['Votre compte Tadaaa est actif', 'Votre compte est de nouveau actif : vous pouvez vous connecter.'],
            default => ['Votre compte Tadaaa', 'Une modification a été faite sur votre compte.'],
        };
        $msg = trim($personalMessage);
        $this->send($user->getEmail(), $subject, [
            'preheader' => $intro,
            'heading' => $subject,
            'paragraphs' => array_values(array_filter(['Bonjour,', $intro, $msg !== '' ? 'Message de l’équipe :' : null])),
            'quote' => $msg !== '' ? $msg : null,
            'action_label' => $action === 'reactivated' ? 'Se connecter' : null,
            'action_url' => $action === 'reactivated' ? rtrim($this->appPublicUrl, '/').'/account/login' : null,
            'footer' => 'Message envoyé à propos de votre compte Tadaaa.',
        ]);
    }

    /** Avis au super admin (signalement, demande de compte…), quand le push n'a atteint aucun appareil. */
    public function sendAdminNotice(User $admin, string $subject, string $text, string $url): void
    {
        $this->send($admin->getEmail(), $subject, [
            'preheader' => $text,
            'heading' => $subject,
            'paragraphs' => [$text],
            'action_label' => 'Ouvrir',
            'action_url' => $url,
            'footer' => 'Notification d’administration (super admin). Réglable dans Tadaaa, Réglages, Notifications.',
        ]);
    }

    /** @param array<string, mixed> $vars */
    private function send(string $to, string $subject, array $vars): void
    {
        $base = rtrim($this->appPublicUrl, '/');
        $context = $vars + ['quote' => null, 'action_url' => null, 'action_label' => null, 'note' => null];
        $context += ['subject' => $subject, 'app_url' => $base, 'app_host' => parse_url($base, \PHP_URL_HOST) ?: $base];
        $email = (new TemplatedEmail())
            ->from(new Address($this->mailFrom, 'Tadaaa'))
            ->replyTo(new Address($this->mailFrom, 'Tadaaa'))
            ->to($to)
            ->subject($subject.' — Tadaaa')
            ->htmlTemplate('emails/layout.html.twig')
            ->textTemplate('emails/layout.txt.twig')
            ->context($context);

        $this->mailer->send($email);
    }
}
