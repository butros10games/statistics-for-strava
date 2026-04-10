<?php

declare(strict_types=1);

namespace App\Tests\Domain\Dashboard\Widget\TrainingLoad;

use App\Domain\Activity\ActivityType;
use App\Domain\Dashboard\Widget\TrainingLoad\ActivityTypeRecoveryFingerprint;
use App\Domain\Dashboard\Widget\TrainingLoad\ActivityTypeRecoveryFingerprintProfile;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingLoadAnalyticsContext;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingLoadCorrelationInsight;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingLoadPersonalizationAdvisor;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingMetrics;
use App\Domain\Dashboard\Widget\Wellness\FindDailyRecoveryCheckIns\FindDailyRecoveryCheckInsResponse;
use App\Domain\Dashboard\Widget\Wellness\FindWellnessMetrics\FindWellnessMetricsResponse;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use PHPUnit\Framework\TestCase;

final class TrainingLoadPersonalizationAdvisorTest extends TestCase
{
    public function testItAppliesBoundedPersonalizationForCostlyRecentActivityType(): void
    {
        $context = $this->buildContext();
        $personalization = (new TrainingLoadPersonalizationAdvisor())->build(
            analyticsContext: $context,
            correlationInsights: [
                new TrainingLoadCorrelationInsight(
                    key: 'loadToNextDayHrv',
                    title: 'Higher-load days tend to pull next-day HRV down',
                    summary: 'Across your recent training, bigger load days were often followed by lower next-day HRV.',
                    correlation: -0.62,
                    sampleSize: 12,
                ),
            ],
            activityTypeRecoveryFingerprints: [
                new ActivityTypeRecoveryFingerprint(
                    activityType: ActivityType::RUN,
                    sampleSize: 5,
                    averageLoad: 122.0,
                    nextDayHrvDelta: -4.1,
                    nextDaySleepScoreDelta: -3.2,
                    nextDayFatigueDelta: 0.8,
                    profile: ActivityTypeRecoveryFingerprintProfile::NEEDS_BUFFER,
                ),
            ],
            recentActivityDaySamples: [
                ['day' => '2026-04-03', 'activityType' => ActivityType::RUN, 'load' => 112, 'nextDayHrv' => 60.0, 'nextDaySleepScore' => 75, 'nextDayFatigue' => 4],
                ['day' => '2026-04-05', 'activityType' => ActivityType::RUN, 'load' => 120, 'nextDayHrv' => 58.0, 'nextDaySleepScore' => 73, 'nextDayFatigue' => 4],
            ],
        );

        self::assertTrue($personalization->isActive());
        self::assertLessThan(0, $personalization->getReadinessAdjustment());
        self::assertGreaterThan(1.0, $personalization->getForecastLoadFactor());
    }

    private function buildContext(): TrainingLoadAnalyticsContext
    {
        $start = SerializableDateTime::fromString('2026-03-25 00:00:00');
        $intensities = [];
        $rows = [];

        foreach ([80, 90, 110, 125, 135, 145, 150, 160, 165, 170, 175, 180, 185, 190] as $offset => $load) {
            $day = $start->modify(sprintf('+%d days', $offset))->format('Y-m-d');
            $intensities[$day] = $load;
        }

        $trainingMetrics = TrainingMetrics::create($intensities);
        foreach ($intensities as $day => $load) {
            $rows[] = [
                'day' => $day,
                'load' => $load,
                'atl' => (float) ($trainingMetrics->getAtlValues()[$day] ?? 0.0),
                'ctl' => (float) ($trainingMetrics->getCtlValues()[$day] ?? 0.0),
                'tsb' => (float) ($trainingMetrics->getTsbValues()[$day] ?? 0.0),
                'acRatio' => (float) ($trainingMetrics->getAcRatioValues()[$day] ?? 0.0),
                'monotony' => isset($trainingMetrics->getMonotonyValues()[$day]) ? (null === $trainingMetrics->getMonotonyValues()[$day] ? null : (float) $trainingMetrics->getMonotonyValues()[$day]) : null,
                'strain' => isset($trainingMetrics->getStrainValues()[$day]) ? (null === $trainingMetrics->getStrainValues()[$day] ? null : (int) $trainingMetrics->getStrainValues()[$day]) : null,
                'wellness' => null,
                'recoveryCheckIn' => null,
            ];
        }

        return new TrainingLoadAnalyticsContext(
            dateRange: DateRange::fromDates(from: $start, till: $start->modify('+13 days')),
            integratedLoads: $intensities,
            trainingMetrics: $trainingMetrics,
            wellnessMetrics: new FindWellnessMetricsResponse(records: [], latestDay: null),
            recoveryCheckIns: new FindDailyRecoveryCheckInsResponse(records: [], latestDay: null),
            rows: $rows,
        );
    }
}