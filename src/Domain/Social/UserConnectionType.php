<?php

declare(strict_types=1);

namespace App\Domain\Social;

enum UserConnectionType: string
{
    case FOLLOW = 'follow';
    case FRIEND = 'friend';
}
