<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final class TrainingLoadForecastProjection
{
    /** @var array{day: SerializableDateTime, projectedLoad: float, ctl: float, atl: float, tsb: TSB, acRatio: AcRatio}|null */
    private ?array $currentDayProjection = null;
    /** @var array<int, array{day: SerializableDateTime, projectedLoad: float, ctl: float, atl: float, tsb: TSB, acRatio: AcRatio}> */
    private array $forecast = [];
    private ?int $daysUntilTsbHealthy = null;
    private ?int $daysUntilAcRatioHealthy = null;
    private TrainingLoadForecastConfidence $confidence;

    private function __construct(
        private readonly TrainingMetrics $trainingMetrics,
        private readonly SerializableDateTime $now,
        private readonly TrainingLoadForecastScenario $scenario,
        private readonly int $horizon,
        private readonly float $currentDayProjectedLoad,
        /** @var array<int, float> */
        private readonly array $projectedLoads,
    ) {
        $this->buildForecast();
        $this->confidence = $this->buildConfidence();
    }

    public static function create(
        TrainingMetrics $metrics,
        SerializableDateTime $now,
        TrainingLoadForecastScenario $scenario = TrainingLoadForecastScenario::COMPLETE_REST,
        float $loadFactor = 1.0,
        int $horizon = 7,
    ): self {
        return new self(
            trainingMetrics: $metrics,
            now: $now,
            scenario: $scenario,
            horizon: $horizon,
            currentDayProjectedLoad: 0.0,
            projectedLoads: $scenario->buildProjectedLoads($metrics, $loadFactor, $horizon),
        );
    }

    /**
     * @param array<int, float|int> $projectedLoads
     */
    public static function createWithProjectedLoads(
        TrainingMetrics $metrics,
        SerializableDateTime $now,
        array $projectedLoads,
        int $horizon = 7,
        TrainingLoadForecastScenario $scenario = TrainingLoadForecastScenario::COMPLETE_REST,
        float $currentDayProjectedLoad = 0.0,
    ): self {
        return new self(
            trainingMetrics: $metrics,
            now: $now,
            scenario: $scenario,
            horizon: $horizon,
            currentDayProjectedLoad: round(max(0.0, $currentDayProjectedLoad), 1),
            projectedLoads: array_map(
                static fn (float|int $load): float => round((float) $load, 1),
                $projectedLoads,
            ),
        );
    }

    private function buildForecast(): void
    {
        $alphaATL = 1 - exp(-1 / 7);
        $alphaCTL = 1 - exp(-1 / TrainingLoadChart::ROLLING_WINDOW_TO_CALCULATE_METRICS_AGAINST);

        $currentAtl = $this->trainingMetrics->getCurrentAtl() ?? 0;
        $currentCtl = $this->trainingMetrics->getCurrentCtl() ?? 0;

        $atl = $currentAtl;
        $ctl = $currentCtl;

        if ($this->currentDayProjectedLoad > 0.0) {
            $atl = $this->projectCurrentDayMetric(
                currentMetric: $atl,
                alpha: $alphaATL,
            );
            $ctl = $this->projectCurrentDayMetric(
                currentMetric: $ctl,
                alpha: $alphaCTL,
            );
            $currentDayTsb = round($ctl - $atl, 1);
            $currentDayAcRatio = $ctl > 0 ? round($atl / $ctl, 2) : 0;

            $this->currentDayProjection = [
                'day' => $this->now,
                'projectedLoad' => $this->currentDayProjectedLoad,
                'ctl' => round($ctl, 1),
                'atl' => round($atl, 1),
                'tsb' => TSB::of($currentDayTsb),
                'acRatio' => AcRatio::of($currentDayAcRatio),
            ];
        }

        for ($day = 1; $day <= $this->horizon; ++$day) {
            $projectedLoad = $this->projectedLoads[$day] ?? 0.0;
            $atl = ($projectedLoad * $alphaATL) + ($atl * (1 - $alphaATL));
            $ctl = ($projectedLoad * $alphaCTL) + ($ctl * (1 - $alphaCTL));
            $tsb = round($ctl - $atl, 1);
            $acRatio = $ctl > 0 ? round($atl / $ctl, 2) : 0;

            if (null === $this->daysUntilTsbHealthy && $tsb > 0) {
                $this->daysUntilTsbHealthy = $day;
            }
            if (null === $this->daysUntilAcRatioHealthy && $acRatio >= 0.8 && $acRatio <= 1.3) {
                $this->daysUntilAcRatioHealthy = $day;
            }

            $this->forecast[] = [
                'day' => $this->now->modify(sprintf('+ %d days', $day)),
                'projectedLoad' => $projectedLoad,
                'ctl' => round($ctl, 1),
                'atl' => round($atl, 1),
                'tsb' => TSB::of($tsb),
                'acRatio' => AcRatio::of($acRatio),
            ];
        }
    }

    /**
     * @return array<int, array{day: SerializableDateTime, projectedLoad: float, ctl: float, atl: float, tsb: TSB, acRatio: AcRatio}>
     */
    public function getProjection(): array
    {
        return $this->forecast;
    }

    /**
     * @return array{day: SerializableDateTime, projectedLoad: float, ctl: float, atl: float, tsb: TSB, acRatio: AcRatio}|null
     */
    public function getCurrentDayProjection(): ?array
    {
        return $this->currentDayProjection;
    }

    public function getScenario(): TrainingLoadForecastScenario
    {
        return $this->scenario;
    }

    public function getHorizon(): int
    {
        return $this->horizon;
    }

    public function getDaysUntilTsbHealthy(): ?int
    {
        return $this->daysUntilTsbHealthy;
    }

    public function getDaysUntilAcRatioHealthy(): ?int
    {
        return $this->daysUntilAcRatioHealthy;
    }

    public function getConfidence(): TrainingLoadForecastConfidence
    {
        return $this->confidence;
    }

    private function buildConfidence(): TrainingLoadForecastConfidence
    {
        $score = 50.0;

        $recentLoads = array_values(array_filter(
            $this->trainingMetrics->getTrimpValuesForXLastDays(14),
            static fn (int|float|null $value): bool => null !== $value,
        ));
        $recentLoadCount = count($recentLoads);

        $score += match (true) {
            $recentLoadCount >= 14 => 10.0,
            $recentLoadCount >= 7 => 4.0,
            default => -18.0,
        };

        $recentAverageLoad = [] === $recentLoads ? 0.0 : array_sum($recentLoads) / $recentLoadCount;
        if ($recentAverageLoad > 0.0) {
            $recentCv = $this->standardDeviation($recentLoads) / $recentAverageLoad;
            $score += match (true) {
                $recentCv <= 0.2 => 18.0,
                $recentCv <= 0.4 => 10.0,
                $recentCv <= 0.65 => 2.0,
                $recentCv <= 0.9 => -8.0,
                default => -14.0,
            };
        }

        $projectedLoads = array_values($this->projectedLoads);
        if ($this->currentDayProjectedLoad > 0.0) {
            array_unshift($projectedLoads, $this->currentDayProjectedLoad);
        }
        $nonZeroProjectedLoads = array_values(array_filter(
            $projectedLoads,
            static fn (float $load): bool => $load > 0.0,
        ));

        if ([] === $nonZeroProjectedLoads) {
            $score += 12.0;
        } else {
            $projectedAverageLoad = array_sum($nonZeroProjectedLoads) / count($nonZeroProjectedLoads);
            if ($projectedAverageLoad > 0.0) {
                $projectedCv = $this->standardDeviation($nonZeroProjectedLoads) / $projectedAverageLoad;
                $score += match (true) {
                    $projectedCv <= 0.35 => 6.0,
                    $projectedCv <= 0.75 => 1.0,
                    default => -8.0,
                };
            }

            if ($recentAverageLoad > 0.0) {
                $projectedAverageAcrossHorizon = array_sum($projectedLoads) / max(1, count($projectedLoads));
                $projectedGap = abs($projectedAverageAcrossHorizon - $recentAverageLoad) / $recentAverageLoad;
                $score += match (true) {
                    $projectedGap <= 0.25 => 4.0,
                    $projectedGap <= 0.6 => 0.0,
                    default => -8.0,
                };
            }
        }

        return TrainingLoadForecastConfidence::fromScore(max(0.0, min(100.0, $score)));
    }

    private function projectCurrentDayMetric(float $currentMetric, float $alpha): float
    {
        if (count($this->trainingMetrics->getIntensities()) < 2) {
            return $currentMetric + $this->currentDayProjectedLoad;
        }

        return $currentMetric + ($this->currentDayProjectedLoad * $alpha);
    }

    /**
     * @param array<int, int|float> $values
     */
    private function standardDeviation(array $values): float
    {
        $count = count($values);
        if ($count < 2) {
            return 0.0;
        }

        $mean = array_sum($values) / $count;
        $sumSquares = 0.0;
        foreach ($values as $value) {
            $sumSquares += ($value - $mean) ** 2;
        }

        return sqrt($sumSquares / $count);
    }
}
