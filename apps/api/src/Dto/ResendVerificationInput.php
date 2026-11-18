<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ResendVerificationInput
{
    #[Assert\NotBlank]
    #[Assert\Email]
    public string $email = '';

    /** Adresse de retour vers une app sœur après confirmation de l’email (facultatif). */
    #[Assert\Length(max: 500)]
    public ?string $returnTo = null;
}
