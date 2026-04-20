<?php

declare(strict_types=1);

namespace App\Tests\Domain\TrainingPlanner;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityType;
use App\Domain\TrainingPlanner\PlanGenerator\ProposedSession;
use App\Domain\TrainingPlanner\PlanGenerator\ProposedTrainingBlock;
use App\Domain\TrainingPlanner\PlanGenerator\ProposedWeekSkeleton;
use App\Domain\TrainingPlanner\PlanGenerator\RaceProfileTrainingRules;
use App\Domain\TrainingPlanner\PlanGenerator\TrainingPlanProposal;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionEstimationSource;
use App\Domain\TrainingPlanner\PlannedSessionId;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Domain\TrainingPlanner\PlannedSessionLinkStatus;
use App\Domain\TrainingPlanner\Prediction\RunningPlanPerformancePredictor;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventId;
use App\Domain\TrainingPlanner\RaceEventPriority;
use App\Domain\TrainingPlanner\RaceEventProfile;
use App\Domain\TrainingPlanner\RaceEventType;
use App\Domain\TrainingPlanner\TrainingBlockPhase;
use App\Domain\TrainingPlanner\TrainingFocus;
use App\Domain\TrainingPlanner\TrainingPlan;
use App\Domain\TrainingPlanner\TrainingPlanDiscipline;
use App\Domain\TrainingPlanner\TrainingPlanId;
use App\Domain\TrainingPlanner\TrainingPlanType;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use PHPUnit\Framework\TestCase;

final class RunningPlanPerformancePredictorTest extends TestCase
{
    public function testPredictBuildsCurrentAndProjectedBenchmarks(): void
    {
        $predictor = new RunningPlanPerformancePredictor();
        $trainingPlan = TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::TRAINING,
            startDay: SerializableDateTime::fromString('2026-07-06 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-10-25 00:00:00'),
            targetRaceEventId: null,
            title: 'Run block',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            discipline: TrainingPlanDiscipline::RUNNING,
            performanceMetrics: [
                'runningThresholdPace' => 255,
                'weeklyRunningVolume' => 48.0,
            ],
            targetRaceProfile: RaceEventProfile::HALF_MARATHON,
            trainingFocus: TrainingFocus::RUN,
        );

        $prediction = $predictor->predict($trainingPlan, $this->createProposal());

        self::assertNotNull($prediction);
        self::assertSame(255, $prediction->getCurrentThresholdPaceInSeconds());
        self::assertLessThan(255, $prediction->getProjectedThresholdPaceInSeconds());
        self::assertGreaterThan(0, $prediction->getProjectedGainInSecondsPerKm());
        self::assertSame('High confidence', $prediction->getConfidenceLabel());
        self::assertCount(3, $prediction->getBenchmarkPredictions());
        self::assertSame('Half marathon', $prediction->getBenchmarkPredictions()[0]->getLabel());
        self::assertLessThan(
            $prediction->getBenchmarkPredictions()[0]->getCurrentFinishTimeInSeconds(),
            $prediction->getBenchmarkPredictions()[0]->getProjectedFinishTimeInSeconds(),
        );
    }

    public function testPredictBuildsProgressiveWeeklyThresholdCurve(): void
    {
        $predictor = new RunningPlanPerformancePredictor();
        $trainingPlan = TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::TRAINING,
            startDay: SerializableDateTime::fromString('2026-07-06 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-10-25 00:00:00'),
            targetRaceEventId: null,
            title: 'Run block',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            discipline: TrainingPlanDiscipline::TRIATHLON,
            performanceMetrics: [
                'runningThresholdPace' => 260,
                'weeklyRunningVolume' => 42.0,
            ],
            targetRaceProfile: RaceEventProfile::HALF_DISTANCE_TRIATHLON,
            trainingFocus: TrainingFocus::RUN,
        );

        $prediction = $predictor->predict($trainingPlan, $this->createProposal());

        self::assertNotNull($prediction);

        $byWeek = $prediction->getProjectedThresholdPaceByWeekStartDate();

        self::assertArrayHasKey('2026-07-06', $byWeek);
        self::assertArrayHasKey('2026-07-27', $byWeek);
        self::assertArrayHasKey('2026-09-07', $byWeek);
        self::assertLessThanOrEqual($byWeek['2026-07-06'], $byWeek['2026-07-27']);
        self::assertLessThanOrEqual($byWeek['2026-07-27'], $byWeek['2026-09-07']);
        self::assertSame($prediction->getProjectedThresholdPaceInSeconds(), $byWeek['2026-09-07']);
    }

    public function testPredictReturnsNullWithoutRunningThresholdMetric(): void
    {
        $predictor = new RunningPlanPerformancePredictor();
        $trainingPlan = TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::TRAINING,
            startDay: SerializableDateTime::fromString('2026-07-06 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-10-25 00:00:00'),
            targetRaceEventId: null,
            title: 'Run block',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            discipline: TrainingPlanDiscipline::RUNNING,
            performanceMetrics: ['weeklyRunningVolume' => 48.0],
            targetRaceProfile: RaceEventProfile::HALF_MARATHON,
            trainingFocus: TrainingFocus::RUN,
        );

        self::assertNull($predictor->predict($trainingPlan, $this->createProposal()));
    }

    public function testPredictDistinguishesShortAndLongPlans(): void
    {
        $predictor = new RunningPlanPerformancePredictor();
        $trainingPlan = TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::TRAINING,
            startDay: SerializableDateTime::fromString('2026-07-06 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-11-22 00:00:00'),
            targetRaceEventId: null,
            title: 'Run block',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            discipline: TrainingPlanDiscipline::RUNNING,
            performanceMetrics: [
                'runningThresholdPace' => 255,
                'weeklyRunningVolume' => 48.0,
            ],
            targetRaceProfile: RaceEventProfile::HALF_MARATHON,
            trainingFocus: TrainingFocus::RUN,
        );

        $shortPrediction = $predictor->predict($trainingPlan, $this->createStructuredProposal(8, 4, 2, true));
        $longPrediction = $predictor->predict($trainingPlan, $this->createStructuredProposal(20, 4, 2, true));

        self::assertNotNull($shortPrediction);
        self::assertNotNull($longPrediction);
        self::assertGreaterThan(
            $shortPrediction->getProjectedGainInSecondsPerKm(),
            $longPrediction->getProjectedGainInSecondsPerKm(),
        );
        self::assertLessThan(
            $shortPrediction->getProjectedThresholdPaceInSeconds(),
            $longPrediction->getProjectedThresholdPaceInSeconds(),
        );
    }

    public function testPredictRewardsRunHeavyProposalStructure(): void
    {
        $predictor = new RunningPlanPerformancePredictor();
        $trainingPlan = TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::TRAINING,
            startDay: SerializableDateTime::fromString('2026-07-06 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-10-25 00:00:00'),
            targetRaceEventId: null,
            title: 'Run block',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            discipline: TrainingPlanDiscipline::RUNNING,
            performanceMetrics: [
                'runningThresholdPace' => 255,
                'weeklyRunningVolume' => 48.0,
            ],
            targetRaceProfile: RaceEventProfile::HALF_MARATHON,
            trainingFocus: TrainingFocus::RUN,
        );

        $lightPrediction = $predictor->predict($trainingPlan, $this->createStructuredProposal(12, 2, 0, false));
        $heavyPrediction = $predictor->predict($trainingPlan, $this->createStructuredProposal(12, 5, 2, true));

        self::assertNotNull($lightPrediction);
        self::assertNotNull($heavyPrediction);
        self::assertGreaterThan(
            $lightPrediction->getProjectedGainInSecondsPerKm(),
            $heavyPrediction->getProjectedGainInSecondsPerKm(),
        );
        self::assertLessThan(
            $lightPrediction->getProjectedThresholdPaceInSeconds(),
            $heavyPrediction->getProjectedThresholdPaceInSeconds(),
        );
    }

    public function testPredictBuildsDifferentWeeklyCurvesForDifferentRunStructures(): void
    {
        $predictor = new RunningPlanPerformancePredictor();
        $trainingPlan = TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::TRAINING,
            startDay: SerializableDateTime::fromString('2026-07-06 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-10-25 00:00:00'),
            targetRaceEventId: null,
            title: 'Run block',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            discipline: TrainingPlanDiscipline::RUNNING,
            performanceMetrics: [
                'runningThresholdPace' => 255,
                'weeklyRunningVolume' => 48.0,
            ],
            targetRaceProfile: RaceEventProfile::HALF_MARATHON,
            trainingFocus: TrainingFocus::RUN,
        );

        $lightPrediction = $predictor->predict($trainingPlan, $this->createStructuredProposal(12, 2, 0, false));
        $heavyPrediction = $predictor->predict($trainingPlan, $this->createStructuredProposal(12, 5, 2, true));

        self::assertNotNull($lightPrediction);
        self::assertNotNull($heavyPrediction);

        $lightCurve = $lightPrediction->getProjectedThresholdPaceByWeekStartDate();
        $heavyCurve = $heavyPrediction->getProjectedThresholdPaceByWeekStartDate();

        self::assertArrayHasKey('2026-08-03', $lightCurve);
        self::assertArrayHasKey('2026-08-03', $heavyCurve);
        self::assertLessThanOrEqual($lightCurve['2026-08-03'], $heavyCurve['2026-08-03']);
    }

    public function testPredictBuildsAdherenceAdjustedTrajectoryFromCompletedSessions(): void
    {
        $predictor = new RunningPlanPerformancePredictor();
        $trainingPlan = TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::TRAINING,
            startDay: SerializableDateTime::fromString('2026-07-06 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-10-25 00:00:00'),
            targetRaceEventId: null,
            title: 'Run block',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            discipline: TrainingPlanDiscipline::RUNNING,
            performanceMetrics: [
                'runningThresholdPace' => 255,
                'weeklyRunningVolume' => 48.0,
            ],
            targetRaceProfile: RaceEventProfile::HALF_MARATHON,
            trainingFocus: TrainingFocus::RUN,
        );
        $proposal = $this->createStructuredProposal(12, 4, 2, true);

        $lowAdherencePrediction = $predictor->predict(
            $trainingPlan,
            $proposal,
            $this->createHistoricalRunSessions([true, false, true, false]),
            SerializableDateTime::fromString('2026-08-01 08:00:00'),
        );
        $highAdherencePrediction = $predictor->predict(
            $trainingPlan,
            $proposal,
            $this->createHistoricalRunSessions([true, true, true, true]),
            SerializableDateTime::fromString('2026-08-01 08:00:00'),
        );

        self::assertNotNull($lowAdherencePrediction);
        self::assertNotNull($highAdherencePrediction);
        self::assertNotNull($lowAdherencePrediction->getTrajectoryThresholdPaceInSeconds());
        self::assertNotNull($highAdherencePrediction->getTrajectoryThresholdPaceInSeconds());
        self::assertSame(
            $lowAdherencePrediction->getProjectedThresholdPaceInSeconds(),
            $highAdherencePrediction->getProjectedThresholdPaceInSeconds(),
        );
        self::assertGreaterThan(
            $lowAdherencePrediction->getProjectedThresholdPaceInSeconds(),
            $lowAdherencePrediction->getTrajectoryThresholdPaceInSeconds(),
        );
        self::assertGreaterThanOrEqual(
            $highAdherencePrediction->getProjectedThresholdPaceInSeconds(),
            $highAdherencePrediction->getTrajectoryThresholdPaceInSeconds(),
        );
        self::assertGreaterThan(
            $highAdherencePrediction->getTrajectoryThresholdPaceInSeconds(),
            $lowAdherencePrediction->getTrajectoryThresholdPaceInSeconds(),
        );
        $lowAdherenceCurve = $lowAdherencePrediction->getProjectedThresholdPaceByWeekStartDate();

        self::assertSame(
            $lowAdherencePrediction->getTrajectoryThresholdPaceInSeconds(),
            $lowAdherenceCurve[(string) array_key_last($lowAdherenceCurve)],
        );
        self::assertSame(4, $lowAdherencePrediction->getAdherenceSnapshot()?->getPlannedRunSessionCount());
        self::assertSame(2, $lowAdherencePrediction->getAdherenceSnapshot()?->getCompletedRunSessionCount());
    }

    public function testPredictOmitsTrajectoryWithoutHistoricalRunSessions(): void
    {
        $predictor = new RunningPlanPerformancePredictor();
        $trainingPlan = TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::TRAINING,
            startDay: SerializableDateTime::fromString('2026-07-06 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-10-25 00:00:00'),
            targetRaceEventId: null,
            title: 'Run block',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            discipline: TrainingPlanDiscipline::RUNNING,
            performanceMetrics: [
                'runningThresholdPace' => 255,
                'weeklyRunningVolume' => 48.0,
            ],
            targetRaceProfile: RaceEventProfile::HALF_MARATHON,
            trainingFocus: TrainingFocus::RUN,
        );

        $prediction = $predictor->predict(
            $trainingPlan,
            $this->createStructuredProposal(12, 4, 2, true),
            [],
            SerializableDateTime::fromString('2026-07-06 08:00:00'),
        );

        self::assertNotNull($prediction);
        self::assertNull($prediction->getTrajectoryThresholdPaceInSeconds());
        self::assertNull($prediction->getTrajectoryGainInSecondsPerKm());
        self::assertNull($prediction->getAdherenceSnapshot());
    }

    private function createProposal(): TrainingPlanProposal
    {
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-10-25 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Goal race',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5_700,
            createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
        );

        return TrainingPlanProposal::create(
            targetRace: $targetRace,
            rules: RaceProfileTrainingRules::forProfile(RaceEventProfile::HALF_MARATHON),
            planStartDay: SerializableDateTime::fromString('2026-07-06 00:00:00'),
            planEndDay: SerializableDateTime::fromString('2026-10-25 00:00:00'),
            totalWeeks: 16,
            proposedBlocks: [
                ProposedTrainingBlock::create(
                    startDay: SerializableDateTime::fromString('2026-07-06 00:00:00'),
                    endDay: SerializableDateTime::fromString('2026-08-16 00:00:00'),
                    phase: TrainingBlockPhase::BASE,
                    targetRaceEventId: $targetRace->getId(),
                    title: 'Base',
                    focus: null,
                    weekSkeletons: [
                        $this->createWeek(1, '2026-07-06', TrainingBlockPhase::BASE),
                        $this->createWeek(2, '2026-07-13', TrainingBlockPhase::BASE),
                        $this->createWeek(3, '2026-07-20', TrainingBlockPhase::BASE),
                        $this->createWeek(4, '2026-07-27', TrainingBlockPhase::BASE, true),
                    ],
                ),
                ProposedTrainingBlock::create(
                    startDay: SerializableDateTime::fromString('2026-08-17 00:00:00'),
                    endDay: SerializableDateTime::fromString('2026-10-25 00:00:00'),
                    phase: TrainingBlockPhase::BUILD,
                    targetRaceEventId: $targetRace->getId(),
                    title: 'Build',
                    focus: null,
                    weekSkeletons: [
                        $this->createWeek(5, '2026-08-17', TrainingBlockPhase::BUILD),
                        $this->createWeek(6, '2026-08-24', TrainingBlockPhase::BUILD),
                        $this->createWeek(7, '2026-08-31', TrainingBlockPhase::BUILD),
                        $this->createWeek(8, '2026-09-07', TrainingBlockPhase::BUILD),
                    ],
                ),
            ],
        );
    }

    private function createWeek(int $weekNumber, string $startDay, TrainingBlockPhase $phase, bool $isRecoveryWeek = false): ProposedWeekSkeleton
    {
        return ProposedWeekSkeleton::create(
            weekNumber: $weekNumber,
            startDay: SerializableDateTime::fromString(sprintf('%s 00:00:00', $startDay)),
            endDay: SerializableDateTime::fromString(sprintf('%s 00:00:00', SerializableDateTime::fromString(sprintf('%s 00:00:00', $startDay))->modify('+6 days')->format('Y-m-d'))),
            sessions: [
                ProposedSession::create(
                    day: SerializableDateTime::fromString(sprintf('%s 00:00:00', $startDay)),
                    activityType: \App\Domain\Activity\ActivityType::RUN,
                    targetIntensity: TrainingBlockPhase::BUILD === $phase ? PlannedSessionIntensity::HARD : PlannedSessionIntensity::MODERATE,
                    title: TrainingBlockPhase::BUILD === $phase ? 'Run tempo' : 'Easy run',
                    notes: null,
                    targetDurationInSeconds: 3_600,
                ),
            ],
            targetLoadMultiplier: $isRecoveryWeek ? 0.92 : 1.0,
            isRecoveryWeek: $isRecoveryWeek,
        );
    }

    private function createStructuredProposal(
        int $totalWeeks,
        int $runSessionsPerWeek,
        int $keyRunSessionsPerWeek,
        bool $includeLongRun,
    ): TrainingPlanProposal {
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-11-29 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Goal race',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5_700,
            createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
        );

        $phaseWeeks = [
            TrainingBlockPhase::BASE->value => max(1, (int) floor($totalWeeks * 0.35)),
            TrainingBlockPhase::BUILD->value => max(1, (int) floor($totalWeeks * 0.45)),
            TrainingBlockPhase::PEAK->value => $totalWeeks >= 10 ? 2 : 0,
        ];
        $allocatedWeeks = array_sum($phaseWeeks);
        $phaseWeeks[TrainingBlockPhase::TAPER->value] = max(0, $totalWeeks - $allocatedWeeks);

        $blocks = [];
        $weekNumber = 1;
        $blockStartDay = SerializableDateTime::fromString('2026-07-06 00:00:00');

        foreach ($phaseWeeks as $phaseValue => $weekCount) {
            if ($weekCount <= 0) {
                continue;
            }

            $phase = TrainingBlockPhase::from($phaseValue);
            $weekSkeletons = [];

            for ($offset = 0; $offset < $weekCount; ++$offset) {
                $weekStartDay = SerializableDateTime::fromDateTimeImmutable($blockStartDay->modify(sprintf('+%d days', $offset * 7)));
                $weekSkeletons[] = $this->createStructuredWeek(
                    weekNumber: $weekNumber,
                    startDay: $weekStartDay,
                    phase: $phase,
                    runSessionsPerWeek: $runSessionsPerWeek,
                    keyRunSessionsPerWeek: $keyRunSessionsPerWeek,
                    includeLongRun: $includeLongRun,
                    isRecoveryWeek: 0 === ($weekNumber % 4),
                );
                ++$weekNumber;
            }

            $blockEndDay = SerializableDateTime::fromDateTimeImmutable($blockStartDay->modify(sprintf('+%d days', ($weekCount * 7) - 1)));
            $blocks[] = ProposedTrainingBlock::create(
                startDay: $blockStartDay,
                endDay: $blockEndDay,
                phase: $phase,
                targetRaceEventId: $targetRace->getId(),
                title: ucfirst(strtolower($phase->value)),
                focus: null,
                weekSkeletons: $weekSkeletons,
            );
            $blockStartDay = SerializableDateTime::fromDateTimeImmutable($blockEndDay->modify('+1 day'));
        }

        return TrainingPlanProposal::create(
            targetRace: $targetRace,
            rules: RaceProfileTrainingRules::forProfile(RaceEventProfile::HALF_MARATHON),
            planStartDay: SerializableDateTime::fromString('2026-07-06 00:00:00'),
            planEndDay: SerializableDateTime::fromDateTimeImmutable(SerializableDateTime::fromString('2026-07-06 00:00:00')->modify(sprintf('+%d days', ($totalWeeks * 7) - 1))),
            totalWeeks: $totalWeeks,
            proposedBlocks: $blocks,
        );
    }

    private function createStructuredWeek(
        int $weekNumber,
        SerializableDateTime $startDay,
        TrainingBlockPhase $phase,
        int $runSessionsPerWeek,
        int $keyRunSessionsPerWeek,
        bool $includeLongRun,
        bool $isRecoveryWeek,
    ): ProposedWeekSkeleton {
        $sessions = [];
        $dayOffsets = [0, 1, 3, 5, 6, 6];

        for ($sessionIndex = 0; $sessionIndex < $runSessionsPerWeek; ++$sessionIndex) {
            $isLongRunSession = $includeLongRun && $sessionIndex === $runSessionsPerWeek - 1;
            $isKeySession = !$isLongRunSession && $sessionIndex < $keyRunSessionsPerWeek;
            $sessionDurationInSeconds = $isLongRunSession
                ? 6_000
                : ($isKeySession ? 3_900 : 2_700);
            $sessionIntensity = $isLongRunSession
                ? PlannedSessionIntensity::MODERATE
                : ($isKeySession ? PlannedSessionIntensity::HARD : PlannedSessionIntensity::EASY);
            $sessionTitle = $isLongRunSession
                ? 'Long run'
                : ($isKeySession ? sprintf('Run intervals %d', $sessionIndex + 1) : sprintf('Easy run %d', $sessionIndex + 1));

            $sessions[] = ProposedSession::create(
                day: SerializableDateTime::fromDateTimeImmutable($startDay->modify(sprintf('+%d days', $dayOffsets[$sessionIndex] ?? 6))),
                activityType: ActivityType::RUN,
                targetIntensity: $sessionIntensity,
                title: $sessionTitle,
                notes: null,
                targetDurationInSeconds: $sessionDurationInSeconds,
                isKeySession: $isKeySession,
            );
        }

        return ProposedWeekSkeleton::create(
            weekNumber: $weekNumber,
            startDay: $startDay,
            endDay: SerializableDateTime::fromDateTimeImmutable($startDay->modify('+6 days')),
            sessions: $sessions,
            targetLoadMultiplier: TrainingBlockPhase::BUILD === $phase ? 1.04 : 0.96,
            isRecoveryWeek: $isRecoveryWeek,
        );
    }

    /**
     * @param list<bool> $completionPattern
     *
     * @return list<PlannedSession>
     */
    private function createHistoricalRunSessions(array $completionPattern): array
    {
        $days = ['2026-07-06', '2026-07-08', '2026-07-13', '2026-07-19'];
        $titles = ['Run intervals 1', 'Easy run 1', 'Run intervals 2', 'Long run'];
        $durations = [3900, 2700, 3900, 6000];
        $intensities = [PlannedSessionIntensity::HARD, PlannedSessionIntensity::EASY, PlannedSessionIntensity::HARD, PlannedSessionIntensity::MODERATE];
        $sessions = [];

        foreach ($completionPattern as $index => $isCompleted) {
            $sessions[] = PlannedSession::create(
                plannedSessionId: PlannedSessionId::random(),
                day: SerializableDateTime::fromString(sprintf('%s 00:00:00', $days[$index])),
                activityType: ActivityType::RUN,
                title: $titles[$index],
                notes: null,
                targetLoad: 40.0 + $index,
                targetDurationInSeconds: $durations[$index],
                targetIntensity: $intensities[$index],
                templateActivityId: null,
                estimationSource: PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
                linkedActivityId: $isCompleted ? ActivityId::random() : null,
                linkStatus: $isCompleted ? PlannedSessionLinkStatus::LINKED : PlannedSessionLinkStatus::UNLINKED,
                createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
                updatedAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            );
        }

        return $sessions;
    }
}