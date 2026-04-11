<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final class TrainingLoadForecastProjection
{
    /** @var array<int, array{day: SerializableDateTime, projectedLoad: float, ctl: float, atl: float, tsb: TSB, acRatio: AcRatio}> */
    private array $forecast = [];
    private ?int $daysUntilTsbHealthy = null;
    private ?int $daysUntilAcRatioHealthy = null;

    private function __construct(
        private readonly TrainingMetrics $trainingMetrics,
        private readonly SerializableDateTime $now,
        private readonly TrainingLoadForecastScenario $scenario,
        private readonly int $horizon,
        /** @var array<int, float> */
        private readonly array $projectedLoads,
    ) {
        $this->buildForecast();
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
    ): self {
        return new self(
            trainingMetrics: $metrics,
            now: $now,
            scenario: $scenario,
            horizon: $horizon,
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
}
