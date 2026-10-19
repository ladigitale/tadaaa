<?php

declare(strict_types=1);

namespace App\Service\Room;

enum JoinDecision: string
{
    /** Entrée immédiate. */
    case Admit = 'admit';
    /** Demande mise en attente de la décision de l'hôte. */
    case Pending = 'pending';
    case WrongCode = 'wrong_code';
    case Full = 'full';
    case TooManyPending = 'too_many_pending';
}
