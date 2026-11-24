<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ForgotPasswordInput
{
    #[Assert\NotBlank]
    #[Assert\Email]
    public string $email = '';

    /** Adresse de retour vers une app sœur après le changement de mot de passe (facultatif). */
    #[Assert\Length(max: 500)]
    public ?string $returnTo = null;
}
