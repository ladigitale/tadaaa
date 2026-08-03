<?php

declare(strict_types=1);

namespace App\Entity;

enum UserStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Rejected = 'rejected';
    case Disabled = 'disabled';

    public function isUsable(): bool
    {
        return $this === self::Active;
    }

    public function denialMessage(): string
    {
        return match ($this) {
            self::Pending => 'Vérifiez votre email pour activer le compte (lien reçu à l’inscription).',
            self::Rejected => 'Votre demande de compte a été refusée.',
            self::Disabled => 'Votre compte a été désactivé.',
            self::Active => 'Compte actif.',
        };
    }
}
