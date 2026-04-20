<?php

declare(strict_types=1);

namespace App\Domain\Social;

enum UserConnectionStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case DECLINED = 'declined';
}
