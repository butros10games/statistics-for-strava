<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\Wellness;

enum WellnessMetric: string
{
    case STEPS = 'steps';
    case SLEEP = 'sleep';
    case SLEEP_SCORE = 'sleepScore';
    case HRV = 'hrv';

    public function getLabel(): string
    {
        return match ($this) {
            self::STEPS => 'Steps',
            self::SLEEP => 'Sleep',
            self::SLEEP_SCORE => 'Sleep score',
            self::HRV => 'HRV',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::STEPS => '#2563EB',
            self::SLEEP => '#7C3AED',
            self::SLEEP_SCORE => '#D97706',
            self::HRV => '#059669',
        };
    }

    public function getAxisLabelFormatter(): string
    {
        return match ($this) {
            self::STEPS => '{value} steps',
            self::SLEEP => '{value} h',
            self::SLEEP_SCORE => '{value}',
            self::HRV => '{value} ms',
        };
    }

    /**
     * @param array{
     *     stepsTotal: int,
     *     stepsCount: int,
     *     sleepDurationTotal: int,
     *     sleepDurationCount: int,
     *     sleepScoreTotal: int,
     *     sleepScoreCount: int,
     *     hrvTotal: float,
     *     hrvCount: int
     * } $bucket
     */
    public function resolveValue(array $bucket): int|float|null
    {
        return match ($this) {
            self::STEPS => 0 === $bucket['stepsCount'] ? null : $bucket['stepsTotal'],
            self::SLEEP => 0 === $bucket['sleepDurationCount'] ? null : round(($bucket['sleepDurationTotal'] / $bucket['sleepDurationCount']) / 3600, 1),
            self::SLEEP_SCORE => 0 === $bucket['sleepScoreCount'] ? null : round($bucket['sleepScoreTotal'] / $bucket['sleepScoreCount'], 1),
            self::HRV => 0 === $bucket['hrvCount'] ? null : round($bucket['hrvTotal'] / $bucket['hrvCount'], 1),
        };
    }
}