<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class AccountMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%env(MAIL_FROM)%')]
        private readonly string $mailFrom,
        #[Autowire('%env(APP_PUBLIC_URL)%')]
        private readonly string $appPublicUrl,
    ) {
    }

    public function sendEmailVerification(User $user, string $rawToken): void
    {
        $base = rtrim($this->appPublicUrl, '/');
        $url = $base.'/account/verify?token='.rawurlencode($rawToken);
        $body = <<<TXT
Bonjour,

Confirmez votre adresse pour activer votre compte Tadaaa :

{$url}

Ce lien expire dans 48 heures.

Si vous n’êtes pas à l’origine de cette inscription, ignorez ce message.
TXT;

        $this->send($user->getEmail(), 'Confirmez votre email — Tadaaa', $body);
    }

    public function sendModerationNotice(User $user, string $action, string $personalMessage = ''): void
    {
        $subject = match ($action) {
            'disabled' => 'Votre compte Tadaaa a été désactivé',
            'rejected' => 'Votre demande de compte Tadaaa a été refusée',
            'deleted' => 'Votre compte Tadaaa a été supprimé',
            'reactivated' => 'Votre compte Tadaaa a été réactivé',
            default => 'Notification compte Tadaaa',
        };

        $intro = match ($action) {
            'disabled' => 'Votre compte a été désactivé. Vous ne pouvez plus vous connecter ni synchroniser.',
            'rejected' => 'Votre demande de compte a été refusée.',
            'deleted' => 'Votre compte et les données associées ont été supprimés.',
            'reactivated' => 'Votre compte est de nouveau actif. Vous pouvez vous reconnecter.',
            default => 'Une action a été effectuée sur votre compte.',
        };

        $extra = '';
        $trimmed = trim($personalMessage);
        if ($trimmed !== '') {
            $extra = "\n\nMessage de l’équipe :\n".$trimmed;
        }

        $this->send($user->getEmail(), $subject, $intro.$extra."\n\n— Tadaaa");
    }

    private function send(string $to, string $subject, string $text): void
    {
        $email = (new Email())
            ->from(new Address($this->mailFrom, 'Tadaaa'))
            ->to($to)
            ->subject($subject)
            ->text($text);

        $this->mailer->send($email);
    }
}
