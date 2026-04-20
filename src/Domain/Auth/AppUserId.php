<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Infrastructure\ValueObject\Identifier\Identifier;

final readonly class AppUserId extends Identifier
{
    public static function getPrefix(): string
    {
        return 'user-';
    }
}
