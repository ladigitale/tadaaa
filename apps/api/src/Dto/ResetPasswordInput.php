<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ResetPasswordInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 128)]
    public string $token = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: 8, max: 128)]
    public string $password = '';
}
