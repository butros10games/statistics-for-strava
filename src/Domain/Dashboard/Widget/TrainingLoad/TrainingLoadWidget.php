<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

use App\Domain\Activity\ActivityTrainingLoadCalculator;
use App\Domain\Activity\ActivityType;
use App\Domain\Activity\EnrichedActivities;
use App\Domain\Activity\Stream\ActivityHeartRateRepository;
use App\Domain\Dashboard\Widget\Wellness\FindDailyRecoveryCheckIns\FindDailyRecoveryCheckInsResponse;
use App\Domain\Dashboard\Widget\Wellness\FindWellnessMetrics\FindWellnessMetricsResponse;
use App\Domain\Dashboard\Widget\TrainingLoad\FindNumberOfRestDays\FindNumberOfRestDays;
use App\Domain\Dashboard\Widget\Widget;
use App\Domain\Dashboard\Widget\WidgetConfiguration;
use App\Domain\TrainingPlanner\PlannedSessionForecastBuilder;
use App\Infrastructure\CQRS\Query\Bus\QueryBus;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use League\Flysystem\FilesystemOperator;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final readonly class TrainingLoadWidget implements Widget
{
    public function __construct(
        private ActivityHeartRateRepository $activityHeartRateRepository,
        private EnrichedActivities $enrichedActivities,
        private ActivityTrainingLoadCalculator $activityTrainingLoadCalculator,
        private TrainingLoadAnalyticsContextBuilder $trainingLoadAnalyticsContextBuilder,
        private QueryBus $queryBus,
        private FilesystemOperator $buildStorage,
        private Environment $twig,
        private TranslatorInterface $translator,
        private Clock $clock,
        private WellnessReadinessCalculator $wellnessReadinessCalculator,
        private ReadinessMismatchAnalyzer $readinessMismatchAnalyzer,
        private RecoveryTrendAnalyzer $recoveryTrendAnalyzer,
        private TrainingLoadCorrelationInsightsAnalyzer $trainingLoadCorrelationInsightsAnalyzer,
        private ActivityTypeRecoveryFingerprintAnalyzer $activityTypeRecoveryFingerprintAnalyzer,
        private TrainingLoadPersonalizationAdvisor $trainingLoadPersonalizationAdvisor,
        private PlannedSessionForecastBuilder $plannedSessionForecastBuilder,
    ) {
    }

    public function guardValidConfiguration(WidgetConfiguration $configuration): void
    {
    }

    public function getDefaultConfiguration(): WidgetConfiguration
    {
        return WidgetConfiguration::empty();
    }

    public function render(SerializableDateTime $now, WidgetConfiguration $configuration): string
    {
        $timeInHeartRateZonesForLast30Days = $this->activityHeartRateRepository->findTotalTimeInSecondsInHeartRateZonesForLast30Days();

        $loadCalculationFrom = SerializableDateTime::fromString(
            $now->modify('- '.(TrainingLoadChart::NUMBER_OF_DAYS_TO_DISPLAY + 210).' days')->format('Y-m-d 00:00:00')
        );
        $analyticsContext = $this->trainingLoadAnalyticsContextBuilder->build(DateRange::fromDates(
            from: $loadCalculationFrom,
            till: SerializableDateTime::fromString($now->format('Y-m-d 23:59:59')),
        ));

        $trainingMetrics = $analyticsContext->getTrainingMetrics();
        $allWellnessMetrics = $analyticsContext->getWellnessMetrics();
        $allRecoveryCheckIns = $analyticsContext->getRecoveryCheckIns();

        $wellnessMetrics = $this->filterWellnessMetricsByDateRange(
            $allWellnessMetrics,
            SerializableDateTime::fromString($now->modify('-29 days')->format('Y-m-d 00:00:00')),
        );

        $latestRecoveryCheckInOverall = $allRecoveryCheckIns->getLatestRecord();
        $latestRecoveryCheckIn = $this->latestRecoveryCheckInForToday($allRecoveryCheckIns, $now);

        $baseReadinessAssessment = $this->wellnessReadinessCalculator->assess($trainingMetrics, $wellnessMetrics, $latestRecoveryCheckIn);
        $baseReadinessScore = $baseReadinessAssessment?->getScore();
        $latestWellnessRecord = $wellnessMetrics->getLatestRecord();
        $wellnessBaselineRecords = count($wellnessMetrics->getRecords()) > 1 ? array_slice($wellnessMetrics->getRecords(), 0, -1) : $wellnessMetrics->getRecords();

        $correlationInsights = $this->trainingLoadCorrelationInsightsAnalyzer->analyze($analyticsContext, 90);
        $activityDaySamples = $this->buildActivityDaySamples($analyticsContext, $now);
        $activityTypeRecoveryFingerprints = $this->activityTypeRecoveryFingerprintAnalyzer->analyze($activityDaySamples);
        $trainingLoadPersonalization = $this->trainingLoadPersonalizationAdvisor->build(
            analyticsContext: $analyticsContext,
            correlationInsights: $correlationInsights,
            activityTypeRecoveryFingerprints: $activityTypeRecoveryFingerprints,
            recentActivityDaySamples: array_slice($activityDaySamples, -7),
        );
        $readinessScore = null === $baseReadinessScore ? null : ReadinessScore::of(
            max(0, min(100, $baseReadinessScore->getValue() + $trainingLoadPersonalization->getReadinessAdjustment()))
        );
        $readinessAssessment = null === $baseReadinessAssessment || null === $readinessScore
            ? null
            : $baseReadinessAssessment->withScore($readinessScore);
        if (null !== $readinessAssessment && 0 !== $trainingLoadPersonalization->getReadinessAdjustment()) {
            $readinessAssessment = $readinessAssessment->withFactor(ReadinessFactor::create(
                ReadinessFactor::KEY_PERSONALIZATION,
                'Personalized recovery lens',
                (float) $trainingLoadPersonalization->getReadinessAdjustment(),
                false,
            ));
        }
        $readinessMismatchInsight = $this->readinessMismatchAnalyzer->analyze($readinessAssessment, $latestRecoveryCheckIn);
        $recoveryTrendWarnings = $this->recoveryTrendAnalyzer->analyze($analyticsContext, $readinessScore);

        $projectionNow = $this->clock->getCurrentDateTimeImmutable();
        $forecastHorizon = 7;

        $trainingLoadForecast = TrainingLoadForecastProjection::create(
            metrics: $trainingMetrics,
            now: $projectionNow,
            loadFactor: $trainingLoadPersonalization->getForecastLoadFactor(),
            horizon: $forecastHorizon,
        );
        $trainingLoadForecastScenarios = array_map(
            fn (TrainingLoadForecastScenario $scenario): array => [
                'key' => $scenario->value,
                'label' => $scenario->getLabel(),
                'description' => $scenario->getDescription(),
                'forecast' => TrainingLoadForecastProjection::create(
                    metrics: $trainingMetrics,
                    now: $projectionNow,
                    scenario: $scenario,
                    loadFactor: $trainingLoadPersonalization->getForecastLoadFactor(),
                    horizon: $forecastHorizon,
                ),
            ],
            TrainingLoadForecastScenario::cases(),
        );
        $plannedSessionForecast = $this->plannedSessionForecastBuilder->build($projectionNow, $forecastHorizon);
        $plannedSessionForecastProjection = $plannedSessionForecast->hasEstimates()
            ? TrainingLoadForecastProjection::createWithProjectedLoads(
                metrics: $trainingMetrics,
                now: $projectionNow,
                projectedLoads: $plannedSessionForecast->getProjectedLoads(),
                horizon: $forecastHorizon,
                currentDayProjectedLoad: $plannedSessionForecast->getCurrentDayProjectedLoad(),
            )
            : null;

        if (null !== $plannedSessionForecastProjection) {
            array_unshift($trainingLoadForecastScenarios, [
                'key' => 'planned-sessions',
                'label' => 'Planned sessions',
                'description' => 'Projects the next 7 days using the sessions currently saved in your planner.',
                'forecast' => $plannedSessionForecastProjection,
            ]);
        }

        $numberOfRestDays = $this->queryBus->ask(new FindNumberOfRestDays(DateRange::fromDates(
            from: $now->modify('-6 days'),
            till: $now,
        )))->getNumberOfRestDays();

        $this->buildStorage->write(
            'training-load.html',
            $this->twig->render('html/dashboard/training-load.html.twig', [
                'trainingLoadChart' => Json::encode(
                    TrainingLoadChart::create(
                        trainingMetrics: $trainingMetrics,
                        now: $now,
                        translator: $this->translator,
                        plannedSessionForecastProjection: $plannedSessionForecastProjection,
                    )->build()
                ),
                'trainingMetrics' => $trainingMetrics,
                'readinessScore' => $readinessScore,
                'readinessAssessment' => $readinessAssessment,
                'readinessMismatchInsight' => $readinessMismatchInsight,
                'recoveryTrendWarnings' => $recoveryTrendWarnings,
                'latestWellnessHrv' => $latestWellnessRecord['hrv'] ?? null,
                'wellnessHrvBaseline' => $this->averageWellnessMetric($wellnessBaselineRecords, 'hrv'),
                'latestWellnessSleepScore' => $latestWellnessRecord['sleepScore'] ?? null,
                'trainingLoadForecast' => $trainingLoadForecast,
                'trainingLoadForecastScenarios' => $trainingLoadForecastScenarios,
                'plannedSessionForecast' => $plannedSessionForecast,
                'plannedSessionForecastProjection' => $plannedSessionForecastProjection,
                'correlationInsights' => $correlationInsights,
                'activityTypeRecoveryFingerprints' => $activityTypeRecoveryFingerprints,
                'trainingLoadPersonalization' => $trainingLoadPersonalization,
                'latestRecoveryCheckIn' => $latestRecoveryCheckIn,
                'latestRecoveryCheckInOverall' => $latestRecoveryCheckInOverall,
                'shouldPromptRecoveryCheckIn' => null === $latestRecoveryCheckIn,
                'restDaysInLast7Days' => $numberOfRestDays,
                'timeInHeartRateZonesForLast30Days' => $timeInHeartRateZonesForLast30Days,
            ])
        );

        return $this->twig->load('html/dashboard/widget/widget--training-load.html.twig')->render([
            'timeInHeartRateZonesForLast30Days' => $timeInHeartRateZonesForLast30Days,
            'trainingMetrics' => $trainingMetrics,
            'readinessScore' => $readinessScore,
            'readinessAssessment' => $readinessAssessment,
            'readinessMismatchInsight' => $readinessMismatchInsight,
            'latestWellnessHrv' => $latestWellnessRecord['hrv'] ?? null,
            'wellnessHrvBaseline' => $this->averageWellnessMetric($wellnessBaselineRecords, 'hrv'),
            'latestWellnessSleepScore' => $latestWellnessRecord['sleepScore'] ?? null,
            'recoveryTrendWarnings' => $recoveryTrendWarnings,
            'correlationInsights' => $correlationInsights,
            'activityTypeRecoveryFingerprints' => $activityTypeRecoveryFingerprints,
            'trainingLoadPersonalization' => $trainingLoadPersonalization,
            'latestRecoveryCheckIn' => $latestRecoveryCheckIn,
            'latestRecoveryCheckInOverall' => $latestRecoveryCheckInOverall,
            'shouldPromptRecoveryCheckIn' => null === $latestRecoveryCheckIn,
            'restDaysInLast7Days' => $numberOfRestDays,
        ]);
    }

    /**
     * @param list<array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}> $records
     */
    private function averageWellnessMetric(array $records, string $field): ?float
    {
        $values = array_values(array_filter(
            array_map(static fn (array $record): int|float|null => $record[$field], $records),
            static fn (int|float|null $value): bool => null !== $value,
        ));

        if ([] === $values) {
            return null;
        }

        return array_sum($values) / count($values);
    }

    private function filterWellnessMetricsByDateRange(FindWellnessMetricsResponse $response, SerializableDateTime $from): FindWellnessMetricsResponse
    {
        $filteredRecords = array_values(array_filter(
            $response->getRecords(),
            static fn (array $record): bool => $record['day'] >= $from->format('Y-m-d'),
        ));

        $lastRecord = [] === $filteredRecords ? null : $filteredRecords[array_key_last($filteredRecords)];

        return new FindWellnessMetricsResponse(
            records: $filteredRecords,
            latestDay: null === $lastRecord ? null : SerializableDateTime::fromString($lastRecord['day'])->setTime(0, 0),
        );
    }

    /**
     * @return array{day: string, fatigue: int, soreness: int, stress: int, motivation: int, sleepQuality: int}|null
     */
    private function latestRecoveryCheckInForToday(FindDailyRecoveryCheckInsResponse $response, SerializableDateTime $now): ?array
    {
        $latestRecord = $response->getLatestRecord();
        if (null === $latestRecord) {
            return null;
        }

        return $latestRecord['day'] === $now->format('Y-m-d') ? $latestRecord : null;
    }

    /**
     * @return list<array{day: string, activityType: ActivityType, load: int, nextDayHrv: ?float, nextDaySleepScore: ?int, nextDayFatigue: ?int}>
     */
    private function buildActivityDaySamples(TrainingLoadAnalyticsContext $analyticsContext, SerializableDateTime $now): array
    {
        $fromDay = $now->modify('-89 days')->format('Y-m-d');
        $toDay = $now->format('Y-m-d');

        $rowsByDay = [];
        foreach ($analyticsContext->getRows() as $row) {
            $rowsByDay[$row['day']] = $row;
        }

        $dominantActivityPerDay = [];
        $totalLoadPerDay = [];
        foreach ($this->enrichedActivities->findAll() as $activity) {
            $day = $activity->getStartDate()->format('Y-m-d');
            if ($day < $fromDay || $day > $toDay) {
                continue;
            }

            $load = $this->activityTrainingLoadCalculator->calculate($activity);
            if ($load <= 0) {
                continue;
            }

            $totalLoadPerDay[$day] = ($totalLoadPerDay[$day] ?? 0) + $load;
            if (!isset($dominantActivityPerDay[$day]) || $load > $dominantActivityPerDay[$day]['load']) {
                $dominantActivityPerDay[$day] = [
                    'activityType' => $activity->getSportType()->getActivityType(),
                    'load' => $load,
                ];
            }
        }

        ksort($dominantActivityPerDay);

        $samples = [];
        foreach ($dominantActivityPerDay as $day => $dominantActivity) {
            $nextDay = SerializableDateTime::fromString($day.' 00:00:00')->modify('+1 day')->format('Y-m-d');
            $nextDayRow = $rowsByDay[$nextDay] ?? null;
            if (null === $nextDayRow) {
                continue;
            }

            $samples[] = [
                'day' => $day,
                'activityType' => $dominantActivity['activityType'],
                'load' => $totalLoadPerDay[$day] ?? $dominantActivity['load'],
                'nextDayHrv' => $nextDayRow['wellness']['hrv'] ?? null,
                'nextDaySleepScore' => $nextDayRow['wellness']['sleepScore'] ?? null,
                'nextDayFatigue' => $nextDayRow['recoveryCheckIn']['fatigue'] ?? null,
            ];
        }

        return $samples;
    }

}
