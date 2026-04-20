<?php

declare(strict_types=1);

namespace App\Tests\Domain\TrainingPlanner\Analysis;

use App\Domain\Activity\ActivityType;
use App\Domain\TrainingPlanner\Analysis\TrainingPlanAnalysisScenario;
use App\Domain\TrainingPlanner\Analysis\TrainingPlanAnalysisScenarioMatrix;
use App\Domain\TrainingPlanner\Analysis\TrainingPlanQualityAnalyzer;
use App\Domain\TrainingPlanner\PlanGenerator\PlanAdaptationWarning;
use App\Domain\TrainingPlanner\PlanGenerator\PlanAdaptationWarningSeverity;
use App\Domain\TrainingPlanner\PlanGenerator\PlanAdaptationWarningType;
use App\Domain\TrainingPlanner\PlanGenerator\ProposedSession;
use App\Domain\TrainingPlanner\PlanGenerator\ProposedTrainingBlock;
use App\Domain\TrainingPlanner\PlanGenerator\ProposedWeekSkeleton;
use App\Domain\TrainingPlanner\PlanGenerator\RaceProfileTrainingRules;
use App\Domain\TrainingPlanner\PlanGenerator\TrainingPlanProposal;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
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

final class TrainingPlanAnalysisFrameworkTest extends TestCase
{
    public function testScenarioMatrixBuildsWideCoverageAcrossDisciplinesAndPlanTypes(): void
    {
        $matrix = new TrainingPlanAnalysisScenarioMatrix();
        $scenarios = $matrix->build();
        $scenarioNames = array_map(static fn (TrainingPlanAnalysisScenario $scenario): string => $scenario->getName(), $scenarios);

        self::assertGreaterThanOrEqual(36, count($scenarios));
        self::assertCount(
            count(array_unique($scenarioNames)),
            $scenarios,
        );
        self::assertContains(TrainingPlanDiscipline::RUNNING, array_map(static fn (TrainingPlanAnalysisScenario $scenario): TrainingPlanDiscipline => $scenario->getDiscipline(), $scenarios));
        self::assertContains(TrainingPlanDiscipline::CYCLING, array_map(static fn (TrainingPlanAnalysisScenario $scenario): TrainingPlanDiscipline => $scenario->getDiscipline(), $scenarios));
        self::assertContains(TrainingPlanDiscipline::TRIATHLON, array_map(static fn (TrainingPlanAnalysisScenario $scenario): TrainingPlanDiscipline => $scenario->getDiscipline(), $scenarios));
        self::assertContains(TrainingPlanType::RACE, array_map(static fn (TrainingPlanAnalysisScenario $scenario): TrainingPlanType => $scenario->getPlanType(), $scenarios));
        self::assertContains(TrainingPlanType::TRAINING, array_map(static fn (TrainingPlanAnalysisScenario $scenario): TrainingPlanType => $scenario->getPlanType(), $scenarios));
        self::assertContains('run-race-half-marathon-compressed', $scenarioNames);
        self::assertContains('tri-race-half-distance-b-race-taper', $scenarioNames);
        self::assertContains('tri-race-olympic-multi-a-race', $scenarioNames);
    }

    public function testAnalyzerFlagsShallowRecoveryAndContradictoryEasyLabels(): void
    {
        $analyzer = new TrainingPlanQualityAnalyzer();
        $scenario = $this->createScenario();
        $proposal = TrainingPlanProposal::create(
            targetRace: $scenario->getTargetRace(),
            rules: RaceProfileTrainingRules::forProfile(RaceEventProfile::HALF_DISTANCE_TRIATHLON),
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            planEndDay: SerializableDateTime::fromString('2026-04-19 00:00:00'),
            totalWeeks: 2,
            proposedBlocks: [
                ProposedTrainingBlock::create(
                    startDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
                    endDay: SerializableDateTime::fromString('2026-04-19 00:00:00'),
                    phase: TrainingBlockPhase::BUILD,
                    targetRaceEventId: $scenario->getTargetRace()->getId(),
                    title: 'Build',
                    focus: null,
                    weekSkeletons: [
                        ProposedWeekSkeleton::create(
                            weekNumber: 1,
                            startDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
                            endDay: SerializableDateTime::fromString('2026-04-12 00:00:00'),
                            sessions: [
                                $this->createSession('2026-04-06', ActivityType::WATER_SPORTS, PlannedSessionIntensity::EASY, 'Easy swim', 1800),
                                $this->createSession('2026-04-07', ActivityType::RUN, PlannedSessionIntensity::HARD, 'Run tempo', 3600, true),
                                $this->createSession('2026-04-09', ActivityType::RIDE, PlannedSessionIntensity::MODERATE, 'Long bike', 7200, true),
                                $this->createSession('2026-04-12', ActivityType::RUN, PlannedSessionIntensity::MODERATE, 'Long run', 5400, true),
                            ],
                            targetLoadMultiplier: 1.0,
                        ),
                        ProposedWeekSkeleton::create(
                            weekNumber: 2,
                            startDay: SerializableDateTime::fromString('2026-04-13 00:00:00'),
                            endDay: SerializableDateTime::fromString('2026-04-19 00:00:00'),
                            sessions: [
                                $this->createSession('2026-04-13', ActivityType::WATER_SPORTS, PlannedSessionIntensity::EASY, 'Recovery swim', 1500),
                                $this->createSession('2026-04-15', ActivityType::RIDE, PlannedSessionIntensity::HARD, 'Easy bike', 3600, true),
                                $this->createSession('2026-04-17', ActivityType::RUN, PlannedSessionIntensity::EASY, 'Recovery run', 2400),
                            ],
                            targetLoadMultiplier: 0.91,
                            isRecoveryWeek: true,
                        ),
                    ],
                ),
            ],
        );

        $report = $analyzer->analyze($scenario, $proposal);
        $issueCodes = array_map(static fn ($issue): string => $issue->getCode(), $report->getIssues());

        self::assertContains('shallow_recovery_weeks', $issueCodes);
        self::assertContains('contradictory_easy_labels', $issueCodes);
    }

    public function testAnalyzerFlagsTriFocusMismatchForRunFocusedDevelopmentWeek(): void
    {
        $analyzer = new TrainingPlanQualityAnalyzer();
        $scenario = $this->createScenario(type: TrainingPlanType::TRAINING, focus: TrainingFocus::RUN);
        $proposal = TrainingPlanProposal::create(
            targetRace: $scenario->getTargetRace(),
            rules: RaceProfileTrainingRules::forProfile(RaceEventProfile::HALF_DISTANCE_TRIATHLON),
            planStartDay: SerializableDateTime::fromString('2026-07-06 00:00:00'),
            planEndDay: SerializableDateTime::fromString('2026-07-12 00:00:00'),
            totalWeeks: 1,
            proposedBlocks: [
                ProposedTrainingBlock::create(
                    startDay: SerializableDateTime::fromString('2026-07-06 00:00:00'),
                    endDay: SerializableDateTime::fromString('2026-07-12 00:00:00'),
                    phase: TrainingBlockPhase::BUILD,
                    targetRaceEventId: null,
                    title: 'Build',
                    focus: 'Run focus',
                    weekSkeletons: [
                        ProposedWeekSkeleton::create(
                            weekNumber: 1,
                            startDay: SerializableDateTime::fromString('2026-07-06 00:00:00'),
                            endDay: SerializableDateTime::fromString('2026-07-12 00:00:00'),
                            sessions: [
                                $this->createSession('2026-07-06', ActivityType::WATER_SPORTS, PlannedSessionIntensity::EASY, 'Easy swim', 1800),
                                $this->createSession('2026-07-07', ActivityType::RIDE, PlannedSessionIntensity::HARD, 'Bike intervals', 3600, true),
                                $this->createSession('2026-07-09', ActivityType::RIDE, PlannedSessionIntensity::MODERATE, 'Long bike', 7200, true),
                                $this->createSession('2026-07-12', ActivityType::RUN, PlannedSessionIntensity::EASY, 'Easy run', 1800),
                            ],
                            targetLoadMultiplier: 1.0,
                        ),
                    ],
                ),
            ],
        );

        $report = $analyzer->analyze($scenario, $proposal);
        $issueCodes = array_map(static fn ($issue): string => $issue->getCode(), $report->getIssues());

        self::assertContains('focus_imbalance_weeks', $issueCodes);
    }

    public function testAnalyzerPropagatesGeneratorWarningsIntoIssues(): void
    {
        $analyzer = new TrainingPlanQualityAnalyzer();
        $scenario = $this->createScenario();
        $proposal = TrainingPlanProposal::create(
            targetRace: $scenario->getTargetRace(),
            rules: RaceProfileTrainingRules::forProfile(RaceEventProfile::HALF_DISTANCE_TRIATHLON),
            planStartDay: SerializableDateTime::fromString('2026-08-31 00:00:00'),
            planEndDay: SerializableDateTime::fromString('2026-09-27 00:00:00'),
            totalWeeks: 4,
            proposedBlocks: [],
            warnings: [
                PlanAdaptationWarning::create(
                    type: PlanAdaptationWarningType::PLAN_TOO_SHORT,
                    title: 'Plan is shorter than ideal',
                    body: 'The available time is too short for the target profile.',
                    severity: PlanAdaptationWarningSeverity::WARNING,
                ),
                PlanAdaptationWarning::create(
                    type: PlanAdaptationWarningType::MULTIPLE_A_RACES,
                    title: 'Multiple A-races detected',
                    body: 'There are multiple A-priority races in the plan window.',
                    severity: PlanAdaptationWarningSeverity::CRITICAL,
                ),
            ],
        );

        $report = $analyzer->analyze($scenario, $proposal);
        $issueCodes = array_map(static fn ($issue): string => $issue->getCode(), $report->getIssues());

        self::assertContains('plan_too_short', $issueCodes);
        self::assertContains('multiple_a_races', $issueCodes);
        self::assertSame(2, $report->getMetrics()['generatorWarningCount']);
    }

    public function testAnalyzerFlagsHardDayClusteringAndMissingLongSessions(): void
    {
        $analyzer = new TrainingPlanQualityAnalyzer();
        $scenario = $this->createScenario(type: TrainingPlanType::RACE, focus: null, profile: RaceEventProfile::MARATHON);
        $proposal = TrainingPlanProposal::create(
            targetRace: $scenario->getTargetRace(),
            rules: RaceProfileTrainingRules::forProfile(RaceEventProfile::MARATHON),
            planStartDay: SerializableDateTime::fromString('2026-07-06 00:00:00'),
            planEndDay: SerializableDateTime::fromString('2026-07-12 00:00:00'),
            totalWeeks: 1,
            proposedBlocks: [
                ProposedTrainingBlock::create(
                    startDay: SerializableDateTime::fromString('2026-07-06 00:00:00'),
                    endDay: SerializableDateTime::fromString('2026-07-12 00:00:00'),
                    phase: TrainingBlockPhase::BUILD,
                    targetRaceEventId: $scenario->getTargetRace()->getId(),
                    title: 'Build',
                    focus: 'Marathon specific',
                    weekSkeletons: [
                        ProposedWeekSkeleton::create(
                            weekNumber: 1,
                            startDay: SerializableDateTime::fromString('2026-07-06 00:00:00'),
                            endDay: SerializableDateTime::fromString('2026-07-12 00:00:00'),
                            sessions: [
                                $this->createSession('2026-07-06', ActivityType::RUN, PlannedSessionIntensity::HARD, 'Intervals', 3600, true),
                                $this->createSession('2026-07-07', ActivityType::RUN, PlannedSessionIntensity::HARD, 'Hill reps', 3300, true),
                                $this->createSession('2026-07-10', ActivityType::RUN, PlannedSessionIntensity::EASY, 'Easy run', 2400),
                                $this->createSession('2026-07-12', ActivityType::RUN, PlannedSessionIntensity::MODERATE, 'Steady run', 3000, true),
                            ],
                            targetLoadMultiplier: 1.0,
                        ),
                    ],
                ),
            ],
        );

        $report = $analyzer->analyze($scenario, $proposal);
        $issueCodes = array_map(static fn ($issue): string => $issue->getCode(), $report->getIssues());

        self::assertContains('hard_day_clustering', $issueCodes);
        self::assertContains('insufficient_long_sessions', $issueCodes);
    }

    private function createScenario(
        TrainingPlanType $type = TrainingPlanType::RACE,
        ?TrainingFocus $focus = null,
        RaceEventProfile $profile = RaceEventProfile::HALF_DISTANCE_TRIATHLON,
    ): TrainingPlanAnalysisScenario {
        $race = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-09-27 00:00:00'),
            type: RaceEventType::fromProfile($profile),
            title: 'Analysis race',
            location: 'Lab',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 19_800,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $linkedPlan = TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: $type,
            startDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-09-27 00:00:00'),
            targetRaceEventId: TrainingPlanType::RACE === $type ? $race->getId() : null,
            title: 'Analysis scenario',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            discipline: TrainingPlanDiscipline::TRIATHLON,
            sportSchedule: [
                'swimDays' => [1, 4],
                'bikeDays' => [2, 4, 6],
                'runDays' => [2, 5, 7],
                'longRideDays' => [6],
                'longRunDays' => [7],
            ],
            performanceMetrics: [
                'runningThresholdPace' => 255,
                'weeklyRunningVolume' => 48.0,
                'cyclingFtp' => 250,
                'weeklyBikingVolume' => 7.0,
            ],
            targetRaceProfile: $profile,
            trainingFocus: $focus,
        );

        return TrainingPlanAnalysisScenario::create(
            name: 'analysis-scenario',
            label: 'Analysis scenario',
            planType: $type,
            discipline: TrainingPlanDiscipline::TRIATHLON,
            targetRaceProfile: $profile,
            trainingFocus: $focus,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            targetRace: $race,
            allRaceEvents: [$race],
            linkedTrainingPlan: $linkedPlan,
        );
    }

    private function createSession(
        string $day,
        ActivityType $activityType,
        PlannedSessionIntensity $intensity,
        string $title,
        int $durationInSeconds,
        bool $isKey = false,
    ): ProposedSession {
        return ProposedSession::create(
            day: SerializableDateTime::fromString(sprintf('%s 00:00:00', $day)),
            activityType: $activityType,
            targetIntensity: $intensity,
            title: $title,
            notes: null,
            targetDurationInSeconds: $durationInSeconds,
            isKeySession: $isKey,
        );
    }
}
