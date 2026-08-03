<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class AdminQuotaInput
{
    /** null = reset to default; 0 = unlimited; >0 = custom bytes */
    #[Assert\GreaterThanOrEqual(0)]
    public ?int $storageQuotaBytes = null;

    /** null = reset to dynamic default; 0 = unlimited; >0 = custom monthly bytes */
    #[Assert\GreaterThanOrEqual(0)]
    public ?int $bandwidthQuotaMonthBytes = null;

    public bool $resetStorage = false;

    public bool $resetBandwidth = false;
}
