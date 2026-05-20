<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityType;
use App\Domain\Calendar\Week;
use App\Domain\Dashboard\Widget\TrainingLoad\IntegratedDailyLoadCalculator;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingLoadForecastProjection;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingMetrics;
use App\Domain\Dashboard\Widget\TrainingLoad\WellnessReadinessCalculator;
use App\Domain\Dashboard\Widget\Wellness\FindDailyRecoveryCheckIns\FindDailyRecoveryCheckIns;
use App\Domain\Dashboard\Widget\Wellness\FindDailyRecoveryCheckIns\FindDailyRecoveryCheckInsResponse;
use App\Domain\Dashboard\Widget\Wellness\FindWellnessMetrics\FindWellnessMetrics;
use App\Domain\Dashboard\Widget\Wellness\FindWellnessMetrics\FindWellnessMetricsResponse;
use App\Domain\Wellness\WellnessSource;
use App\Infrastructure\CQRS\Query\Bus\QueryBus;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class AdaptivePlanningContextBuilder
{
    private const int RECENT_ACTIVITY_DAYS = 42;
    private const int RECENT_WELLNESS_DAYS = 30;
    private const int FORECAST_HORIZON_DAYS = 7;
    private const int TRAINING_METRICS_WARMUP_DAYS = 120;

    public function __construct(
        private ActivityRepository $activityRepository,
        private IntegratedDailyLoadCalculator $integratedDailyLoadCalculator,
        private QueryBus $queryBus,
        private WellnessReadinessCalculator $wellnessReadinessCalculator,
        private CurrentTrainingBlockResolver $currentTrainingBlockResolver,
        private PlannedSessionEstimatedLoadMapBuilder $plannedSessionEstimatedLoadMapBuilder,
        private RaceEventsByIdMapBuilder $raceEventsByIdMapBuilder,
        private PlannedSessionForecastBuilder $plannedSessionForecastBuilder,
        private RaceReadinessContextBuilder $raceReadinessContextBuilder,
    ) {
    }

    /**
     * @param list<PlannedSession> $plannedSessions
     * @param list<RaceEvent>      $raceEvents
     * @param list<TrainingBlock>  $trainingBlocks
     */
    public function build(
        SerializableDateTime $referenceDate,
        array $plannedSessions,
        array $raceEvents,
        array $trainingBlocks,
    ): AdaptivePlanningContext {
        $today = $referenceDate->setTime(0, 0);
        $activityIndex = TrainingPlannerActivityIndex::fromActivities($this->activityRepository->findAll());
        $trainingMetrics = $this->buildTrainingMetrics($referenceDate);
        $wellnessMetrics = $this->buildWellnessMetrics($today);
        $recoveryCheckIns = $this->buildRecoveryCheckIns($today);
        $readinessAssessment = $this->wellnessReadinessCalculator->assess(
            $trainingMetrics,
            $wellnessMetrics,
            $this->latestRecoveryCheckInForToday($recoveryCheckIns->getRecords(), $today),
        );
        $plannedSessionForecast = $this->plannedSessionForecastBuilder->build($referenceDate, self::FORECAST_HORIZON_DAYS);
        $forecastProjection = TrainingLoadForecastProjection::createWithProjectedLoads(
            metrics: $trainingMetrics,
            now: $referenceDate,
            projectedLoads: $plannedSessionForecast->getProjectedLoads(),
            horizon: self::FORECAST_HORIZON_DAYS,
            currentDayProjectedLoad: $plannedSessionForecast->getCurrentDayProjectedLoad(),
        );

        return new AdaptivePlanningContext(
            currentWeekReadinessContext: $this->raceReadinessContextBuilder->build(
                referenceDate: $referenceDate,
                plannedSessions: $this->findSessionsInCurrentWeek($plannedSessions, $referenceDate),
                raceEvents: $this->findRacesInCurrentWeek($raceEvents, $referenceDate),
                trainingBlocks: $this->findBlocksInCurrentWeek($trainingBlocks, $referenceDate),
                currentTrainingBlock: $this->currentTrainingBlockResolver->findCurrent($trainingBlocks, $referenceDate),
                raceEventsById: $this->raceEventsByIdMapBuilder->build($raceEvents),
                plannedSessionEstimatesById: $this->plannedSessionEstimatedLoadMapBuilder->build($this->findSessionsInCurrentWeek($plannedSessions, $referenceDate)),
                readinessScore: $readinessAssessment?->getScore(),
                forecastProjection: $forecastProjection,
            ),
            historicalWeeklyRunningVolume: $this->buildHistoricalWeeklyVolume($activityIndex, $today, ActivityType::RUN),
            historicalWeeklyBikingVolume: $this->buildHistoricalWeeklyVolume($activityIndex, $today, ActivityType::RIDE),
        );
    }

    private function buildTrainingMetrics(SerializableDateTime $referenceDate): TrainingMetrics
    {
        $trainingMetricsRange = DateRange::fromDates(
            SerializableDateTime::fromString($referenceDate->modify(sprintf('-%d days', self::TRAINING_METRICS_WARMUP_DAYS))->format('Y-m-d 00:00:00')),
            SerializableDateTime::fromString($referenceDate->format('Y-m-d 23:59:59')),
        );

        return TrainingMetrics::create($this->integratedDailyLoadCalculator->calculateForDateRange($trainingMetricsRange));
    }

    private function buildWellnessMetrics(SerializableDateTime $today): FindWellnessMetricsResponse
    {
        /** @var FindWellnessMetricsResponse $response */
        $response = $this->queryBus->ask(new FindWellnessMetrics(
            dateRange: DateRange::fromDates(
                $today->modify(sprintf('-%d days', self::RECENT_WELLNESS_DAYS - 1)),
                $today->setTime(23, 59, 59),
            ),
            source: WellnessSource::GARMIN,
        ));

        return $response;
    }

    private function buildRecoveryCheckIns(SerializableDateTime $today): FindDailyRecoveryCheckInsResponse
    {
        /** @var FindDailyRecoveryCheckInsResponse $response */
        $response = $this->queryBus->ask(new FindDailyRecoveryCheckIns(
            dateRange: DateRange::fromDates(
                $today->modify(sprintf('-%d days', self::RECENT_WELLNESS_DAYS - 1)),
                $today->setTime(23, 59, 59),
            ),
        ));

        return $response;
    }

    /**
     * @param list<PlannedSession> $plannedSessions
     *
     * @return list<PlannedSession>
     */
    private function findSessionsInCurrentWeek(array $plannedSessions, SerializableDateTime $referenceDate): array
    {
        $week = Week::fromDate($referenceDate);

        return array_values(array_filter(
            $plannedSessions,
            static fn (PlannedSession $plannedSession): bool => $plannedSession->getDay() >= $week->getFrom() && $plannedSession->getDay() <= $week->getTo(),
        ));
    }

    /**
     * @param list<TrainingBlock> $trainingBlocks
     *
     * @return list<TrainingBlock>
     */
    private function findBlocksInCurrentWeek(array $trainingBlocks, SerializableDateTime $referenceDate): array
    {
        $week = Week::fromDate($referenceDate);

        return array_values(array_filter(
            $trainingBlocks,
            static fn (TrainingBlock $trainingBlock): bool => $trainingBlock->getStartDay() <= $week->getTo() && $trainingBlock->getEndDay() >= $week->getFrom(),
        ));
    }

    /**
     * @param list<RaceEvent> $raceEvents
     *
     * @return list<RaceEvent>
     */
    private function findRacesInCurrentWeek(array $raceEvents, SerializableDateTime $referenceDate): array
    {
        $week = Week::fromDate($referenceDate);

        return array_values(array_filter(
            $raceEvents,
            static fn (RaceEvent $raceEvent): bool => $raceEvent->getDay() >= $week->getFrom() && $raceEvent->getDay() <= $week->getTo(),
        ));
    }

    private function buildHistoricalWeeklyVolume(TrainingPlannerActivityIndex $activityIndex, SerializableDateTime $today, ActivityType $activityType): ?float
    {
        $dateRange = DateRange::fromDates(
            $today->modify(sprintf('-%d days', self::RECENT_ACTIVITY_DAYS - 1))->setTime(0, 0),
            $today->setTime(23, 59, 59),
        );
        $volume = 0.0;

        foreach ($activityIndex->byDateRangeAndActivityType($dateRange, $activityType) as $activity) {
            $volume += match ($activityType) {
                ActivityType::RUN => $activity->getDistance()->toFloat(),
                ActivityType::RIDE => $activity->getMovingTimeInSeconds() / 3600,
                default => 0.0,
            };
        }

        if ($volume <= 0.0) {
            return null;
        }

        return round($volume / max(1.0, self::RECENT_ACTIVITY_DAYS / 7), 1);
    }

    /**
     * @param list<array{day: string, fatigue: int, soreness: int, stress: int, motivation: int, sleepQuality: int}> $recoveryCheckIns
     *
     * @return array{day: string, fatigue: int, soreness: int, stress: int, motivation: int, sleepQuality: int}|null
     */
    private function latestRecoveryCheckInForToday(array $recoveryCheckIns, SerializableDateTime $today): ?array
    {
        $latestRecoveryCheckIn = end($recoveryCheckIns);
        if (!is_array($latestRecoveryCheckIn)) {
            return null;
        }

        return $latestRecoveryCheckIn['day'] === $today->format('Y-m-d')
            ? $latestRecoveryCheckIn
            : null;
    }
}
