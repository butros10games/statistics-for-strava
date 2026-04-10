<?php

declare(strict_types=1);

namespace App\Domain\Wellness;

interface WellnessProvider
{
    /**
     * @return DailyWellness[]
     */
    public function fetch(): array;
}