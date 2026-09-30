<?php

declare(strict_types=1);

namespace App\Entity;

enum ArtifactVisibility: string
{
    case Private = 'private';
    case Link = 'link';
    case Public = 'public';
}
