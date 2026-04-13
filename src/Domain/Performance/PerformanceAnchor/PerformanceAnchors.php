<?php

declare(strict_types=1);

namespace App\Domain\Performance\PerformanceAnchor;

use App\Infrastructure\ValueObject\Collection;

/**
 * @extends Collection<PerformanceAnchor>
 */
final class PerformanceAnchors extends Collection
{
    public function getItemClassName(): string
    {
        return PerformanceAnchor::class;
    }
}