<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class RegisterInput
{
    #[Assert\NotBlank]
    #[Assert\Email]
    public string $email = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: 8, max: 128)]
    public string $password = '';

    /** Honeypot — must stay empty. */
    public string $website = '';

    #[Assert\IsTrue(message: 'Vous devez accepter les CGU et la politique de confidentialité.')]
    public bool $acceptedTerms = false;

    /** Adresse de retour vers une app sœur après confirmation de l’email (facultatif). */
    #[Assert\Length(max: 500)]
    public ?string $returnTo = null;
}
