<?php

declare(strict_types=1);

namespace App\Console;

use App\Domain\Activity\Activities;
use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Activity\DailyTrainingLoad;
use App\Domain\Dashboard\Widget\TrainingLoad\IntegratedDailyLoadCalculator;
use App\Domain\Dashboard\Widget\TrainingLoad\ReadinessAssessment;
use App\Domain\Dashboard\Widget\TrainingLoad\ReadinessFactor;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingMetrics;
use App\Domain\Dashboard\Widget\TrainingLoad\WellnessReadinessCalculator;
use App\Domain\Dashboard\Widget\Wellness\FindWellnessMetrics\FindWellnessMetricsResponse;
use App\Domain\Performance\PerformanceAnchor\PerformanceAnchorHistory;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionLoadEstimator;
use App\Domain\TrainingPlanner\PlannedSessionRepository;
use App\Domain\Athlete\AthleteRepository;
use App\Domain\Wellness\DailyRecoveryCheckIn;
use App\Domain\Wellness\DailyRecoveryCheckInRepository;
use App\Domain\Wellness\DailyWellness;
use App\Domain\Wellness\DailyWellnessRepository;
use App\Domain\Wellness\WellnessSource;
use App\Infrastructure\Console\ProvideConsoleIntro;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:training:backtest', description: 'Run a historical pilot backtest for readiness and report forecast-data gaps')]
final class BacktestTrainingModelsConsoleCommand extends Command
{
    use ProvideConsoleIntro;

    private const int READINESS_WELLNESS_WINDOW_DAYS = 30;
    private const int TRAINING_METRICS_WARMUP_DAYS = 252;
    private const int PLANNER_FORECAST_HORIZON_DAYS = 7;

    public function __construct(
        private Connection $connection,
        private ActivityRepository $activityRepository,
        private AthleteRepository $athleteRepository,
        private PerformanceAnchorHistory $performanceAnchorHistory,
        private PlannedSessionRepository $plannedSessionRepository,
        private DailyTrainingLoad $dailyTrainingLoad,
        private IntegratedDailyLoadCalculator $integratedDailyLoadCalculator,
        private DailyWellnessRepository $dailyWellnessRepository,
        private DailyRecoveryCheckInRepository $dailyRecoveryCheckInRepository,
        private WellnessReadinessCalculator $wellnessReadinessCalculator,
        private Clock $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->outputConsoleIntro($io);

        $today = SerializableDateTime::fromString(
            $this->clock->getCurrentDateTimeImmutable()->format('Y-m-d 00:00:00')
        );

        $coverage = $this->collectCoverage($today);
        $plannerBacktest = $this->runPlannerForecastBacktest($today);

        $io->title('Training model backtest');
        $io->section('Data coverage');
        $io->table(
            ['Metric', 'Value', 'Notes'],
            [
                ['Activities', (string) $coverage['activityCount'], 'Imported activities available for replay'],
                ['Wellness days', (string) $coverage['wellnessCount'], 'GARMIN wellness rows available'],
                ['Recovery check-ins', (string) $coverage['recoveryCheckInCount'], 'Direct subjective labels available'],
                ['Historical planned sessions', (string) $coverage['historicalPlannedSessionCount'], 'Planner rows dated before today'],
                ['Total planned sessions', (string) $coverage['plannedSessionCount'], $coverage['plannedSessionRange']],
                ['Reconstructable forecast windows', (string) $plannerBacktest['windowCount'], sprintf('Full %d-day windows with no planner time-travel leakage', self::PLANNER_FORECAST_HORIZON_DAYS)],
                ['Activities with normalized power', (string) $coverage['normalizedPowerActivityCount'], 'Needed to replay the power-based load branch historically'],
            ],
        );

        $rows = $this->runReadinessPilotBacktest();
        $maxAbsStrainFactor = [] === $rows
            ? 0.0
            : max(array_map(static fn (array $row): float => abs($row['strainFactor']), $rows));
        if ([] === $rows) {
            $io->warning('No readiness evaluation rows could be built from the current data.');
        } else {
            $metrics = $this->calculateBacktestMetrics($rows);

            $io->section('Readiness pilot backtest');
            $io->text([
                'Target: next-day subjective readiness score derived from the following day\'s recovery check-in.',
                'Baseline: legacy readiness variant without the new strain factor.',
            ]);

            $io->table(
                ['Metric', 'Current model', 'Legacy variant', 'Delta'],
                [
                    [
                        'MAE',
                        number_format($metrics['currentMae'], 2),
                        number_format($metrics['legacyMae'], 2),
                        $this->formatSignedDelta($metrics['currentMae'] - $metrics['legacyMae'], lowerIsBetter: true),
                    ],
                    [
                        'RMSE',
                        number_format($metrics['currentRmse'], 2),
                        number_format($metrics['legacyRmse'], 2),
                        $this->formatSignedDelta($metrics['currentRmse'] - $metrics['legacyRmse'], lowerIsBetter: true),
                    ],
                    [
                        'Pearson r',
                        $this->formatNullableFloat($metrics['currentCorrelation']),
                        $this->formatNullableFloat($metrics['legacyCorrelation']),
                        $this->formatCorrelationDelta($metrics['currentCorrelation'], $metrics['legacyCorrelation']),
                    ],
                    [
                        'Evaluation rows',
                        (string) $metrics['sampleSize'],
                        (string) $metrics['sampleSize'],
                        '—',
                    ],
                ],
            );

            $io->table(
                ['Eval day', 'Next-day target', 'Current', 'Legacy', 'Current error', 'Legacy error', 'Strain'],
                array_map(
                    static fn (array $row): array => [
                        $row['evaluationDay'],
                        (string) $row['targetScore'],
                        (string) $row['currentScore'],
                        (string) $row['legacyScore'],
                        number_format($row['currentAbsError'], 1),
                        number_format($row['legacyAbsError'], 1),
                        number_format($row['strainFactor'], 1),
                    ],
                    $rows,
                ),
            );
        }

        $io->section('Planner forecast backtest');
        $io->text([
            sprintf('Target: realized activity-based daily load over the next %d days.', self::PLANNER_FORECAST_HORIZON_DAYS),
            'Method: rebuild each historical forecast window using only sessions already created and not edited after that cutoff.',
        ]);

        if (0 === $plannerBacktest['windowCount']) {
            $io->warning(sprintf(
                'No reconstructable %d-day planner forecast windows could be built from the current data yet.',
                self::PLANNER_FORECAST_HORIZON_DAYS,
            ));
        } else {
            $io->table(
                ['Metric', 'Value', 'Notes'],
                [
                    ['Windows', (string) $plannerBacktest['windowCount'], 'Historical forecast cutoffs evaluated'],
                    ['Daily observations', (string) $plannerBacktest['dailySampleSize'], 'Window count × forecast horizon'],
                    ['Unique reconstructable sessions', (string) $plannerBacktest['reconstructableSessionCount'], 'Historical planner rows that survived cutoff filtering'],
                    ['Daily MAE', number_format($plannerBacktest['dailyMae'], 2), 'Average absolute day-level forecast error'],
                    ['Daily RMSE', number_format($plannerBacktest['dailyRmse'], 2), 'Day-level root mean squared error'],
                    ['7-day total MAE', number_format($plannerBacktest['windowMae'], 2), 'Average absolute error of each full horizon total'],
                    ['7-day total RMSE', number_format($plannerBacktest['windowRmse'], 2), 'Root mean squared error of each horizon total'],
                    ['7-day total Pearson r', $this->formatNullableFloat($plannerBacktest['windowCorrelation']), 'Correlation between projected and realized horizon totals'],
                ],
            );

            $io->table(
                ['Eval day', 'Sessions', 'Projected 7-day load', 'Actual 7-day load', 'Abs error'],
                array_map(
                    static fn (array $row): array => [
                        $row['evaluationDay'],
                        (string) $row['sessionCount'],
                        number_format($row['projectedTotalLoad'], 1),
                        number_format($row['actualTotalLoad'], 1),
                        number_format($row['totalAbsError'], 1),
                    ],
                    $plannerBacktest['rows'],
                ),
            );
        }

        $io->section('What is still missing for a stronger claim');

        $missing = [];
        if ($coverage['recoveryCheckInCount'] < 20) {
            $missing[] = sprintf(
                'Recovery check-ins are sparse (%d rows). A more defensible readiness benchmark wants at least a few dozen labels.',
                $coverage['recoveryCheckInCount'],
            );
        }

        if (0 === $plannerBacktest['windowCount']) {
            $missing[] = sprintf(
                'There are still no reconstructable %d-day planner forecast windows. More historical planned sessions (created and left unchanged before the forecast cutoff) are needed.',
                self::PLANNER_FORECAST_HORIZON_DAYS,
            );
        } elseif ($plannerBacktest['windowCount'] < 10) {
            $missing[] = sprintf(
                'Planner forecast coverage is still thin (%d reconstructable windows). This is enough for a pilot, but not enough for a strong accuracy claim yet.',
                $plannerBacktest['windowCount'],
            );
        }

        if (0 === $coverage['normalizedPowerActivityCount']) {
            $missing[] = 'No historical normalized-power records were found, so the legacy-vs-current power-load branch could not be replayed on past activities.';
        }

        if ($maxAbsStrainFactor <= 0.0 && [] !== $rows) {
            $missing[] = 'The strain factor never activated on the available evaluation days, so the current and legacy readiness variants were identical in this pilot run.';
        }

        if ([] === $missing) {
            $io->success('Coverage is good enough for the currently implemented pilot benchmark.');
        } else {
            $io->warning($missing);
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{activityCount: int, wellnessCount: int, recoveryCheckInCount: int, plannedSessionCount: int, historicalPlannedSessionCount: int, normalizedPowerActivityCount: int, plannedSessionRange: string}
     */
    private function collectCoverage(SerializableDateTime $today): array
    {
        $plannedSessionCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM PlannedSession');
        $historicalPlannedSessionCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM PlannedSession WHERE day < :today',
            ['today' => $today]
        );

        $plannedSessionMinDay = $this->connection->fetchOne('SELECT MIN(day) FROM PlannedSession');
        $plannedSessionMaxDay = $this->connection->fetchOne('SELECT MAX(day) FROM PlannedSession');

        return [
            'activityCount' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM Activity'),
            'wellnessCount' => (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM DailyWellness WHERE source = :source',
                ['source' => WellnessSource::GARMIN->value],
            ),
            'recoveryCheckInCount' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM DailyRecoveryCheckIn'),
            'plannedSessionCount' => $plannedSessionCount,
            'historicalPlannedSessionCount' => $historicalPlannedSessionCount,
            'normalizedPowerActivityCount' => (int) $this->connection->fetchOne(
                'SELECT COUNT(DISTINCT activityId) FROM ActivityStreamMetric WHERE streamType = :streamType AND metricType = :metricType',
                [
                    'streamType' => 'watts',
                    'metricType' => 'normalized_power',
                ],
            ),
            'plannedSessionRange' => false === $plannedSessionMinDay || false === $plannedSessionMaxDay || null === $plannedSessionMinDay || null === $plannedSessionMaxDay
                ? 'No planned sessions found'
                : sprintf('%s → %s', $plannedSessionMinDay, $plannedSessionMaxDay),
        ];
    }

    /**
     * @return list<array{evaluationDay: string, targetDay: string, targetScore: int, currentScore: int, legacyScore: int, currentAbsError: float, legacyAbsError: float, strainFactor: float}>
     */
    private function runReadinessPilotBacktest(): array
    {
        $rows = [];
        $recoveryCheckIns = $this->dailyRecoveryCheckInRepository->findByDateRange(
            DateRange::fromDates(
                SerializableDateTime::fromString('2000-01-01 00:00:00'),
                SerializableDateTime::fromString('2100-01-01 00:00:00'),
            )
        );

        foreach ($recoveryCheckIns as $targetCheckIn) {
            $evaluationDay = SerializableDateTime::fromString(
                $targetCheckIn->getDay()->modify('-1 day')->format('Y-m-d 00:00:00')
            );

            $assessment = $this->buildReadinessAssessmentForDay($evaluationDay);
            if (!$assessment instanceof ReadinessAssessment) {
                continue;
            }

            $currentScore = $assessment->getScore()->getValue();
            $legacyScore = $this->deriveLegacyReadinessScore($assessment);
            $targetScore = $this->deriveSubjectiveTargetScore($targetCheckIn);
            $strainFactor = $assessment->sumFactors([ReadinessFactor::KEY_STRAIN]);

            $rows[] = [
                'evaluationDay' => $evaluationDay->format('Y-m-d'),
                'targetDay' => $targetCheckIn->getDay()->format('Y-m-d'),
                'targetScore' => $targetScore,
                'currentScore' => $currentScore,
                'legacyScore' => $legacyScore,
                'currentAbsError' => abs($currentScore - $targetScore),
                'legacyAbsError' => abs($legacyScore - $targetScore),
                'strainFactor' => $strainFactor,
            ];
        }

        return $rows;
    }

    /**
     * @return array{windowCount: int, reconstructableSessionCount: int, dailySampleSize: int, dailyMae: float, dailyRmse: float, windowMae: float, windowRmse: float, windowCorrelation: ?float, rows: list<array{evaluationDay: string, sessionCount: int, projectedTotalLoad: float, actualTotalLoad: float, totalAbsError: float}>}
     */
    private function runPlannerForecastBacktest(SerializableDateTime $today): array
    {
        $earliestPlannedSession = $this->plannedSessionRepository->findEarliest();
        if (!$earliestPlannedSession instanceof PlannedSession) {
            return [
                'windowCount' => 0,
                'reconstructableSessionCount' => 0,
                'dailySampleSize' => 0,
                'dailyMae' => 0.0,
                'dailyRmse' => 0.0,
                'windowMae' => 0.0,
                'windowRmse' => 0.0,
                'windowCorrelation' => null,
                'rows' => [],
            ];
        }

        $firstEvaluationDay = SerializableDateTime::fromString(
            $earliestPlannedSession->getDay()->modify(sprintf('-%d days', self::PLANNER_FORECAST_HORIZON_DAYS))->format('Y-m-d 00:00:00')
        );
        $latestEvaluationDay = SerializableDateTime::fromString(
            $today->modify(sprintf('-%d days', self::PLANNER_FORECAST_HORIZON_DAYS))->format('Y-m-d 00:00:00')
        );

        if ($firstEvaluationDay->isAfter($latestEvaluationDay)) {
            return [
                'windowCount' => 0,
                'reconstructableSessionCount' => 0,
                'dailySampleSize' => 0,
                'dailyMae' => 0.0,
                'dailyRmse' => 0.0,
                'windowMae' => 0.0,
                'windowRmse' => 0.0,
                'windowCorrelation' => null,
                'rows' => [],
            ];
        }

        $rows = [];
        $dailyProjectedLoads = [];
        $dailyActualLoads = [];
        $windowProjectedLoads = [];
        $windowActualLoads = [];
        $reconstructableSessionIds = [];

        for ($evaluationDay = $firstEvaluationDay; $evaluationDay->isBeforeOrOn($latestEvaluationDay); $evaluationDay = SerializableDateTime::fromString($evaluationDay->modify('+1 day')->format('Y-m-d 00:00:00'))) {
            $window = $this->buildPlannerForecastWindow($evaluationDay);
            if (null === $window) {
                continue;
            }

            $rows[] = [
                'evaluationDay' => $window['evaluationDay'],
                'sessionCount' => $window['sessionCount'],
                'projectedTotalLoad' => $window['projectedTotalLoad'],
                'actualTotalLoad' => $window['actualTotalLoad'],
                'totalAbsError' => $window['totalAbsError'],
            ];

            $dailyProjectedLoads = [...$dailyProjectedLoads, ...$window['projectedLoads']];
            $dailyActualLoads = [...$dailyActualLoads, ...$window['actualLoads']];
            $windowProjectedLoads[] = $window['projectedTotalLoad'];
            $windowActualLoads[] = $window['actualTotalLoad'];

            foreach ($window['sessionIds'] as $sessionId) {
                $reconstructableSessionIds[$sessionId] = true;
            }
        }

        $windowCount = count($rows);
        if (0 === $windowCount) {
            return [
                'windowCount' => 0,
                'reconstructableSessionCount' => 0,
                'dailySampleSize' => 0,
                'dailyMae' => 0.0,
                'dailyRmse' => 0.0,
                'windowMae' => 0.0,
                'windowRmse' => 0.0,
                'windowCorrelation' => null,
                'rows' => [],
            ];
        }

        return [
            'windowCount' => $windowCount,
            'reconstructableSessionCount' => count($reconstructableSessionIds),
            'dailySampleSize' => count($dailyActualLoads),
            'dailyMae' => array_sum(array_map(
                static fn (int $index): float => abs($dailyProjectedLoads[$index] - $dailyActualLoads[$index]),
                array_keys($dailyActualLoads),
            )) / max(1, count($dailyActualLoads)),
            'dailyRmse' => $this->calculateRmse($dailyProjectedLoads, $dailyActualLoads),
            'windowMae' => array_sum(array_map(
                static fn (int $index): float => abs($windowProjectedLoads[$index] - $windowActualLoads[$index]),
                array_keys($windowActualLoads),
            )) / max(1, count($windowActualLoads)),
            'windowRmse' => $this->calculateRmse($windowProjectedLoads, $windowActualLoads),
            'windowCorrelation' => $this->calculatePearsonCorrelation($windowProjectedLoads, $windowActualLoads),
            'rows' => $rows,
        ];
    }

    /**
     * @return array{evaluationDay: string, sessionCount: int, projectedTotalLoad: float, actualTotalLoad: float, totalAbsError: float, projectedLoads: list<float>, actualLoads: list<float>, sessionIds: list<string>}|null
     */
    private function buildPlannerForecastWindow(SerializableDateTime $evaluationDay): ?array
    {
        $forecastStart = SerializableDateTime::fromString($evaluationDay->modify('+1 day')->format('Y-m-d 00:00:00'));
        $forecastEnd = SerializableDateTime::fromString(
            $evaluationDay->modify(sprintf('+%d days', self::PLANNER_FORECAST_HORIZON_DAYS))->format('Y-m-d 23:59:59')
        );
        $cutoff = SerializableDateTime::fromString($evaluationDay->format('Y-m-d 23:59:59'));

        $plannedSessions = $this->plannedSessionRepository->findByDateRange(
            DateRange::fromDates($forecastStart, $forecastEnd)
        );
        if ([] === $plannedSessions) {
            return null;
        }

        $estimator = $this->createHistoricalPlannedSessionLoadEstimator($cutoff);
        $projectedLoadsByOffset = array_fill(1, self::PLANNER_FORECAST_HORIZON_DAYS, 0.0);
        $sessionIds = [];

        foreach ($plannedSessions as $plannedSession) {
            if (!$this->isSessionReconstructableAtCutoff($plannedSession, $cutoff)) {
                continue;
            }

            $estimate = $estimator->estimate($plannedSession);
            if (null === $estimate) {
                continue;
            }

            $offset = (int) $evaluationDay->diff($plannedSession->getDay())->days;
            if ($offset < 1 || $offset > self::PLANNER_FORECAST_HORIZON_DAYS) {
                continue;
            }

            $projectedLoadsByOffset[$offset] += $estimate->getEstimatedLoad();
            $sessionIds[] = (string) $plannedSession->getId();
        }

        if ([] === $sessionIds) {
            return null;
        }

        $projectedLoads = [];
        $actualLoads = [];
        for ($offset = 1; $offset <= self::PLANNER_FORECAST_HORIZON_DAYS; ++$offset) {
            $forecastDay = SerializableDateTime::fromString($evaluationDay->modify(sprintf('+%d days', $offset))->format('Y-m-d 00:00:00'));
            $projectedLoads[] = round($projectedLoadsByOffset[$offset], 1);
            $actualLoads[] = (float) $this->dailyTrainingLoad->calculate($forecastDay);
        }

        $projectedTotalLoad = round(array_sum($projectedLoads), 1);
        $actualTotalLoad = round(array_sum($actualLoads), 1);

        return [
            'evaluationDay' => $evaluationDay->format('Y-m-d'),
            'sessionCount' => count($sessionIds),
            'projectedTotalLoad' => $projectedTotalLoad,
            'actualTotalLoad' => $actualTotalLoad,
            'totalAbsError' => abs($projectedTotalLoad - $actualTotalLoad),
            'projectedLoads' => $projectedLoads,
            'actualLoads' => $actualLoads,
            'sessionIds' => array_values(array_unique($sessionIds)),
        ];
    }

    private function isSessionReconstructableAtCutoff(PlannedSession $plannedSession, SerializableDateTime $cutoff): bool
    {
        return $plannedSession->getCreatedAt()->isBeforeOrOn($cutoff)
            && $plannedSession->getUpdatedAt()->isBeforeOrOn($cutoff);
    }

    private function createHistoricalPlannedSessionLoadEstimator(SerializableDateTime $cutoff): PlannedSessionLoadEstimator
    {
        $cutoffActivityRepository = $this->createCutoffActivityRepository($cutoff);

        return new PlannedSessionLoadEstimator(
            activityRepository: $cutoffActivityRepository,
            athleteRepository: $this->athleteRepository,
            performanceAnchorHistory: $this->performanceAnchorHistory,
        );
    }

    private function createCutoffActivityRepository(SerializableDateTime $cutoff): ActivityRepository
    {
        $activities = array_values(array_filter(
            iterator_to_array($this->activityRepository->findAll()),
            static fn (Activity $activity): bool => $activity->getStartDate()->isBeforeOrOn($cutoff),
        ));

        return new class($activities) implements ActivityRepository {
            /** @var array<string, Activity> */
            private array $activitiesById = [];

            /**
             * @param list<Activity> $activities
             */
            public function __construct(array $activities)
            {
                foreach ($activities as $activity) {
                    $this->activitiesById[(string) $activity->getId()] = $activity;
                }
            }

            public function find(ActivityId $activityId): Activity
            {
                if (!array_key_exists((string) $activityId, $this->activitiesById)) {
                    throw new \RuntimeException(sprintf('Activity %s not found in historical snapshot.', $activityId));
                }

                return $this->activitiesById[(string) $activityId];
            }

            public function findAll(): Activities
            {
                return Activities::fromArray(array_values($this->activitiesById));
            }

            public function findWithRawData(ActivityId $activityId): ActivityWithRawData
            {
                throw new \BadMethodCallException('Historical snapshot repository does not expose raw activity payloads.');
            }

            public function exists(ActivityId $activityId): bool
            {
                return array_key_exists((string) $activityId, $this->activitiesById);
            }

            public function add(ActivityWithRawData $activityWithRawData): void
            {
                throw new \BadMethodCallException('Historical snapshot repository is read-only.');
            }

            public function update(ActivityWithRawData $activityWithRawData): void
            {
                throw new \BadMethodCallException('Historical snapshot repository is read-only.');
            }

            public function delete(ActivityId $activityId): void
            {
                throw new \BadMethodCallException('Historical snapshot repository is read-only.');
            }

            public function activityNeedsStreamImport(ActivityId $activityId): bool
            {
                throw new \BadMethodCallException('Historical snapshot repository does not manage imports.');
            }

            public function markActivityStreamsAsImported(ActivityId $activityId): void
            {
                throw new \BadMethodCallException('Historical snapshot repository does not manage imports.');
            }

            public function markActivitiesForDeletion(\App\Domain\Activity\ActivityIds $activityIds): void
            {
                throw new \BadMethodCallException('Historical snapshot repository is read-only.');
            }
        };
    }

    private function buildReadinessAssessmentForDay(SerializableDateTime $evaluationDay): ?ReadinessAssessment
    {
        $trainingMetricsRange = DateRange::fromDates(
            SerializableDateTime::fromString(
                $evaluationDay->modify(sprintf('-%d days', self::TRAINING_METRICS_WARMUP_DAYS))->format('Y-m-d 00:00:00')
            ),
            SerializableDateTime::fromString($evaluationDay->format('Y-m-d 23:59:59')),
        );
        $wellnessRange = DateRange::fromDates(
            SerializableDateTime::fromString(
                $evaluationDay->modify(sprintf('-%d days', self::READINESS_WELLNESS_WINDOW_DAYS - 1))->format('Y-m-d 00:00:00')
            ),
            SerializableDateTime::fromString($evaluationDay->format('Y-m-d 23:59:59')),
        );

        $integratedLoads = $this->integratedDailyLoadCalculator->calculateForDateRange($trainingMetricsRange);
        $wellnessRecords = $this->dailyWellnessRepository->findByDateRange($wellnessRange, WellnessSource::GARMIN);
        if ([] === $wellnessRecords) {
            return null;
        }

        $latestRecoveryCheckIn = $this->dailyRecoveryCheckInRepository->findByDay($evaluationDay);

        return $this->wellnessReadinessCalculator->assess(
            TrainingMetrics::create($integratedLoads),
            $this->buildWellnessMetricsResponse($wellnessRecords),
            $latestRecoveryCheckIn instanceof DailyRecoveryCheckIn ? $this->mapRecoveryCheckIn($latestRecoveryCheckIn) : null,
        );
    }

    /**
     * @param list<DailyWellness> $records
     */
    private function buildWellnessMetricsResponse(array $records): FindWellnessMetricsResponse
    {
        $mappedRecords = array_map(
            static fn (DailyWellness $record): array => [
                'day' => $record->getDay()->format('Y-m-d'),
                'stepsCount' => $record->getStepsCount(),
                'sleepDurationInSeconds' => $record->getSleepDurationInSeconds(),
                'sleepScore' => $record->getSleepScore(),
                'hrv' => $record->getHrv(),
            ],
            $records,
        );

        $lastRecord = $records[array_key_last($records)] ?? null;

        return new FindWellnessMetricsResponse(
            records: $mappedRecords,
            latestDay: $lastRecord instanceof DailyWellness ? $lastRecord->getDay()->setTime(0, 0) : null,
        );
    }

    /**
     * @return array{day: string, fatigue: int, soreness: int, stress: int, motivation: int, sleepQuality: int}
     */
    private function mapRecoveryCheckIn(DailyRecoveryCheckIn $checkIn): array
    {
        return [
            'day' => $checkIn->getDay()->format('Y-m-d'),
            'fatigue' => $checkIn->getFatigue(),
            'soreness' => $checkIn->getSoreness(),
            'stress' => $checkIn->getStress(),
            'motivation' => $checkIn->getMotivation(),
            'sleepQuality' => $checkIn->getSleepQuality(),
        ];
    }

    private function deriveLegacyReadinessScore(ReadinessAssessment $assessment): int
    {
        $score = 0.0;

        foreach ($assessment->getFactors() as $factor) {
            if (ReadinessFactor::KEY_STRAIN === $factor->getKey()) {
                continue;
            }

            $score += $factor->getValue();
        }

        return (int) round(max(0.0, min(100.0, $score)));
    }

    private function deriveSubjectiveTargetScore(DailyRecoveryCheckIn $checkIn): int
    {
        $score = 55.0;
        $score -= ($checkIn->getFatigue() - 1) * 3.0;
        $score -= ($checkIn->getSoreness() - 1) * 2.0;
        $score -= ($checkIn->getStress() - 1) * 2.5;
        $score += ($checkIn->getMotivation() - 3) * 2.0;
        $score += ($checkIn->getSleepQuality() - 3) * 2.0;

        return (int) round(max(0.0, min(100.0, $score)));
    }

    /**
     * @param list<array{evaluationDay: string, targetDay: string, targetScore: int, currentScore: int, legacyScore: int, currentAbsError: float, legacyAbsError: float, strainFactor: float}> $rows
     *
     * @return array{sampleSize: int, currentMae: float, legacyMae: float, currentRmse: float, legacyRmse: float, currentCorrelation: ?float, legacyCorrelation: ?float}
     */
    private function calculateBacktestMetrics(array $rows): array
    {
        $targetScores = array_map(static fn (array $row): int => $row['targetScore'], $rows);
        $currentScores = array_map(static fn (array $row): int => $row['currentScore'], $rows);
        $legacyScores = array_map(static fn (array $row): int => $row['legacyScore'], $rows);

        return [
            'sampleSize' => count($rows),
            'currentMae' => array_sum(array_map(static fn (array $row): float => $row['currentAbsError'], $rows)) / count($rows),
            'legacyMae' => array_sum(array_map(static fn (array $row): float => $row['legacyAbsError'], $rows)) / count($rows),
            'currentRmse' => $this->calculateRmse($currentScores, $targetScores),
            'legacyRmse' => $this->calculateRmse($legacyScores, $targetScores),
            'currentCorrelation' => $this->calculatePearsonCorrelation($currentScores, $targetScores),
            'legacyCorrelation' => $this->calculatePearsonCorrelation($legacyScores, $targetScores),
        ];
    }

    /**
     * @param list<int|float> $predictions
     * @param list<int|float> $targets
     */
    private function calculateRmse(array $predictions, array $targets): float
    {
        $count = count($predictions);
        if (0 === $count) {
            return 0.0;
        }

        $sum = 0.0;
        for ($index = 0; $index < $count; ++$index) {
            $sum += ($predictions[$index] - $targets[$index]) ** 2;
        }

        return sqrt($sum / $count);
    }

    /**
     * @param list<int|float> $left
     * @param list<int|float> $right
     */
    private function calculatePearsonCorrelation(array $left, array $right): ?float
    {
        $count = count($left);
        if ($count < 2 || $count !== count($right)) {
            return null;
        }

        $leftMean = array_sum($left) / $count;
        $rightMean = array_sum($right) / $count;

        $numerator = 0.0;
        $leftVariance = 0.0;
        $rightVariance = 0.0;

        for ($index = 0; $index < $count; ++$index) {
            $leftDelta = $left[$index] - $leftMean;
            $rightDelta = $right[$index] - $rightMean;

            $numerator += $leftDelta * $rightDelta;
            $leftVariance += $leftDelta ** 2;
            $rightVariance += $rightDelta ** 2;
        }

        if (0.0 === $leftVariance || 0.0 === $rightVariance) {
            return null;
        }

        return $numerator / sqrt($leftVariance * $rightVariance);
    }

    private function formatNullableFloat(?float $value): string
    {
        return null === $value ? 'n/a' : number_format($value, 3);
    }

    private function formatCorrelationDelta(?float $current, ?float $legacy): string
    {
        if (null === $current || null === $legacy) {
            return 'n/a';
        }

        return $this->formatSignedDelta($current - $legacy, lowerIsBetter: false, decimals: 3);
    }

    private function formatSignedDelta(float $delta, bool $lowerIsBetter, int $decimals = 2): string
    {
        $isImprovement = $lowerIsBetter ? $delta < 0 : $delta > 0;
        $prefix = $delta > 0 ? '+' : '';

        return sprintf(
            '%s%s (%s)',
            $prefix,
            number_format($delta, $decimals),
            $isImprovement ? 'better' : ($delta === 0.0 ? 'flat' : 'worse'),
        );
    }
}