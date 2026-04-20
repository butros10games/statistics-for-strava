<?php

declare(strict_types=1);

namespace App\Domain\Social;

use App\Infrastructure\ValueObject\Identifier\Identifier;

final readonly class UserConnectionId extends Identifier
{
    public static function getPrefix(): string
    {
        return 'connection-';
    }
}
