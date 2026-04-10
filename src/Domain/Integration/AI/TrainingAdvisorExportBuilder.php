<?php

declare(strict_types=1);

namespace App\Domain\Integration\AI;

use App\Domain\Activity\Activities;
use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityTrainingLoadCalculator;
use App\Domain\Activity\EnrichedActivities;
use App\Domain\Dashboard\Widget\TrainingLoad\IntegratedDailyLoadCalculator;
use App\Domain\Dashboard\Widget\TrainingLoad\ReadinessFactor;
use App\Domain\Dashboard\Widget\TrainingLoad\ReadinessAssessment;
use App\Domain\Dashboard\Widget\TrainingLoad\AcRatio;
use App\Domain\Dashboard\Widget\TrainingLoad\TSB;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingLoadChart;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingLoadForecastProjection;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingMetrics;
use App\Domain\Dashboard\Widget\TrainingLoad\WellnessReadinessCalculator;
use App\Domain\Dashboard\Widget\Wellness\FindWellnessMetrics\FindWellnessMetricsResponse;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionForecastBuilder;
use App\Domain\TrainingPlanner\PlannedSessionLoadEstimate;
use App\Domain\TrainingPlanner\PlannedSessionLoadEstimator;
use App\Domain\TrainingPlanner\PlannedSessionRepository;
use App\Domain\Wellness\DailyRecoveryCheckIn;
use App\Domain\Wellness\DailyRecoveryCheckInRepository;
use App\Domain\Wellness\DailyWellness;
use App\Domain\Wellness\DailyWellnessRepository;
use App\Domain\Wellness\WellnessSource;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class TrainingAdvisorExportBuilder
{
    private const int EXPORT_VERSION = 1;
    private const int RECENT_ACTIVITY_DAYS = 42;
    private const int RECENT_WELLNESS_DAYS = 30;
    private const int RECENT_TRAINING_METRICS_DAYS = 42;
    private const int FORECAST_HORIZON_DAYS = 7;
    private const int TRAINING_METRICS_WARMUP_DAYS = TrainingLoadChart::NUMBER_OF_DAYS_TO_DISPLAY + 210;

    public function __construct(
        private EnrichedActivities $enrichedActivities,
        private ActivityTrainingLoadCalculator $activityTrainingLoadCalculator,
        private IntegratedDailyLoadCalculator $integratedDailyLoadCalculator,
        private DailyWellnessRepository $dailyWellnessRepository,
        private DailyRecoveryCheckInRepository $dailyRecoveryCheckInRepository,
        private WellnessReadinessCalculator $wellnessReadinessCalculator,
        private PlannedSessionRepository $plannedSessionRepository,
        private PlannedSessionLoadEstimator $plannedSessionLoadEstimator,
        private PlannedSessionForecastBuilder $plannedSessionForecastBuilder,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(SerializableDateTime $now): array
    {
        $today = $now->setTime(0, 0);
        $activityRange = DateRange::fromDates(
            $today->modify(sprintf('-%d days', self::RECENT_ACTIVITY_DAYS - 1)),
            $today->setTime(23, 59, 59),
        );
        $wellnessRange = DateRange::fromDates(
            $today->modify(sprintf('-%d days', self::RECENT_WELLNESS_DAYS - 1)),
            $today->setTime(23, 59, 59),
        );
        $trainingMetricsRange = DateRange::fromDates(
            SerializableDateTime::fromString($now->modify(sprintf('-%d days', self::TRAINING_METRICS_WARMUP_DAYS))->format('Y-m-d 00:00:00')),
            SerializableDateTime::fromString($now->format('Y-m-d 23:59:59')),
        );
        $plannedSessionRange = DateRange::fromDates(
            $today->modify('+1 day'),
            $today->modify(sprintf('+%d days', self::FORECAST_HORIZON_DAYS))->setTime(23, 59, 59),
        );

        $recentActivities = $this->buildRecentActivitiesSection($activityRange);
        $wellnessMetrics = $this->buildWellnessMetricsResponse($wellnessRange);
        $recentRecoveryCheckIns = $this->dailyRecoveryCheckInRepository->findByDateRange($wellnessRange);
        $latestRecoveryCheckInOverall = $this->mapRecoveryCheckIn($this->dailyRecoveryCheckInRepository->findLatest());
        $latestRecoveryCheckInForToday = $this->latestRecoveryCheckInForToday($recentRecoveryCheckIns, $today);

        $integratedLoads = $this->integratedDailyLoadCalculator->calculateForDateRange($trainingMetricsRange);
        $trainingMetrics = TrainingMetrics::create($integratedLoads);
        $readinessAssessment = $this->wellnessReadinessCalculator->assess(
            $trainingMetrics,
            $wellnessMetrics,
            $latestRecoveryCheckInForToday,
        );

        $plannedSessions = $this->plannedSessionRepository->findByDateRange($plannedSessionRange);
        $plannedSessionForecast = $this->plannedSessionForecastBuilder->build($now, self::FORECAST_HORIZON_DAYS);
        $plannedSessionProjection = TrainingLoadForecastProjection::createWithProjectedLoads(
            metrics: $trainingMetrics,
            now: $now,
            projectedLoads: $plannedSessionForecast->getProjectedLoads(),
            horizon: self::FORECAST_HORIZON_DAYS,
        );

        return [
            'version' => self::EXPORT_VERSION,
            'exportType' => 'training-advisor',
            'generatedAt' => $now->format('Y-m-d H:i:s'),
            'today' => $today->format('Y-m-d'),
            'windows' => [
                'recentActivityDays' => self::RECENT_ACTIVITY_DAYS,
                'wellnessDays' => self::RECENT_WELLNESS_DAYS,
                'trainingMetricsDays' => self::RECENT_TRAINING_METRICS_DAYS,
                'forecastHorizonDays' => self::FORECAST_HORIZON_DAYS,
            ],
            'usageGuide' => [
                'goal' => 'Share this JSON with an LLM and ask for feedback on your next planned session, weekly planning, pacing or power advice, and recovery or risk flags.',
                'suggestedPrompts' => [
                    'Review my next planned session using my recent training, readiness, and recovery data. What should I keep, change, or watch out for?',
                    'Look at the next 7 days and suggest a balanced weekly plan based on my load, readiness, and planned sessions.',
                    'For my next key workout, suggest pacing, power, or effort guidance using my recent activity history and current fatigue state.',
                    'Flag any recovery risks, overload patterns, or signs that I should reduce intensity or volume.',
                ],
            ],
            'dataFreshness' => [
                'mostRecentActivityStart' => $recentActivities['summary']['mostRecentActivityStart'] ?? null,
                'latestWellnessDay' => $wellnessMetrics->getLatestDay()?->format('Y-m-d'),
                'latestRecoveryCheckInDay' => $latestRecoveryCheckInOverall['day'] ?? null,
            ],
            'currentStatus' => [
                'trainingMetrics' => $this->buildTrainingMetricsSummary($trainingMetrics),
                'readiness' => $this->buildReadinessSummary($readinessAssessment),
                'latestWellness' => $wellnessMetrics->getLatestRecord(),
                'latestRecoveryCheckIn' => $latestRecoveryCheckInOverall,
                'latestRecoveryCheckInUsedForReadiness' => $latestRecoveryCheckInForToday,
            ],
            'recentActivities' => $recentActivities,
            'trainingLoad' => [
                'current' => $this->buildTrainingMetricsSummary($trainingMetrics),
                'last42Days' => $this->buildRecentTrainingMetricsTimeline($trainingMetrics, $integratedLoads),
            ],
            'wellness' => [
                'sourceUsedForReadiness' => WellnessSource::GARMIN->value,
                'records' => $wellnessMetrics->getRecords(),
                'baseline' => $this->buildWellnessBaseline($wellnessMetrics),
            ],
            'recoveryCheckIns' => [
                'records' => array_map($this->mapRecoveryCheckIn(...), $recentRecoveryCheckIns),
                'latest' => $latestRecoveryCheckInOverall,
            ],
            'upcomingPlannedSessions' => [
                'summary' => [
                    'count' => count($plannedSessions),
                    'totalProjectedLoad' => $plannedSessionForecast->getTotalProjectedLoad(),
                    'projectedLoadsByDayOffset' => $this->buildProjectedLoadsByDayOffset($now, $plannedSessionForecast->getProjectedLoads()),
                    'recoveryProjection' => [
                        'daysUntilTsbHealthy' => $plannedSessionProjection->getDaysUntilTsbHealthy(),
                        'daysUntilAcRatioHealthy' => $plannedSessionProjection->getDaysUntilAcRatioHealthy(),
                    ],
                ],
                'projection' => $this->buildForecastProjection($plannedSessionProjection),
                'items' => array_map(fn (PlannedSession $plannedSession): array => $this->mapPlannedSession($plannedSession), $plannedSessions),
            ],
        ];
    }

    /**
     * @return array{summary: array<string, mixed>, items: list<array<string, mixed>>}
     */
    private function buildRecentActivitiesSection(DateRange $dateRange): array
    {
        $activities = $this->filterActivitiesByDateRange($this->enrichedActivities->findAll(), $dateRange);

        $items = [];
        $summaryByActivityType = [];
        $totalDistanceInKilometer = 0.0;
        $totalMovingTimeInSeconds = 0;
        $totalTrainingLoad = 0;

        foreach ($activities as $activity) {
            $mappedActivity = $this->mapActivity($activity);
            $items[] = $mappedActivity;

            $activityType = $mappedActivity['activityType'];
            $summaryByActivityType[$activityType] ??= [
                'count' => 0,
                'distanceInKilometer' => 0.0,
                'movingTimeInSeconds' => 0,
                'trainingLoad' => 0,
            ];

            ++$summaryByActivityType[$activityType]['count'];
            $summaryByActivityType[$activityType]['distanceInKilometer'] += $mappedActivity['distanceInKilometer'];
            $summaryByActivityType[$activityType]['movingTimeInSeconds'] += $mappedActivity['movingTimeInSeconds'];
            $summaryByActivityType[$activityType]['trainingLoad'] += $mappedActivity['trainingLoad'];

            $totalDistanceInKilometer += $mappedActivity['distanceInKilometer'];
            $totalMovingTimeInSeconds += $mappedActivity['movingTimeInSeconds'];
            $totalTrainingLoad += $mappedActivity['trainingLoad'];
        }

        ksort($summaryByActivityType);

        return [
            'summary' => [
                'count' => count($items),
                'distanceInKilometer' => round($totalDistanceInKilometer, 2),
                'movingTimeInSeconds' => $totalMovingTimeInSeconds,
                'trainingLoad' => $totalTrainingLoad,
                'activityTypes' => array_map(
                    static fn (array $summary): array => [
                        'count' => $summary['count'],
                        'distanceInKilometer' => round($summary['distanceInKilometer'], 2),
                        'movingTimeInSeconds' => $summary['movingTimeInSeconds'],
                        'trainingLoad' => $summary['trainingLoad'],
                    ],
                    $summaryByActivityType,
                ),
                'mostRecentActivityStart' => $items[0]['startDateTime'] ?? null,
            ],
            'items' => $items,
        ];
    }

    private function filterActivitiesByDateRange(Activities $activities, DateRange $dateRange): Activities
    {
        $filtered = [];

        foreach ($activities as $activity) {
            if ($activity->getStartDate() < $dateRange->getFrom() || $activity->getStartDate() > $dateRange->getTill()) {
                continue;
            }

            $filtered[] = $activity;
        }

        return Activities::fromArray($filtered);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapActivity(Activity $activity): array
    {
        return [
            'id' => $activity->getId()->toUnprefixedString(),
            'startDateTime' => $activity->getStartDate()->format('Y-m-d H:i:s'),
            'sportType' => $activity->getSportType()->value,
            'activityType' => $activity->getSportType()->getActivityType()->value,
            'name' => $activity->getName(),
            'description' => $activity->getDescription(),
            'distanceInKilometer' => round($activity->getDistance()->toFloat(), 2),
            'elevationInMeter' => $activity->getElevation()->toInt(),
            'movingTimeInSeconds' => $activity->getMovingTimeInSeconds(),
            'trainingLoad' => $this->activityTrainingLoadCalculator->calculate($activity),
            'averageHeartRate' => $activity->getAverageHeartRate(),
            'averagePowerInWatts' => $activity->getAveragePower(),
            'normalizedPowerInWatts' => $activity->getNormalizedPower(),
            'averageSpeedInKilometersPerHour' => round($activity->getAverageSpeed()->toFloat(), 2),
            'gearName' => $activity->getGearName(),
            'workoutType' => $activity->getWorkoutType()?->value,
            'isCommute' => $activity->isCommute(),
        ];
    }

    private function buildWellnessMetricsResponse(DateRange $dateRange): FindWellnessMetricsResponse
    {
        $records = array_map(
            static fn (DailyWellness $record): array => [
                'day' => $record->getDay()->format('Y-m-d'),
                'stepsCount' => $record->getStepsCount(),
                'sleepDurationInSeconds' => $record->getSleepDurationInSeconds(),
                'sleepScore' => $record->getSleepScore(),
                'hrv' => $record->getHrv(),
            ],
            $this->dailyWellnessRepository->findByDateRange($dateRange, WellnessSource::GARMIN),
        );

        $lastRecord = [] === $records ? null : $records[array_key_last($records)];

        return new FindWellnessMetricsResponse(
            records: $records,
            latestDay: null === $lastRecord ? null : SerializableDateTime::fromString($lastRecord['day'])->setTime(0, 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTrainingMetricsSummary(TrainingMetrics $trainingMetrics): array
    {
        $currentTsb = $trainingMetrics->getCurrentTsb();
        $currentAcRatio = $trainingMetrics->getCurrentAcRatio();

        return [
            'atl' => $trainingMetrics->getCurrentAtl(),
            'ctl' => $trainingMetrics->getCurrentCtl(),
            'weeklyTrimp' => $trainingMetrics->getWeeklyTrimp(),
            'monotony' => $trainingMetrics->getCurrentMonotony(),
            'strain' => $trainingMetrics->getCurrentStrain(),
            'tsb' => null === $currentTsb ? null : [
                'value' => $currentTsb->getValue(),
                'status' => [
                    'key' => $currentTsb->getStatus()->name,
                    'label' => $currentTsb->getStatus()->trans($this->translator),
                    'description' => $currentTsb->getStatus()->transDescription($this->translator),
                    'range' => $currentTsb->getStatus()->getRange(),
                ],
            ],
            'acRatio' => null === $currentAcRatio ? null : [
                'value' => $currentAcRatio->getValue(),
                'status' => [
                    'key' => $currentAcRatio->getStatus()->name,
                    'label' => $currentAcRatio->getStatus()->trans($this->translator),
                    'description' => $currentAcRatio->getStatus()->transDescription($this->translator),
                    'range' => $currentAcRatio->getStatus()->getRange(),
                ],
            ],
        ];
    }

    /**
     * @param array<string, int> $integratedLoads
     *
    * @return list<array<string, int|float|null|string|array<string, mixed>>>
     */
    private function buildRecentTrainingMetricsTimeline(TrainingMetrics $trainingMetrics, array $integratedLoads): array
    {
        $days = array_slice(array_keys($integratedLoads), -self::RECENT_TRAINING_METRICS_DAYS);
        $atlValues = $trainingMetrics->getAtlValues();
        $ctlValues = $trainingMetrics->getCtlValues();
        $tsbValues = $trainingMetrics->getTsbValues();
        $monotonyValues = $trainingMetrics->getMonotonyValues();
        $strainValues = $trainingMetrics->getStrainValues();
        $acRatioValues = $trainingMetrics->getAcRatioValues();

        $timeline = [];
        foreach ($days as $day) {
            $tsbValue = $tsbValues[$day] ?? null;
            $acRatioValue = $acRatioValues[$day] ?? null;

            $timeline[] = [
                'day' => $day,
                'integratedLoad' => $integratedLoads[$day] ?? null,
                'atl' => $atlValues[$day] ?? null,
                'ctl' => $ctlValues[$day] ?? null,
                'tsb' => null === $tsbValue ? null : [
                    'value' => $tsbValue,
                    'status' => $this->translateTsbStatus($tsbValue),
                ],
                'monotony' => $monotonyValues[$day] ?? null,
                'strain' => $strainValues[$day] ?? null,
                'acRatio' => null === $acRatioValue ? null : [
                    'value' => $acRatioValue,
                    'status' => $this->translateAcRatioStatus($acRatioValue),
                ],
            ];
        }

        return $timeline;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildReadinessSummary(?ReadinessAssessment $readinessAssessment): ?array
    {
        if (null === $readinessAssessment) {
            return null;
        }

        $score = $readinessAssessment->getScore();

        return [
            'score' => $score->getValue(),
            'status' => [
                'key' => $score->getStatus()->name,
                'label' => $score->getStatus()->trans($this->translator),
                'description' => $score->getStatus()->transDescription($this->translator),
                'range' => $score->getStatus()->getRange(),
            ],
            'topPositiveFactors' => array_map($this->mapReadinessFactor(...), $readinessAssessment->getTopPositiveFactors(3)),
            'topNegativeFactors' => array_map($this->mapReadinessFactor(...), $readinessAssessment->getTopNegativeFactors(3)),
            'allFactors' => array_map($this->mapReadinessFactor(...), $readinessAssessment->getFactors()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapReadinessFactor(ReadinessFactor $factor): array
    {
        return [
            'key' => $factor->getKey(),
            'label' => $factor->getLabel(),
            'value' => $factor->getValue(),
            'highlightable' => $factor->isHighlightable(),
        ];
    }

    /**
     * @return array<string, float|null>
     */
    private function buildWellnessBaseline(FindWellnessMetricsResponse $wellnessMetrics): array
    {
        $baselineRecords = count($wellnessMetrics->getRecords()) > 1
            ? array_slice($wellnessMetrics->getRecords(), 0, -1)
            : $wellnessMetrics->getRecords();

        return [
            'stepsCount' => $this->averageMetric($baselineRecords, 'stepsCount'),
            'sleepDurationInSeconds' => $this->averageMetric($baselineRecords, 'sleepDurationInSeconds'),
            'sleepScore' => $this->averageMetric($baselineRecords, 'sleepScore'),
            'hrv' => $this->averageMetric($baselineRecords, 'hrv'),
        ];
    }

    /**
     * @param list<array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}> $records
     */
    private function averageMetric(array $records, string $key): ?float
    {
        $values = array_values(array_filter(
            array_map(static fn (array $record): int|float|null => $record[$key], $records),
            static fn (int|float|null $value): bool => null !== $value,
        ));

        if ([] === $values) {
            return null;
        }

        return round(array_sum($values) / count($values), 2);
    }

    /**
     * @param list<DailyRecoveryCheckIn> $recentRecoveryCheckIns
     *
     * @return array{day: string, fatigue: int, soreness: int, stress: int, motivation: int, sleepQuality: int}|null
     */
    private function latestRecoveryCheckInForToday(array $recentRecoveryCheckIns, SerializableDateTime $today): ?array
    {
        $latest = end($recentRecoveryCheckIns);
        if (!$latest instanceof DailyRecoveryCheckIn) {
            return null;
        }

        $mapped = $this->mapRecoveryCheckIn($latest);

        return $mapped['day'] === $today->format('Y-m-d') ? $mapped : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapRecoveryCheckIn(?DailyRecoveryCheckIn $checkIn): ?array
    {
        if (!$checkIn instanceof DailyRecoveryCheckIn) {
            return null;
        }

        return [
            'day' => $checkIn->getDay()->format('Y-m-d'),
            'fatigue' => $checkIn->getFatigue(),
            'soreness' => $checkIn->getSoreness(),
            'stress' => $checkIn->getStress(),
            'motivation' => $checkIn->getMotivation(),
            'sleepQuality' => $checkIn->getSleepQuality(),
            'recordedAt' => $checkIn->getRecordedAt()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<int, float> $projectedLoads
     *
     * @return list<array{dayOffset: int, day: string, projectedLoad: float}>
     */
    private function buildProjectedLoadsByDayOffset(SerializableDateTime $now, array $projectedLoads): array
    {
        $rows = [];

        foreach ($projectedLoads as $dayOffset => $projectedLoad) {
            $rows[] = [
                'dayOffset' => $dayOffset,
                'day' => $now->modify(sprintf('+ %d days', $dayOffset))->format('Y-m-d'),
                'projectedLoad' => round($projectedLoad, 1),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildForecastProjection(TrainingLoadForecastProjection $projection): array
    {
        return array_map(
            fn (array $row): array => [
                'day' => $row['day']->format('Y-m-d'),
                'projectedLoad' => $row['projectedLoad'],
                'tsb' => [
                    'value' => $row['tsb']->getValue(),
                    'status' => [
                        'key' => $row['tsb']->getStatus()->name,
                        'label' => $row['tsb']->getStatus()->trans($this->translator),
                        'description' => $row['tsb']->getStatus()->transDescription($this->translator),
                    ],
                ],
                'acRatio' => [
                    'value' => $row['acRatio']->getValue(),
                    'status' => [
                        'key' => $row['acRatio']->getStatus()->name,
                        'label' => $row['acRatio']->getStatus()->trans($this->translator),
                        'description' => $row['acRatio']->getStatus()->transDescription($this->translator),
                    ],
                ],
            ],
            $projection->getProjection(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function mapPlannedSession(PlannedSession $plannedSession): array
    {
        $estimate = $this->plannedSessionLoadEstimator->estimate($plannedSession);

        return [
            'id' => (string) $plannedSession->getId(),
            'day' => $plannedSession->getDay()->format('Y-m-d'),
            'activityType' => $plannedSession->getActivityType()->value,
            'title' => $plannedSession->getTitle(),
            'notes' => $plannedSession->getNotes(),
            'targetLoad' => $plannedSession->getTargetLoad(),
            'targetDurationInSeconds' => $plannedSession->getTargetDurationInSeconds(),
            'targetIntensity' => $plannedSession->getTargetIntensity()?->value,
            'templateActivityId' => $plannedSession->getTemplateActivityId()?->toUnprefixedString(),
            'linkStatus' => $plannedSession->getLinkStatus()->value,
            'linkedActivityId' => $plannedSession->getLinkedActivityId()?->toUnprefixedString(),
            'estimation' => $this->mapPlannedSessionEstimate($estimate),
            'workoutSteps' => $plannedSession->getWorkoutSteps(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapPlannedSessionEstimate(?PlannedSessionLoadEstimate $estimate): ?array
    {
        if (!$estimate instanceof PlannedSessionLoadEstimate) {
            return null;
        }

        return [
            'estimatedLoad' => $estimate->getEstimatedLoad(),
            'source' => $estimate->getEstimationSource()->value,
            'sourceLabel' => $estimate->getEstimationSource()->getLabel(),
        ];
    }

    /**
     * @return array{key: string, label: string, description: string, range?: string}
     */
    private function translateTsbStatus(float $value): array
    {
        $status = TSB::of($value)->getStatus();

        return [
            'key' => $status->name,
            'label' => $status->trans($this->translator),
            'description' => $status->transDescription($this->translator),
            'range' => $status->getRange(),
        ];
    }

    /**
     * @return array{key: string, label: string, description: string, range?: string}
     */
    private function translateAcRatioStatus(float $value): array
    {
        $status = AcRatio::of($value)->getStatus();

        return [
            'key' => $status->name,
            'label' => $status->trans($this->translator),
            'description' => $status->transDescription($this->translator),
            'range' => $status->getRange(),
        ];
    }
}
