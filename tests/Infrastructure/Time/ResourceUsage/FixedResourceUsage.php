<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Time\ResourceUsage;

use App\Infrastructure\Time\ResourceUsage\ResourceUsage;

final readonly class FixedResourceUsage implements ResourceUsage
{
    public function startTimer(): void
    {
        // Intentionally left blank for deterministic test output.
    }

    public function stopTimer(): void
    {
        // Intentionally left blank for deterministic test output.
    }

    public function getRunTimeInSeconds(): float
    {
        return 10;
    }

    public function format(): string
    {
        return 'Time: 10s, Memory: 45MB';
    }
}
