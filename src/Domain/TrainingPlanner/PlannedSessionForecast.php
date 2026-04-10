<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

final readonly class PlannedSessionForecast
{
    /**
     * @param array<int, float> $projectedLoads
     * @param list<PlannedSessionLoadEstimate> $estimates
     */
    private function __construct(
        private array $projectedLoads,
        private array $estimates,
    ) {
    }

    public static function empty(int $horizon): self
    {
        return new self(
            projectedLoads: array_fill_keys(range(1, max(1, $horizon)), 0.0),
            estimates: [],
        );
    }

    /**
     * @param array<int, float> $projectedLoads
     * @param list<PlannedSessionLoadEstimate> $estimates
     */
    public static function create(array $projectedLoads, array $estimates): self
    {
        return new self(
            projectedLoads: $projectedLoads,
            estimates: $estimates,
        );
    }

    /**
     * @return array<int, float>
     */
    public function getProjectedLoads(): array
    {
        return $this->projectedLoads;
    }

    /**
     * @return list<PlannedSessionLoadEstimate>
     */
    public function getEstimates(): array
    {
        return $this->estimates;
    }

    public function hasEstimates(): bool
    {
        return [] !== $this->estimates;
    }

    public function getTotalProjectedLoad(): float
    {
        return round(array_sum($this->projectedLoads), 1);
    }
}
