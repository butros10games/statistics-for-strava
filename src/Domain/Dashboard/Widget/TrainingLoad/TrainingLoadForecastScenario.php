<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

enum TrainingLoadForecastScenario: string
{
    case COMPLETE_REST = 'completeRest';
    case LIGHT_RECOVERY = 'lightRecovery';
    case MAINTAIN_RECENT_LOAD = 'maintainRecentLoad';
    case ONE_HARD_SESSION = 'oneHardSession';

    public function getLabel(): string
    {
        return match ($this) {
            self::COMPLETE_REST => 'Complete rest',
            self::LIGHT_RECOVERY => 'Light recovery',
            self::MAINTAIN_RECENT_LOAD => 'Maintain recent load',
            self::ONE_HARD_SESSION => 'One hard session',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::COMPLETE_REST => 'Projects the next 7 days if you take full rest from training load.',
            self::LIGHT_RECOVERY => 'Assumes a week of light movement and easy aerobic sessions.',
            self::MAINTAIN_RECENT_LOAD => 'Keeps your next week near the average load of your last 7 days.',
            self::ONE_HARD_SESSION => 'Models one harder day followed by easier recovery-oriented sessions.',
        };
    }

    /**
     * @return array<int, float>
     */
    public function buildProjectedLoads(TrainingMetrics $trainingMetrics, float $loadFactor = 1.0, int $horizon = 7): array
    {
        $horizon = max(1, $horizon);
        $recentLoads = array_values(array_filter(
            $trainingMetrics->getTrimpValuesForXLastDays(7),
            static fn (int|float|null $value): bool => null !== $value,
        ));
        $averageRecentLoad = [] === $recentLoads ? 0.0 : array_sum($recentLoads) / count($recentLoads);

        if (0.0 === $averageRecentLoad) {
            return array_fill_keys(range(1, $horizon), 0.0);
        }

        $loads = match ($this) {
            self::COMPLETE_REST => array_fill_keys(range(1, $horizon), 0.0),
            self::LIGHT_RECOVERY => array_fill_keys(range(1, $horizon), round($averageRecentLoad * 0.35, 1)),
            self::MAINTAIN_RECENT_LOAD => array_fill_keys(range(1, $horizon), round($averageRecentLoad, 1)),
            self::ONE_HARD_SESSION => $this->buildOneHardSessionLoads($averageRecentLoad, $horizon),
        };

        if (1.0 === $loadFactor) {
            return $loads;
        }

        return array_map(
            static fn (float $load): float => round($load * $loadFactor, 1),
            $loads,
        );
    }

    /**
     * @return array<int, float>
     */
    private function buildOneHardSessionLoads(float $averageRecentLoad, int $horizon): array
    {
        $pattern = [
            1 => round(max($averageRecentLoad * 1.35, $averageRecentLoad + 15.0), 1),
            2 => round($averageRecentLoad * 0.4, 1),
            3 => round($averageRecentLoad * 0.25, 1),
            4 => round($averageRecentLoad * 0.25, 1),
            5 => round($averageRecentLoad * 0.3, 1),
            6 => round($averageRecentLoad * 0.3, 1),
            7 => round($averageRecentLoad * 0.25, 1),
        ];

        if ($horizon <= 7) {
            return array_intersect_key($pattern, array_flip(range(1, $horizon)));
        }

        for ($day = 8; $day <= $horizon; ++$day) {
            $pattern[$day] = $pattern[7];
        }

        return $pattern;
    }
}