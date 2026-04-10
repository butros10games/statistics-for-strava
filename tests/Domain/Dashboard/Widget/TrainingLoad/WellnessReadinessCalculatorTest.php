<?php

declare(strict_types=1);

namespace App\Tests\Domain\Dashboard\Widget\TrainingLoad;

use App\Domain\Dashboard\Widget\TrainingLoad\ReadinessStatus;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingMetrics;
use App\Domain\Dashboard\Widget\TrainingLoad\WellnessReadinessCalculator;
use App\Domain\Dashboard\Widget\Wellness\FindWellnessMetrics\FindWellnessMetricsResponse;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use PHPUnit\Framework\TestCase;

final class WellnessReadinessCalculatorTest extends TestCase
{
    private WellnessReadinessCalculator $calculator;

    public function testItReturnsNullWithoutWellnessData(): void
    {
        $this->assertNull($this->calculator->calculate(
            trainingMetrics: TrainingMetrics::create([]),
            wellnessMetrics: new FindWellnessMetricsResponse([], null),
        ));
    }

    public function testItCanCalculateReadyState(): void
    {
        $score = $this->calculator->calculate(
            trainingMetrics: TrainingMetrics::create($this->buildConstantIntensities(100, 60)),
            wellnessMetrics: new FindWellnessMetricsResponse(
                records: [
                    ['day' => '2026-04-05', 'stepsCount' => 10000, 'sleepDurationInSeconds' => 27000, 'sleepScore' => 74, 'hrv' => 50.0],
                    ['day' => '2026-04-06', 'stepsCount' => 11000, 'sleepDurationInSeconds' => 28800, 'sleepScore' => 82, 'hrv' => 56.0],
                ],
                latestDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            ),
        );

        $this->assertNotNull($score);
        $this->assertGreaterThanOrEqual(75, $score->getValue());
        $this->assertSame(ReadinessStatus::READY_TO_GO, $score->getStatus());
    }

    public function testItCanCalculateRecoveryNeededState(): void
    {
        $score = $this->calculator->calculate(
            trainingMetrics: TrainingMetrics::create($this->buildConstantIntensities(100, 60)),
            wellnessMetrics: new FindWellnessMetricsResponse(
                records: [
                    ['day' => '2026-04-05', 'stepsCount' => 10000, 'sleepDurationInSeconds' => 28800, 'sleepScore' => 80, 'hrv' => 58.0],
                    ['day' => '2026-04-06', 'stepsCount' => 7000, 'sleepDurationInSeconds' => 21600, 'sleepScore' => 60, 'hrv' => 42.0],
                ],
                latestDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            ),
        );

        $this->assertNotNull($score);
        $this->assertLessThan(45, $score->getValue());
        $this->assertSame(ReadinessStatus::NEEDS_RECOVERY, $score->getStatus());
    }

    public function testItPenalizesHighMonotonyWhenRecoverySignalsDip(): void
    {
        $score = $this->calculator->calculate(
            trainingMetrics: TrainingMetrics::create($this->buildMonotonousIntensities()),
            wellnessMetrics: new FindWellnessMetricsResponse(
                records: [
                    ['day' => '2026-04-01', 'stepsCount' => 9000, 'sleepDurationInSeconds' => 28200, 'sleepScore' => 80, 'hrv' => 60.0],
                    ['day' => '2026-04-02', 'stepsCount' => 9200, 'sleepDurationInSeconds' => 28500, 'sleepScore' => 81, 'hrv' => 61.0],
                    ['day' => '2026-04-03', 'stepsCount' => 9100, 'sleepDurationInSeconds' => 28800, 'sleepScore' => 82, 'hrv' => 60.5],
                    ['day' => '2026-04-04', 'stepsCount' => 9400, 'sleepDurationInSeconds' => 27900, 'sleepScore' => 79, 'hrv' => 59.5],
                    ['day' => '2026-04-05', 'stepsCount' => 9500, 'sleepDurationInSeconds' => 27600, 'sleepScore' => 78, 'hrv' => 59.0],
                    ['day' => '2026-04-06', 'stepsCount' => 14500, 'sleepDurationInSeconds' => 24000, 'sleepScore' => 70, 'hrv' => 52.0],
                ],
                latestDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            ),
        );

        $this->assertNotNull($score);
        $this->assertLessThan(60, $score->getValue());
        $this->assertContains($score->getStatus(), [ReadinessStatus::CAUTION, ReadinessStatus::NEEDS_RECOVERY]);
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->calculator = new WellnessReadinessCalculator();
    }

    /**
     * @return array<string, int>
     */
    private function buildConstantIntensities(int $value, int $numberOfDays): array
    {
        $intensities = [];

        for ($day = $numberOfDays - 1; $day >= 0; --$day) {
            $date = SerializableDateTime::fromString('2026-04-06 00:00:00')->modify(sprintf('-%d days', $day));
            $intensities[$date->format('Y-m-d')] = $value;
        }

        return $intensities;
    }

    /**
     * @return array<string, int>
     */
    private function buildMonotonousIntensities(): array
    {
        $intensities = [];

        for ($day = 59; $day >= 7; --$day) {
            $date = SerializableDateTime::fromString('2026-04-06 00:00:00')->modify(sprintf('-%d days', $day));
            $intensities[$date->format('Y-m-d')] = 80;
        }

        foreach ([95, 95, 95, 95, 95, 95, 60] as $offset => $value) {
            $date = SerializableDateTime::fromString('2026-04-06 00:00:00')->modify(sprintf('-%d days', 6 - $offset));
            $intensities[$date->format('Y-m-d')] = $value;
        }

        ksort($intensities);

        return $intensities;
    }
}