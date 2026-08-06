<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class DeleteAccountInput
{
    #[Assert\NotBlank]
    #[Assert\Email]
    public string $confirmEmail = '';
}
