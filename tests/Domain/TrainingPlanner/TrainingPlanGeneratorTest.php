<?php

declare(strict_types=1);

namespace App\Tests\Domain\TrainingPlanner;

use App\Domain\Activity\ActivityType;
use App\Domain\Dashboard\Widget\TrainingLoad\ReadinessScore;
use App\Domain\TrainingPlanner\AdaptivePlanningContext;
use App\Domain\TrainingPlanner\PlanGenerator\TrainingPlanGenerator;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionEstimationSource;
use App\Domain\TrainingPlanner\PlannedSessionId;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Domain\TrainingPlanner\PlannedSessionLinkStatus;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventId;
use App\Domain\TrainingPlanner\RaceEventPriority;
use App\Domain\TrainingPlanner\RaceReadinessContext;
use App\Domain\TrainingPlanner\RaceEventType;
use App\Domain\TrainingPlanner\RunningWorkoutTargetMode;
use App\Domain\TrainingPlanner\TrainingSession;
use App\Domain\TrainingPlanner\TrainingSessionId;
use App\Domain\TrainingPlanner\TrainingSessionObjective;
use App\Domain\TrainingPlanner\TrainingSessionRecommendationCriteria;
use App\Domain\TrainingPlanner\TrainingSessionRepository;
use App\Domain\TrainingPlanner\TrainingSessionSource;
use App\Domain\TrainingPlanner\TrainingBlockStyle;
use App\Domain\TrainingPlanner\TrainingFocus;
use App\Domain\TrainingPlanner\TrainingBlock;
use App\Domain\TrainingPlanner\TrainingBlockId;
use App\Domain\TrainingPlanner\TrainingBlockPhase;
use App\Domain\TrainingPlanner\TrainingPlan;
use App\Domain\TrainingPlanner\TrainingPlanDiscipline;
use App\Domain\TrainingPlanner\TrainingPlanId;
use App\Domain\TrainingPlanner\TrainingPlanType;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use PHPUnit\Framework\TestCase;

final class TrainingPlanGeneratorTest extends TestCase
{
    public function testGenerateUsesExistingBlocksAsPlanStructure(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = $this->createTargetRace();

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-14 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-02-02', '2026-04-05', TrainingBlockPhase::BASE, $targetRace->getId()),
                $this->createBlock('2026-04-06', '2026-05-17', TrainingBlockPhase::BUILD, $targetRace->getId()),
                $this->createBlock('2026-05-18', '2026-06-07', TrainingBlockPhase::PEAK, $targetRace->getId()),
                $this->createBlock('2026-06-08', '2026-06-21', TrainingBlockPhase::TAPER, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-14 00:00:00'),
        );

        self::assertSame('2026-02-02 00:00:00', $proposal->getPlanStartDay()->format('Y-m-d H:i:s'));
        self::assertCount(5, $proposal->getProposedBlocks());
        self::assertSame('2026-02-02', $proposal->getProposedBlocks()[0]->getStartDay()->format('Y-m-d'));
        self::assertSame(TrainingBlockPhase::RECOVERY, $proposal->getProposedBlocks()[4]->getPhase());
        self::assertSame('2026-07-05', $proposal->getProposedBlocks()[4]->getEndDay()->format('Y-m-d'));
    }

    public function testGenerateKeepsExistingSessionsAsPlannedInUpcomingExistingBlockWeeks(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = $this->createTargetRace();
        $buildBlock = $this->createBlock('2026-04-06', '2026-04-19', TrainingBlockPhase::BUILD, $targetRace->getId());

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-14 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [$buildBlock],
            existingSessions: [
                PlannedSession::create(
                    plannedSessionId: PlannedSessionId::random(),
                    day: SerializableDateTime::fromString('2026-04-15 00:00:00'),
                    activityType: ActivityType::RUN,
                    title: 'Tempo run',
                    notes: null,
                    targetLoad: 72.0,
                    targetDurationInSeconds: 3600,
                    targetIntensity: PlannedSessionIntensity::HARD,
                    templateActivityId: null,
                    estimationSource: PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
                    linkedActivityId: null,
                    linkStatus: PlannedSessionLinkStatus::UNLINKED,
                    createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
                    updatedAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
                ),
            ],
            referenceDate: SerializableDateTime::fromString('2026-04-14 00:00:00'),
        );

        $weekSkeletons = $proposal->getProposedBlocks()[0]->getWeekSkeletons();
        $currentWeekSessions = $weekSkeletons[1]->getSessions();
        $titles = array_map(static fn ($session): ?string => $session->getTitle(), $currentWeekSessions);

        self::assertSame(['Tempo run'], $titles);
        self::assertCount(1, $currentWeekSessions);
    }

    public function testGenerateAddsRecoveryBlockWithRecoveryWorkoutsForCleanSheetPlans(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = $this->createTargetRace();

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-03-09 00:00:00'),
            allRaceEvents: [$targetRace],
        );

        $recoveryBlock = $proposal->getProposedBlocks()[array_key_last($proposal->getProposedBlocks())];

        self::assertSame(TrainingBlockPhase::RECOVERY, $recoveryBlock->getPhase());
        self::assertSame('2026-06-22', $recoveryBlock->getStartDay()->format('Y-m-d'));
        self::assertSame('2026-07-05', $recoveryBlock->getEndDay()->format('Y-m-d'));
        self::assertNotEmpty($recoveryBlock->getWeekSkeletons());

        $firstRecoveryWeekTitles = array_map(
            static fn ($session): ?string => $session->getTitle(),
            $recoveryBlock->getWeekSkeletons()[0]->getSessions(),
        );

        self::assertNotEmpty($firstRecoveryWeekTitles);
        self::assertContains('Recovery swim', $firstRecoveryWeekTitles);
    }

    public function testGenerateKeepsLateRaceWeekSessionsInsideTheSameWeekWindow(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = $this->createTargetRace();
        $bRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-05-24 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Tune-up race',
            location: 'Alkmaar',
            notes: null,
            priority: RaceEventPriority::B,
            targetFinishTimeInSeconds: 5700,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-05-18 00:00:00'),
            allRaceEvents: [$targetRace, $bRace],
            existingBlocks: [
                $this->createBlock('2026-05-18', '2026-05-24', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-05-18 00:00:00'),
        );

        $weekSessions = $proposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions();
        $sessionDays = array_map(
            static fn ($session): string => $session->getDay()->format('Y-m-d'),
            $weekSessions,
        );
        $sortedSessionDays = $sessionDays;
        sort($sortedSessionDays);

        self::assertSame($sortedSessionDays, $sessionDays);
        self::assertNotContains('2026-05-25', $sessionDays);
        self::assertNotContains('2026-05-26', $sessionDays);
        self::assertSame('2026-05-24', max($sessionDays));
    }

    public function testGenerateBuildsTwoLongerLongSessionsForHalfDistanceBuildWeek(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = $this->createTargetRace();

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-04-12', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
        );

        $weekSessions = $proposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions();
        $titles = array_map(static fn ($session): ?string => $session->getTitle(), $weekSessions);

        self::assertGreaterThanOrEqual(8, count($weekSessions));
        self::assertContains('Long bike', $titles);
        self::assertContains('Long run', $titles);

        $longBike = $this->findSessionByTitle($weekSessions, 'Long bike');
        $longRun = $this->findSessionByTitle($weekSessions, 'Long run');
        $bikeIntervals = $this->findSessionByTitle($weekSessions, 'Bike intervals');

        self::assertNotNull($longBike);
        self::assertNotNull($longRun);
        self::assertNotNull($bikeIntervals);
        self::assertGreaterThanOrEqual(10_800, $longBike->getTargetDurationInSeconds());
        self::assertGreaterThanOrEqual(6_900, $longRun->getTargetDurationInSeconds());
        self::assertGreaterThanOrEqual(6_800, $bikeIntervals->getTargetDurationInSeconds());
    }

    public function testGenerateKeepsRecoveryCadenceAcrossBlockBoundaries(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_DISTANCE_TRIATHLON,
            title: 'West Friesland',
            location: 'Hoorn',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 19_800,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-01-05 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-01-05', '2026-03-08', TrainingBlockPhase::BASE, $targetRace->getId()),
                $this->createBlock('2026-03-09', '2026-03-29', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2025-12-29 00:00:00'),
        );

        $baseWeekSkeletons = $proposal->getProposedBlocks()[0]->getWeekSkeletons();
        $buildWeekSkeletons = $proposal->getProposedBlocks()[1]->getWeekSkeletons();

        self::assertCount(9, $baseWeekSkeletons);
        self::assertCount(3, $buildWeekSkeletons);
        self::assertTrue($baseWeekSkeletons[3]->isRecoveryWeek());
        self::assertTrue($baseWeekSkeletons[7]->isRecoveryWeek());
        self::assertFalse($buildWeekSkeletons[1]->isRecoveryWeek());
        self::assertTrue($buildWeekSkeletons[2]->isRecoveryWeek());
        self::assertLessThan($buildWeekSkeletons[1]->getTargetLoadPercentage(), $buildWeekSkeletons[2]->getTargetLoadPercentage());
        self::assertCount(5, $buildWeekSkeletons[2]->getSessions());

        $recoveryWeekTitles = array_map(static fn ($session): ?string => $session->getTitle(), $buildWeekSkeletons[2]->getSessions());

        self::assertNotContains('Brick run', $recoveryWeekTitles);
    }

    public function testGenerateBuildsDetailedLighterTriathlonTaperWeeks(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = $this->createTargetRace();

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-03-09 00:00:00'),
            allRaceEvents: [$targetRace],
        );

        $taperBlocks = array_values(array_filter(
            $proposal->getProposedBlocks(),
            static fn ($block): bool => TrainingBlockPhase::TAPER === $block->getPhase(),
        ));

        self::assertCount(1, $taperBlocks);

        $taperBlock = $taperBlocks[0];
        self::assertCount(2, $taperBlock->getWeekSkeletons());

        $firstWeekTitles = array_map(
            static fn ($session): ?string => $session->getTitle(),
            $taperBlock->getWeekSkeletons()[0]->getSessions(),
        );
        $raceWeekTitles = array_map(
            static fn ($session): ?string => $session->getTitle(),
            $taperBlock->getWeekSkeletons()[1]->getSessions(),
        );

        self::assertSame(
            ['Easy taper flush', 'Swim rhythm tune-up', 'Bike race-pace cutdown', 'Run race-pace cutdown', 'Reduced endurance ride', 'Controlled long run'],
            $firstWeekTitles,
        );
        self::assertSame(
            ['Swim rhythm touch', 'Bike openers', 'Run opener', 'West Friesland'],
            $raceWeekTitles,
        );
        self::assertCount(6, $taperBlock->getWeekSkeletons()[0]->getSessions());
        self::assertCount(4, $taperBlock->getWeekSkeletons()[1]->getSessions());

        $firstTaperWeekSessions = $taperBlock->getWeekSkeletons()[0]->getSessions();
        $bikeSharpener = $this->findSessionByTitle($firstTaperWeekSessions, 'Bike race-pace cutdown');
        $reducedRide = $this->findSessionByTitle($firstTaperWeekSessions, 'Reduced endurance ride');
        $controlledLongRun = $this->findSessionByTitle($firstTaperWeekSessions, 'Controlled long run');

        self::assertNotNull($bikeSharpener);
        self::assertNotNull($reducedRide);
        self::assertNotNull($controlledLongRun);
        self::assertTrue($bikeSharpener->hasWorkoutSteps());
        self::assertSame(3_960, $bikeSharpener->getTargetDurationInSeconds());
        self::assertGreaterThanOrEqual(12_600, $reducedRide->getTargetDurationInSeconds());
        self::assertGreaterThanOrEqual(5_700, $controlledLongRun->getTargetDurationInSeconds());

        $firstWeekBikeVolume = array_reduce(
            $firstTaperWeekSessions,
            static fn (int $carry, $session): int => ActivityType::RIDE === $session->getActivityType()
                ? $carry + ($session->getTargetDurationInSeconds() ?? 0)
                : $carry,
            0,
        );

        self::assertGreaterThanOrEqual(18_000, $firstWeekBikeVolume);

        $previewHeadlines = array_map(
            static fn (array $row): string => $row['headline'],
            $bikeSharpener->getWorkoutPreviewRows(),
        );

        self::assertContains('Warm-up · Easy spin + cadence lift', $previewHeadlines);
        self::assertContains('3x block', $previewHeadlines);
    }

    public function testGenerateUsesReadableFallbackTitleForUntitledHalfMarathonBRace(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = $this->createTargetRace();
        $bRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-05-10 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: null,
            location: 'Zandvoort',
            notes: null,
            priority: RaceEventPriority::B,
            targetFinishTimeInSeconds: 5400,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$bRace, $targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-05-17', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
        );

        $raceWeekSessions = $proposal->getProposedBlocks()[0]->getWeekSkeletons()[4]->getSessions();
        $raceSession = $this->findSessionByTitle($raceWeekSessions, 'Half marathon');

        self::assertNotNull($raceSession);
        self::assertSame(PlannedSessionIntensity::RACE, $raceSession->getTargetIntensity());
        self::assertSame('B race', $raceSession->getNotes());
    }

    public function testGenerateBuildsRunSpecificRaceWeekTaperSessions(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-10-18 00:00:00'),
            type: RaceEventType::MARATHON,
            title: 'Amsterdam Marathon',
            location: 'Amsterdam',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 11_400,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-06-01 00:00:00'),
            allRaceEvents: [$targetRace],
        );

        $taperBlocks = array_values(array_filter(
            $proposal->getProposedBlocks(),
            static fn ($block): bool => TrainingBlockPhase::TAPER === $block->getPhase(),
        ));

        self::assertCount(1, $taperBlocks);

        $taperWeeks = $taperBlocks[0]->getWeekSkeletons();
        self::assertCount(3, $taperWeeks);

        $raceWeekTitles = array_map(
            static fn ($session): ?string => $session->getTitle(),
            $taperWeeks[2]->getSessions(),
        );

        self::assertSame(
            ['Race-pace touch', 'Easy run + strides', 'Shakeout run', 'Amsterdam Marathon'],
            $raceWeekTitles,
        );

        $controlledLongRun = $this->findSessionByTitle($taperWeeks[0]->getSessions(), 'Controlled long run');
        self::assertNotNull($controlledLongRun);
        self::assertSame(5_400, $controlledLongRun->getTargetDurationInSeconds());
        self::assertTrue($controlledLongRun->hasWorkoutSteps());
    }

    public function testGeneratePlacesLongRunOnPreferredLongRunDayFromLinkedTrainingPlan(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Half marathon target',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5_700,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-04-12', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::RUNNING,
                sportSchedule: ['runDays' => [2, 4], 'longRunDays' => [7]],
                performanceMetrics: ['runningThresholdPace' => 255, 'weeklyRunningVolume' => 52.0],
                targetRaceProfile: $targetRace->getProfile(),
            ),
        );

        $weekSessions = $proposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions();
        $longRun = $this->findSessionByTitle($weekSessions, 'Long run');
        $runIntervals = $this->findSessionByTitle($weekSessions, 'Run intervals');

        self::assertNotNull($longRun);
        self::assertSame('2026-04-12', $longRun->getDay()->format('Y-m-d'));
        self::assertNotNull($runIntervals);
        self::assertStringContainsString('4:15/km', (string) $runIntervals->getNotes());
    }

    public function testGenerateAdjustsRunVolumeDurationsFromLinkedTrainingPlanMetrics(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Half marathon target',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5_700,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $highVolumeProposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-04-12', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::RUNNING,
                performanceMetrics: ['weeklyRunningVolume' => 60.0],
                targetRaceProfile: $targetRace->getProfile(),
            ),
        );
        $lowVolumeProposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-04-12', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::RUNNING,
                performanceMetrics: ['weeklyRunningVolume' => 16.0],
                targetRaceProfile: $targetRace->getProfile(),
            ),
        );

        $highVolumeLongRun = $this->findSessionByTitle($highVolumeProposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions(), 'Long run');
        $lowVolumeLongRun = $this->findSessionByTitle($lowVolumeProposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions(), 'Long run');

        self::assertNotNull($highVolumeLongRun);
        self::assertNotNull($lowVolumeLongRun);
        self::assertGreaterThan($lowVolumeLongRun->getTargetDurationInSeconds(), $highVolumeLongRun->getTargetDurationInSeconds());
    }

    public function testGenerateKeepsHighVolumeAthletesCloserToBaselineAcrossBaseAndBuildWeeks(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Half marathon target',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5_700,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $baselineProposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-04-19', TrainingBlockPhase::BASE, $targetRace->getId()),
                $this->createBlock('2026-04-20', '2026-04-26', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
        );
        $highVolumeProposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-04-19', TrainingBlockPhase::BASE, $targetRace->getId()),
                $this->createBlock('2026-04-20', '2026-04-26', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::RUNNING,
                sportSchedule: ['runDays' => [2, 3, 5, 7], 'longRunDays' => [7]],
                performanceMetrics: ['weeklyRunningVolume' => 68.0],
                targetRaceProfile: $targetRace->getProfile(),
            ),
        );

        $baselineBaseLongRun = $this->findSessionByTitle($baselineProposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions(), 'Long run');
        $highVolumeBaseLongRun = $this->findSessionByTitle($highVolumeProposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions(), 'Long run');
        $baselineBuildLongRun = $this->findSessionByTitle($baselineProposal->getProposedBlocks()[1]->getWeekSkeletons()[0]->getSessions(), 'Long run');
        $highVolumeBuildLongRun = $this->findSessionByTitle($highVolumeProposal->getProposedBlocks()[1]->getWeekSkeletons()[0]->getSessions(), 'Long run');

        self::assertNotNull($baselineBaseLongRun);
        self::assertNotNull($highVolumeBaseLongRun);
        self::assertNotNull($baselineBuildLongRun);
        self::assertNotNull($highVolumeBuildLongRun);
        self::assertGreaterThan($baselineBaseLongRun->getTargetDurationInSeconds(), $highVolumeBaseLongRun->getTargetDurationInSeconds());
        self::assertGreaterThan($baselineBuildLongRun->getTargetDurationInSeconds(), $highVolumeBuildLongRun->getTargetDurationInSeconds());
        self::assertGreaterThanOrEqual(
            count($baselineProposal->getProposedBlocks()[1]->getWeekSkeletons()[0]->getSessions()),
            count($highVolumeProposal->getProposedBlocks()[1]->getWeekSkeletons()[0]->getSessions()),
        );
    }

    public function testGenerateUsesAdaptiveHistoryWhenLinkedPlanMetricsAreMissing(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Half marathon target',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5_700,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $baselineProposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-04-12', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::RUNNING,
                performanceMetrics: [],
                targetRaceProfile: $targetRace->getProfile(),
            ),
        );
        $historyBackedProposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-04-12', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::RUNNING,
                performanceMetrics: [],
                targetRaceProfile: $targetRace->getProfile(),
            ),
            adaptivePlanningContext: new AdaptivePlanningContext(
                currentWeekReadinessContext: $this->createReadinessContext(),
                historicalWeeklyRunningVolume: 64.0,
                historicalWeeklyBikingVolume: null,
            ),
        );

        $baselineLongRun = $this->findSessionByTitle($baselineProposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions(), 'Long run');
        $historyBackedLongRun = $this->findSessionByTitle($historyBackedProposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions(), 'Long run');

        self::assertNotNull($baselineLongRun);
        self::assertNotNull($historyBackedLongRun);
        self::assertGreaterThan($baselineLongRun->getTargetDurationInSeconds(), $historyBackedLongRun->getTargetDurationInSeconds());
    }

    public function testGenerateAppliesAdaptiveRecoveryPenaltyOnlyToImmediateWeeks(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Half marathon target',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5_700,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $freshProposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-04-19', TrainingBlockPhase::BUILD, $targetRace->getId()),
                $this->createBlock('2026-04-20', '2026-04-26', TrainingBlockPhase::PEAK, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::RUNNING,
                performanceMetrics: [],
                targetRaceProfile: $targetRace->getProfile(),
            ),
            adaptivePlanningContext: new AdaptivePlanningContext(
                currentWeekReadinessContext: $this->createReadinessContext(),
                historicalWeeklyRunningVolume: 64.0,
                historicalWeeklyBikingVolume: null,
            ),
        );
        $fatiguedProposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-04-19', TrainingBlockPhase::BUILD, $targetRace->getId()),
                $this->createBlock('2026-04-20', '2026-04-26', TrainingBlockPhase::PEAK, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::RUNNING,
                performanceMetrics: [],
                targetRaceProfile: $targetRace->getProfile(),
            ),
            adaptivePlanningContext: new AdaptivePlanningContext(
                currentWeekReadinessContext: $this->createReadinessContext(
                    readinessScore: ReadinessScore::of(34),
                    forecastDaysUntilTsbHealthy: 4,
                    forecastDaysUntilAcRatioHealthy: 3,
                ),
                historicalWeeklyRunningVolume: 64.0,
                historicalWeeklyBikingVolume: null,
            ),
        );

        $freshFirstWeekLongRun = $this->findSessionByTitle($freshProposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions(), 'Long run');
        $fatiguedFirstWeekLongRun = $this->findSessionByTitle($fatiguedProposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions(), 'Long run');
        $freshThirdWeekLongRun = $this->findSessionByTitle($freshProposal->getProposedBlocks()[1]->getWeekSkeletons()[0]->getSessions(), 'Long run');
        $fatiguedThirdWeekLongRun = $this->findSessionByTitle($fatiguedProposal->getProposedBlocks()[1]->getWeekSkeletons()[0]->getSessions(), 'Long run');

        self::assertNotNull($freshFirstWeekLongRun);
        self::assertNotNull($fatiguedFirstWeekLongRun);
        self::assertNotNull($freshThirdWeekLongRun);
        self::assertNotNull($fatiguedThirdWeekLongRun);
        self::assertGreaterThan($fatiguedFirstWeekLongRun->getTargetDurationInSeconds(), $freshFirstWeekLongRun->getTargetDurationInSeconds());
        self::assertSame($freshThirdWeekLongRun->getTargetDurationInSeconds(), $fatiguedThirdWeekLongRun->getTargetDurationInSeconds());
    }

    public function testGenerateUsesRecommendedLibrarySessionForBuildWorkoutChoice(): void
    {
        $recommendedBuildSession = $this->createTrainingSessionRecommendation(
            activityType: ActivityType::RUN,
            title: 'Hill reps',
            notes: 'Use your proven uphill repeat session.',
            targetDurationInSeconds: 3_900,
            targetIntensity: PlannedSessionIntensity::HARD,
            sessionPhase: TrainingBlockPhase::BUILD,
            sessionObjective: TrainingSessionObjective::HIGH_INTENSITY,
            workoutSteps: [
                ['type' => 'warmup', 'targetType' => 'time', 'durationInSeconds' => 900, 'label' => 'Easy jog + drills'],
                ['type' => 'repeatBlock', 'repetitions' => 6, 'steps' => [
                    ['type' => 'interval', 'targetType' => 'time', 'durationInSeconds' => 60, 'label' => 'Uphill surge'],
                    ['type' => 'recovery', 'targetType' => 'time', 'durationInSeconds' => 120, 'label' => 'Jog down'],
                ]],
                ['type' => 'cooldown', 'targetType' => 'time', 'durationInSeconds' => 600, 'label' => 'Easy finish'],
            ],
        );
        $generator = new TrainingPlanGenerator($this->createTrainingSessionRepositoryStub(static function (
            ActivityType $activityType,
            ?TrainingSessionRecommendationCriteria $criteria,
            int $limit,
        ) use ($recommendedBuildSession): array {
            if (ActivityType::RUN !== $activityType) {
                return [];
            }

            if (TrainingBlockPhase::BUILD !== $criteria?->getSessionPhase()) {
                return [];
            }

            if (TrainingSessionObjective::HIGH_INTENSITY !== $criteria?->getSessionObjective()) {
                return [];
            }

            if (PlannedSessionIntensity::HARD !== $criteria?->getTargetIntensity()) {
                return [];
            }

            return [$recommendedBuildSession];
        }));
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Half marathon target',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5_700,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-04-12', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::RUNNING,
                performanceMetrics: ['weeklyRunningVolume' => 52.0],
                targetRaceProfile: $targetRace->getProfile(),
            ),
        );

        $weekSessions = $proposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions();
        $recommendedSession = $this->findSessionByTitle($weekSessions, 'Hill reps');

        self::assertNotNull($recommendedSession);
        self::assertTrue($recommendedSession->hasWorkoutSteps());
        self::assertSame(3_900, $recommendedSession->getTargetDurationInSeconds());
        self::assertStringContainsString('proven uphill repeat session', (string) $recommendedSession->getNotes());
    }

    public function testGenerateAvoidsReusingSameLibraryWorkoutTwiceInOneWeek(): void
    {
        $recommendedSession = $this->createTrainingSessionRecommendation(
            activityType: ActivityType::RUN,
            title: 'Aerobic cruise',
            notes: 'Steady aerobic control.',
            targetDurationInSeconds: 3_000,
            targetIntensity: PlannedSessionIntensity::EASY,
            sessionPhase: TrainingBlockPhase::BUILD,
            sessionObjective: TrainingSessionObjective::ENDURANCE,
            workoutSteps: [
                ['type' => 'warmup', 'targetType' => 'time', 'durationInSeconds' => 300, 'label' => 'Settle in'],
                ['type' => 'steady', 'targetType' => 'time', 'durationInSeconds' => 2_400, 'label' => 'Aerobic cruise'],
                ['type' => 'cooldown', 'targetType' => 'time', 'durationInSeconds' => 300, 'label' => 'Easy finish'],
            ],
        );
        $generator = new TrainingPlanGenerator($this->createTrainingSessionRepositoryStub(static function (
            ActivityType $activityType,
            ?TrainingSessionRecommendationCriteria $criteria,
            int $limit,
        ) use ($recommendedSession): array {
            if (ActivityType::RUN !== $activityType) {
                return [];
            }

            if (TrainingSessionObjective::ENDURANCE !== $criteria?->getSessionObjective()) {
                return [];
            }

            return [$recommendedSession];
        }));
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Half marathon target',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5_700,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-04-12', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::RUNNING,
                performanceMetrics: ['weeklyRunningVolume' => 52.0],
                targetRaceProfile: $targetRace->getProfile(),
            ),
        );

        $weekSessions = $proposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions();
        $matchingRecommendationTitles = array_values(array_filter(
            array_map(static fn ($session): ?string => $session->getTitle(), $weekSessions),
            static fn (?string $title): bool => 'Aerobic cruise' === $title,
        ));

        self::assertCount(1, $matchingRecommendationTitles);
    }

    public function testGenerateUsesDevelopmentPeriodizationForTrainingPlansWithoutLinkedRace(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = $this->createTargetRace();

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-07-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-07-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::TRIATHLON,
                sportSchedule: [
                    'swimDays' => [1, 3, 5],
                    'bikeDays' => [1, 4, 6],
                    'runDays' => [2, 4, 6, 7],
                    'longRideDays' => [6],
                    'longRunDays' => [7],
                ],
                performanceMetrics: [
                    'cyclingFtp' => 253,
                    'runningThresholdPace' => 255,
                    'weeklyRunningVolume' => 52.0,
                    'weeklyBikingVolume' => 8.5,
                ],
                targetRaceProfile: $targetRace->getProfile(),
                trainingFocus: TrainingFocus::RUN,
                type: TrainingPlanType::TRAINING,
            ),
        );

        $phases = array_map(
            static fn ($block): TrainingBlockPhase => $block->getPhase(),
            $proposal->getProposedBlocks(),
        );

        self::assertSame([TrainingBlockPhase::BASE, TrainingBlockPhase::BUILD], $phases);
        self::assertGreaterThan(
            $proposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getTargetLoadPercentage(),
            $proposal->getProposedBlocks()[1]->getWeekSkeletons()[0]->getTargetLoadPercentage(),
        );
        self::assertGreaterThanOrEqual(
            8,
            max(array_map(
                static fn ($week): int => $week->getSessionCount(),
                $proposal->getProposedBlocks()[1]->getWeekSkeletons(),
            )),
        );
    }

    public function testGenerateBiasesDevelopmentTriathlonWeeksTowardTheConfiguredFocusWithoutDroppingSupportDisciplines(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = $this->createTargetRace();

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-07-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-07-06', '2026-07-12', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-07-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::TRIATHLON,
                sportSchedule: [
                    'swimDays' => [1, 4, 7],
                    'bikeDays' => [1, 3, 5, 6],
                    'runDays' => [2, 4, 6, 7],
                    'longRideDays' => [6],
                    'longRunDays' => [7],
                ],
                performanceMetrics: [
                    'cyclingFtp' => 253,
                    'runningThresholdPace' => 255,
                    'weeklyRunningVolume' => 52.0,
                    'weeklyBikingVolume' => 6.0,
                ],
                targetRaceProfile: $targetRace->getProfile(),
                trainingFocus: TrainingFocus::RUN,
                type: TrainingPlanType::TRAINING,
            ),
        );

        $weekSessions = $proposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions();
        $disciplineCounts = array_count_values(array_map(
            static fn ($session): string => $session->getActivityType()->value,
            $weekSessions,
        ));

        self::assertGreaterThan(($disciplineCounts[ActivityType::RIDE->value] ?? 0), $disciplineCounts[ActivityType::RUN->value] ?? 0);
        self::assertGreaterThan(($disciplineCounts[ActivityType::WATER_SPORTS->value] ?? 0), $disciplineCounts[ActivityType::RUN->value] ?? 0);
        self::assertGreaterThanOrEqual(2, $disciplineCounts[ActivityType::WATER_SPORTS->value] ?? 0);
        self::assertGreaterThanOrEqual(2, $disciplineCounts[ActivityType::RIDE->value] ?? 0);
    }

    public function testGenerateDoesNotMarkEasyStructuredExistingSessionsAsKeyByDefault(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = $this->createTargetRace();
        $buildBlock = $this->createBlock('2026-04-06', '2026-04-12', TrainingBlockPhase::BUILD, $targetRace->getId());

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [$buildBlock],
            existingSessions: [
                PlannedSession::create(
                    plannedSessionId: PlannedSessionId::random(),
                    day: SerializableDateTime::fromString('2026-04-08 00:00:00'),
                    activityType: ActivityType::RUN,
                    title: 'Easy run',
                    notes: 'Keep it conversational.',
                    targetLoad: 22.0,
                    targetDurationInSeconds: 2_400,
                    targetIntensity: PlannedSessionIntensity::EASY,
                    templateActivityId: null,
                    estimationSource: PlannedSessionEstimationSource::WORKOUT_TARGETS,
                    linkedActivityId: null,
                    linkStatus: PlannedSessionLinkStatus::UNLINKED,
                    createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
                    updatedAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
                    workoutSteps: [
                        ['type' => 'warmup', 'targetType' => 'time', 'durationInSeconds' => 300, 'label' => 'Easy settle'],
                        ['type' => 'steady', 'targetType' => 'time', 'durationInSeconds' => 1_800, 'label' => 'Easy aerobic'],
                        ['type' => 'cooldown', 'targetType' => 'time', 'durationInSeconds' => 300, 'label' => 'Easy finish'],
                    ],
                ),
            ],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
        );

        $weekSessions = $proposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions();

        self::assertCount(1, $weekSessions);
        self::assertFalse($weekSessions[0]->isKeySession());
    }

    public function testGenerateAllowsDifferentDisciplinesOnTheSamePreferredDay(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = $this->createTargetRace();

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-04-12', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::TRIATHLON,
                sportSchedule: [
                    'swimDays' => [1],
                    'bikeDays' => [1],
                    'runDays' => [2, 4, 7],
                    'longRideDays' => [1],
                    'longRunDays' => [7],
                ],
                performanceMetrics: ['cyclingFtp' => 253, 'runningThresholdPace' => 255],
                targetRaceProfile: $targetRace->getProfile(),
            ),
        );

        $weekSessions = $proposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions();
        $mondaySessions = array_values(array_filter(
            $weekSessions,
            static fn ($session): bool => '2026-04-06' === $session->getDay()->format('Y-m-d'),
        ));
        $mondayActivityTypes = array_map(
            static fn ($session): string => $session->getActivityType()->value,
            $mondaySessions,
        );

        self::assertContains(ActivityType::WATER_SPORTS->value, $mondayActivityTypes);
        self::assertContains(ActivityType::RIDE->value, $mondayActivityTypes);
    }

    public function testGenerateKeepsRunFocusedTriathlonLongRunOnSundayAndHardSessionsMidweek(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = $this->createTargetRace();

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-04-12', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::TRIATHLON,
                sportSchedule: [
                    'swimDays' => [1, 4],
                    'bikeDays' => [2, 4, 6],
                    'runDays' => [2, 4, 6, 7],
                    'longRideDays' => [6],
                    'longRunDays' => [7],
                ],
                performanceMetrics: ['cyclingFtp' => 253, 'runningThresholdPace' => 255, 'weeklyRunningVolume' => 52.0],
                targetRaceProfile: $targetRace->getProfile(),
                trainingFocus: TrainingFocus::RUN,
            ),
        );

        $weekSessions = $proposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions();
        $longRun = $this->findSessionByTitle($weekSessions, 'Long run');
        $hardSessions = array_values(array_filter(
            $weekSessions,
            static fn ($session): bool => PlannedSessionIntensity::HARD === $session->getTargetIntensity(),
        ));
        $hardSessionTitles = array_map(static fn ($session): ?string => $session->getTitle(), $hardSessions);
        $hardSessionDays = array_map(static fn ($session): int => (int) $session->getDay()->format('N'), $hardSessions);

        self::assertNotNull($longRun);
        self::assertSame('2026-04-12', $longRun->getDay()->format('Y-m-d'));
        self::assertContains('Run intervals', $hardSessionTitles);
        self::assertContains('Bike intervals', $hardSessionTitles);
        self::assertNotContains(1, $hardSessionDays);
        self::assertContains(2, $hardSessionDays);
        self::assertContains(4, $hardSessionDays);
    }

    public function testGeneratePrioritizesLongRunForRunFocusedOlympicTriathlonPlans(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-10-25 00:00:00'),
            type: RaceEventType::OLYMPIC_TRIATHLON,
            title: 'Olympic triathlon target',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 9_000,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-08-17 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-08-17', '2026-08-23', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-08-10 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::TRIATHLON,
                sportSchedule: [
                    'swimDays' => [1, 4],
                    'bikeDays' => [2, 4, 6],
                    'runDays' => [2, 4, 6, 7],
                    'longRideDays' => [6],
                    'longRunDays' => [7],
                ],
                performanceMetrics: ['cyclingFtp' => 253, 'runningThresholdPace' => 255, 'weeklyRunningVolume' => 44.9],
                targetRaceProfile: $targetRace->getProfile(),
                trainingFocus: TrainingFocus::RUN,
                type: TrainingPlanType::TRAINING,
            ),
        );

        $weekSessions = $proposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions();
        $longRun = $this->findSessionByTitle($weekSessions, 'Long run');
        $longBike = $this->findSessionByTitle($weekSessions, 'Long bike');

        self::assertNotNull($longRun);
        self::assertSame('2026-08-23', $longRun->getDay()->format('Y-m-d'));
        self::assertNull($longBike);
    }

    public function testGenerateKeepsLongRideAndLongRunInSpeedEnduranceTriathlonBlocks(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-10-25 00:00:00'),
            type: RaceEventType::OLYMPIC_TRIATHLON,
            title: 'Olympic triathlon target',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 9_000,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-08-17 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-08-17', '2026-08-23', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-08-10 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::TRIATHLON,
                sportSchedule: [
                    'swimDays' => [1, 4],
                    'bikeDays' => [2, 4, 6],
                    'runDays' => [2, 4, 6, 7],
                    'longRideDays' => [6],
                    'longRunDays' => [7],
                ],
                performanceMetrics: ['cyclingFtp' => 253, 'runningThresholdPace' => 255, 'weeklyRunningVolume' => 44.9],
                targetRaceProfile: $targetRace->getProfile(),
                trainingFocus: TrainingFocus::RUN,
                type: TrainingPlanType::TRAINING,
                trainingBlockStyle: TrainingBlockStyle::SPEED_ENDURANCE,
            ),
        );

        $weekSessions = $proposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions();
        $longRun = $this->findSessionByTitle($weekSessions, 'Long run');
        $longBike = $this->findSessionByTitle($weekSessions, 'Long bike');

        self::assertNotNull($longRun);
        self::assertNotNull($longBike);
    }

    public function testGenerateSpeedEnduranceRunBlockShortensLongRunDuration(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Half marathon target',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5_700,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $balancedProposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-04-12', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::RUNNING,
                performanceMetrics: ['weeklyRunningVolume' => 52.0],
                targetRaceProfile: $targetRace->getProfile(),
                trainingFocus: TrainingFocus::RUN,
                type: TrainingPlanType::TRAINING,
            ),
        );
        $speedEnduranceProposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-04-12', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::RUNNING,
                performanceMetrics: ['weeklyRunningVolume' => 52.0],
                targetRaceProfile: $targetRace->getProfile(),
                trainingFocus: TrainingFocus::RUN,
                type: TrainingPlanType::TRAINING,
                trainingBlockStyle: TrainingBlockStyle::SPEED_ENDURANCE,
            ),
        );

        $balancedLongRun = $this->findSessionByTitle($balancedProposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions(), 'Long run');
        $speedEnduranceLongRun = $this->findSessionByTitle($speedEnduranceProposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions(), 'Long run');

        self::assertNotNull($balancedLongRun);
        self::assertNotNull($speedEnduranceLongRun);
        self::assertLessThan($balancedLongRun->getTargetDurationInSeconds(), $speedEnduranceLongRun->getTargetDurationInSeconds());
    }

    public function testGenerateAddsEasyEasyDoubleRunForPreparedRunBlocks(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Half marathon target',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5_700,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-04-12', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::RUNNING,
                sportSchedule: ['runDays' => [1, 2, 4, 6, 7], 'longRunDays' => [7]],
                performanceMetrics: ['runningThresholdPace' => 255, 'weeklyRunningVolume' => 46.0],
                targetRaceProfile: $targetRace->getProfile(),
                type: TrainingPlanType::TRAINING,
            ),
        );

        $weekSessions = $proposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions();
        $secondaryEasyRun = $this->findSessionByTitle($weekSessions, 'Secondary easy run');

        self::assertNotNull($secondaryEasyRun);

        $sameDayRuns = $this->findSessionsOnDay($weekSessions, $secondaryEasyRun->getDay()->format('Y-m-d'), ActivityType::RUN);

        self::assertCount(2, $sameDayRuns);
        self::assertSame(
            [PlannedSessionIntensity::EASY, PlannedSessionIntensity::EASY],
            array_values(array_map(static fn ($session): PlannedSessionIntensity => $session->getTargetIntensity(), $sameDayRuns)),
        );
    }

    public function testGenerateAddsEasyThresholdDoubleRunForSpeedEnduranceRunBlocks(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Half marathon target',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5_700,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-04-12', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::RUNNING,
                sportSchedule: ['runDays' => [1, 2, 4, 6, 7], 'longRunDays' => [7]],
                performanceMetrics: ['runningThresholdPace' => 255, 'weeklyRunningVolume' => 58.0],
                targetRaceProfile: $targetRace->getProfile(),
                trainingFocus: TrainingFocus::RUN,
                type: TrainingPlanType::TRAINING,
                trainingBlockStyle: TrainingBlockStyle::SPEED_ENDURANCE,
            ),
        );

        $weekSessions = $proposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions();
        $secondaryEasyRun = $this->findSessionByTitle($weekSessions, 'Secondary easy run');

        self::assertNotNull($secondaryEasyRun);

        $sameDayRuns = $this->findSessionsOnDay($weekSessions, $secondaryEasyRun->getDay()->format('Y-m-d'), ActivityType::RUN);

        self::assertCount(2, $sameDayRuns);
        self::assertContains(PlannedSessionIntensity::HARD, array_map(static fn ($session): PlannedSessionIntensity => $session->getTargetIntensity(), $sameDayRuns));
        self::assertContains(PlannedSessionIntensity::EASY, array_map(static fn ($session): PlannedSessionIntensity => $session->getTargetIntensity(), $sameDayRuns));
    }

    public function testGenerateAddsThresholdThresholdDoubleRunOnlyForVeryPreparedSpeedEnduranceRunBlocks(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Half marathon target',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5_700,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-04-12', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::RUNNING,
                sportSchedule: ['runDays' => [1, 2, 3, 4, 6, 7], 'longRunDays' => [7]],
                performanceMetrics: ['runningThresholdPace' => 255, 'weeklyRunningVolume' => 78.0],
                targetRaceProfile: $targetRace->getProfile(),
                trainingFocus: TrainingFocus::RUN,
                type: TrainingPlanType::TRAINING,
                trainingBlockStyle: TrainingBlockStyle::SPEED_ENDURANCE,
            ),
        );

        $weekSessions = $proposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions();
        $secondaryThresholdRun = $this->findSessionByTitle($weekSessions, 'Secondary threshold run');

        self::assertNotNull($secondaryThresholdRun);
        self::assertFalse($secondaryThresholdRun->isKeySession());

        $sameDayRuns = $this->findSessionsOnDay($weekSessions, $secondaryThresholdRun->getDay()->format('Y-m-d'), ActivityType::RUN);

        self::assertCount(2, $sameDayRuns);
        self::assertSame(
            [PlannedSessionIntensity::HARD, PlannedSessionIntensity::HARD],
            array_values(array_map(static fn ($session): PlannedSessionIntensity => $session->getTargetIntensity(), $sameDayRuns)),
        );
    }

    public function testGenerateKeepsLowVolumeRunBlocksOnSingleRunDays(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Half marathon target',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5_700,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-04-12', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::RUNNING,
                sportSchedule: ['runDays' => [1, 2, 4, 6, 7], 'longRunDays' => [7]],
                performanceMetrics: ['runningThresholdPace' => 255, 'weeklyRunningVolume' => 32.0],
                targetRaceProfile: $targetRace->getProfile(),
                trainingFocus: TrainingFocus::RUN,
                type: TrainingPlanType::TRAINING,
                trainingBlockStyle: TrainingBlockStyle::SPEED_ENDURANCE,
            ),
        );

        $weekSessions = $proposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions();

        self::assertNull($this->findSessionByTitle($weekSessions, 'Secondary easy run'));
        self::assertNull($this->findSessionByTitle($weekSessions, 'Secondary threshold run'));
    }

    public function testGenerateAddsStructuredTargetsToGenericGeneratedRunAndBikeSessions(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = $this->createTargetRace();

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-04-12', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::TRIATHLON,
                sportSchedule: [
                    'swimDays' => [1, 3],
                    'bikeDays' => [1, 4, 6],
                    'runDays' => [2, 4, 7],
                    'longRideDays' => [6],
                    'longRunDays' => [7],
                ],
                performanceMetrics: ['cyclingFtp' => 253, 'runningThresholdPace' => 255],
                targetRaceProfile: $targetRace->getProfile(),
            ),
        );

        $weekSessions = $proposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions();
        $runIntervals = $this->findSessionByTitle($weekSessions, 'Run intervals');
        $bikeIntervals = $this->findSessionByTitle($weekSessions, 'Bike intervals');

        self::assertNotNull($runIntervals);
        self::assertNotNull($bikeIntervals);
        self::assertTrue($runIntervals->hasWorkoutSteps());
        self::assertTrue($bikeIntervals->hasWorkoutSteps());

        $runTargets = array_filter(
            array_map(
                static fn (array $step): ?string => isset($step['targetPace']) && is_string($step['targetPace']) ? $step['targetPace'] : null,
                $this->flattenWorkoutSteps($runIntervals->getWorkoutSteps()),
            ),
            static fn (?string $targetPace): bool => null !== $targetPace,
        );
        $bikeTargets = array_filter(
            array_map(
                static fn (array $step): ?int => isset($step['targetPower']) && is_numeric($step['targetPower']) ? (int) $step['targetPower'] : null,
                $this->flattenWorkoutSteps($bikeIntervals->getWorkoutSteps()),
            ),
            static fn (?int $targetPower): bool => null !== $targetPower,
        );

        self::assertContains('4:15/km', $runTargets);
        self::assertContains(255, $bikeTargets);
    }

    public function testGenerateProgressesRunTargetsAcrossGeneratedWeeks(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Half marathon target',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5_700,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-04-19', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::RUNNING,
                performanceMetrics: ['runningThresholdPace' => 255, 'weeklyRunningVolume' => 48.0],
                targetRaceProfile: $targetRace->getProfile(),
                trainingFocus: TrainingFocus::RUN,
                type: TrainingPlanType::TRAINING,
            ),
        );

        $firstWeekRunIntervals = $this->findSessionByTitle($proposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions(), 'Run intervals');
        $secondWeekRunIntervals = $this->findSessionByTitle($proposal->getProposedBlocks()[0]->getWeekSkeletons()[1]->getSessions(), 'Run intervals');

        self::assertNotNull($firstWeekRunIntervals);
        self::assertNotNull($secondWeekRunIntervals);

        $firstWeekRunTargets = array_values(array_filter(
            array_map(
                static fn (array $step): ?string => isset($step['targetPace']) && is_string($step['targetPace']) ? $step['targetPace'] : null,
                $this->flattenWorkoutSteps($firstWeekRunIntervals->getWorkoutSteps()),
            ),
            static fn (?string $targetPace): bool => null !== $targetPace,
        ));
        $secondWeekRunTargets = array_values(array_filter(
            array_map(
                static fn (array $step): ?string => isset($step['targetPace']) && is_string($step['targetPace']) ? $step['targetPace'] : null,
                $this->flattenWorkoutSteps($secondWeekRunIntervals->getWorkoutSteps()),
            ),
            static fn (?string $targetPace): bool => null !== $targetPace,
        ));

        self::assertNotEmpty($firstWeekRunTargets);
        self::assertNotEmpty($secondWeekRunTargets);
        self::assertLessThan(
            $this->parsePaceTargetToSeconds($firstWeekRunTargets[0]),
            $this->parsePaceTargetToSeconds($secondWeekRunTargets[0]),
        );
        self::assertNotSame((string) $firstWeekRunIntervals->getNotes(), (string) $secondWeekRunIntervals->getNotes());
    }

    public function testGenerateUsesDistanceBasedRunningStepsWhenConfigured(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Half marathon target',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5_700,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-04-12', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::RUNNING,
                performanceMetrics: ['runningThresholdPace' => 255, 'weeklyRunningVolume' => 48.0],
                targetRaceProfile: $targetRace->getProfile(),
                runningWorkoutTargetMode: RunningWorkoutTargetMode::DISTANCE,
            ),
        );

        $runIntervals = $this->findSessionByTitle($proposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions(), 'Run intervals');

        self::assertNotNull($runIntervals);

        $distanceTargets = array_values(array_filter(
            array_map(
                static fn (array $step): ?int => 'distance' === ($step['targetType'] ?? null) && isset($step['distanceInMeters'])
                    ? (int) $step['distanceInMeters']
                    : null,
                $this->flattenWorkoutSteps($runIntervals->getWorkoutSteps()),
            ),
            static fn (?int $distance): bool => null !== $distance,
        ));

        self::assertNotEmpty($distanceTargets);
        self::assertContains(400, $distanceTargets);
        self::assertContains(1200, $distanceTargets);
    }

    public function testGenerateCanOverrideDistancePreferenceForTimeBasedFartlekSessions(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Half marathon target',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5_700,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-05-10', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::RUNNING,
                performanceMetrics: ['runningThresholdPace' => 255, 'weeklyRunningVolume' => 48.0],
                targetRaceProfile: $targetRace->getProfile(),
                runningWorkoutTargetMode: RunningWorkoutTargetMode::DISTANCE,
            ),
        );

        $fifthWeekRunIntervals = $this->findSessionByTitle($proposal->getProposedBlocks()[0]->getWeekSkeletons()[4]->getSessions(), 'Run intervals');

        self::assertNotNull($fifthWeekRunIntervals);

        $intervalTargetTypes = array_values(array_filter(
            array_map(
                static fn (array $step): ?string => 'interval' === ($step['type'] ?? null) ? (string) ($step['targetType'] ?? '') : null,
                $this->flattenWorkoutSteps($fifthWeekRunIntervals->getWorkoutSteps()),
            ),
            static fn (?string $targetType): bool => null !== $targetType && '' !== $targetType,
        ));

        self::assertNotEmpty($intervalTargetTypes);
        self::assertSame($intervalTargetTypes, array_map('strval', $intervalTargetTypes));
        self::assertContains('time', $intervalTargetTypes);
        self::assertNotContains('distance', $intervalTargetTypes);
    }

    public function testGenerateRotatesBikeIntervalWorkoutFamiliesAcrossBuildWeeks(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-10-25 00:00:00'),
            type: RaceEventType::RIDE,
            title: 'Autumn target ride',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 18_000,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-08-17 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-08-17', '2026-08-30', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-08-10 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::CYCLING,
                sportSchedule: ['bikeDays' => [2, 4, 6, 7], 'longRideDays' => [7]],
                performanceMetrics: ['cyclingFtp' => 280, 'weeklyBikingVolume' => 9.0],
                targetRaceProfile: $targetRace->getProfile(),
            ),
        );

        $firstWeekBikeIntervals = $this->findSessionByTitle($proposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions(), 'Bike intervals');
        $secondWeekBikeIntervals = $this->findSessionByTitle($proposal->getProposedBlocks()[0]->getWeekSkeletons()[1]->getSessions(), 'Bike intervals');

        self::assertNotNull($firstWeekBikeIntervals);
        self::assertNotNull($secondWeekBikeIntervals);

        $firstHeadlines = array_map(static fn (array $row): string => $row['headline'], $firstWeekBikeIntervals->getWorkoutPreviewRows());
        $secondHeadlines = array_map(static fn (array $row): string => $row['headline'], $secondWeekBikeIntervals->getWorkoutPreviewRows());

        self::assertContains('Work · Over segment', $firstHeadlines);
        self::assertContains('Work · 30s on', $secondHeadlines);
        self::assertNotSame($firstHeadlines, $secondHeadlines);
    }

    public function testGenerateAddsLateRideSurgesToSomeLongRides(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-10-25 00:00:00'),
            type: RaceEventType::RIDE,
            title: 'Autumn target ride',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 18_000,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-08-17 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-08-17', '2026-08-30', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-08-10 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::CYCLING,
                sportSchedule: ['bikeDays' => [2, 4, 6, 7], 'longRideDays' => [7]],
                performanceMetrics: ['cyclingFtp' => 280, 'weeklyBikingVolume' => 9.0],
                targetRaceProfile: $targetRace->getProfile(),
            ),
        );

        $secondWeekLongRide = $this->findSessionByTitle($proposal->getProposedBlocks()[0]->getWeekSkeletons()[1]->getSessions(), 'Long bike');

        self::assertNotNull($secondWeekLongRide);

        $headlines = array_map(static fn (array $row): string => $row['headline'], $secondWeekLongRide->getWorkoutPreviewRows());

        self::assertContains('Steady · Late-session surge', $headlines);
    }

    public function testGenerateAddsSweetSpotBlocksToLaterBikeBuildWeeks(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-10-25 00:00:00'),
            type: RaceEventType::RIDE,
            title: 'Autumn target ride',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 18_000,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-08-17 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-08-17', '2026-09-13', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-08-10 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::CYCLING,
                sportSchedule: ['bikeDays' => [2, 4, 6, 7], 'longRideDays' => [7]],
                performanceMetrics: ['cyclingFtp' => 280, 'weeklyBikingVolume' => 9.0],
                targetRaceProfile: $targetRace->getProfile(),
            ),
        );

        $secondWeekBikeIntervals = $this->findSessionByTitle($proposal->getProposedBlocks()[0]->getWeekSkeletons()[1]->getSessions(), 'Bike intervals');

        self::assertNotNull($secondWeekBikeIntervals);

        $headlines = array_map(static fn (array $row): string => $row['headline'], $secondWeekBikeIntervals->getWorkoutPreviewRows());

        self::assertContains('Steady · Sweet spot block', $headlines);
    }

    public function testGenerateCanPlaceLateSessionBikeIntervalsInLongerBuilds(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-10-25 00:00:00'),
            type: RaceEventType::RIDE,
            title: 'Autumn target ride',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 18_000,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-08-17 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-08-17', '2026-09-27', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-08-10 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::CYCLING,
                sportSchedule: ['bikeDays' => [2, 4, 6, 7], 'longRideDays' => [7]],
                performanceMetrics: ['cyclingFtp' => 280, 'weeklyBikingVolume' => 9.0],
                targetRaceProfile: $targetRace->getProfile(),
            ),
        );

        $secondWeekBikeIntervals = $this->findSessionByTitle($proposal->getProposedBlocks()[0]->getWeekSkeletons()[1]->getSessions(), 'Bike intervals');

        self::assertNotNull($secondWeekBikeIntervals);

        $headlines = array_map(static fn (array $row): string => $row['headline'], $secondWeekBikeIntervals->getWorkoutPreviewRows());

        self::assertContains('Work · Late-session interval', $headlines);
    }

    public function testGenerateAddsSpinUpsToSomeEasyBikeSessions(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-10-25 00:00:00'),
            type: RaceEventType::RIDE,
            title: 'Autumn target ride',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 18_000,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-08-17 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-08-17', '2026-08-30', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-08-10 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::CYCLING,
                sportSchedule: ['bikeDays' => [2, 4, 6, 7], 'longRideDays' => [7]],
                performanceMetrics: ['cyclingFtp' => 280, 'weeklyBikingVolume' => 9.0],
                targetRaceProfile: $targetRace->getProfile(),
            ),
        );

        $secondWeekEasyBike = $this->findSessionByTitle($proposal->getProposedBlocks()[0]->getWeekSkeletons()[1]->getSessions(), 'Easy bike');

        self::assertNotNull($secondWeekEasyBike);

        $headlines = array_map(static fn (array $row): string => $row['headline'], $secondWeekEasyBike->getWorkoutPreviewRows());

        self::assertContains('Work · High-cadence spin-up', $headlines);
    }

    public function testGenerateCanAddSweetSpotInsertsToLongRides(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-10-25 00:00:00'),
            type: RaceEventType::RIDE,
            title: 'Autumn target ride',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 18_000,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-08-17 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-08-17', '2026-09-14', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-08-10 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::CYCLING,
                sportSchedule: ['bikeDays' => [2, 4, 6, 7], 'longRideDays' => [7]],
                performanceMetrics: ['cyclingFtp' => 280, 'weeklyBikingVolume' => 9.0],
                targetRaceProfile: $targetRace->getProfile(),
            ),
        );

        $thirdWeekLongBike = $this->findSessionByTitle($proposal->getProposedBlocks()[0]->getWeekSkeletons()[2]->getSessions(), 'Long bike');

        self::assertNotNull($thirdWeekLongBike);

        $headlines = array_map(static fn (array $row): string => $row['headline'], $thirdWeekLongBike->getWorkoutPreviewRows());

        self::assertContains('Steady · Sweet-spot insert', $headlines);
    }

    public function testGenerateSkipsHillRepsWhenHillSessionsAreDisabled(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Half marathon target',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5_700,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-04-19', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::RUNNING,
                performanceMetrics: ['runningThresholdPace' => 255, 'weeklyRunningVolume' => 48.0],
                targetRaceProfile: $targetRace->getProfile(),
            ),
        );

        $secondWeekRunIntervals = $this->findSessionByTitle($proposal->getProposedBlocks()[0]->getWeekSkeletons()[1]->getSessions(), 'Run intervals');

        self::assertNotNull($secondWeekRunIntervals);

        $headlines = array_map(static fn (array $row): string => $row['headline'], $secondWeekRunIntervals->getWorkoutPreviewRows());

        self::assertNotContains('Work · Hill rep', $headlines);
    }

    public function testGenerateCanAddHillRepsWhenEnabled(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Half marathon target',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5_700,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-04-19', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::RUNNING,
                performanceMetrics: ['runningThresholdPace' => 255, 'weeklyRunningVolume' => 48.0],
                targetRaceProfile: $targetRace->getProfile(),
                runHillSessionsEnabled: true,
            ),
        );

        $secondWeekRunIntervals = $this->findSessionByTitle($proposal->getProposedBlocks()[0]->getWeekSkeletons()[1]->getSessions(), 'Run intervals');

        self::assertNotNull($secondWeekRunIntervals);

        $headlines = array_map(static fn (array $row): string => $row['headline'], $secondWeekRunIntervals->getWorkoutPreviewRows());

        self::assertContains('Work · Hill rep', $headlines);
        self::assertContains('Recovery · Jog back down', $headlines);
    }

    public function testGenerateRotatesFlatFriendlyRunWorkoutFamiliesAcrossBuildWeeks(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Half marathon target',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5_700,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-04-26', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::RUNNING,
                performanceMetrics: ['runningThresholdPace' => 255, 'weeklyRunningVolume' => 50.0],
                targetRaceProfile: $targetRace->getProfile(),
            ),
        );

        $weekOneRunIntervals = $this->findSessionByTitle($proposal->getProposedBlocks()[0]->getWeekSkeletons()[0]->getSessions(), 'Run intervals');
        $weekTwoRunIntervals = $this->findSessionByTitle($proposal->getProposedBlocks()[0]->getWeekSkeletons()[1]->getSessions(), 'Run intervals');
        $weekThreeRunIntervals = $this->findSessionByTitle($proposal->getProposedBlocks()[0]->getWeekSkeletons()[2]->getSessions(), 'Run intervals');

        self::assertNotNull($weekOneRunIntervals);
        self::assertNotNull($weekTwoRunIntervals);
        self::assertNotNull($weekThreeRunIntervals);

        $weekOneHeadlines = array_map(static fn (array $row): string => $row['headline'], $weekOneRunIntervals->getWorkoutPreviewRows());
        $weekTwoHeadlines = array_map(static fn (array $row): string => $row['headline'], $weekTwoRunIntervals->getWorkoutPreviewRows());
        $weekThreeHeadlines = array_map(static fn (array $row): string => $row['headline'], $weekThreeRunIntervals->getWorkoutPreviewRows());

        self::assertContains('Work · Pyramid peak', $weekOneHeadlines);
        self::assertContains('Work · Threshold block', $weekTwoHeadlines);
        self::assertContains('Work · Cruise rep', $weekThreeHeadlines);
    }

    public function testGenerateCanBuildTrackStyleRunningSessionsWithoutHills(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Half marathon target',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5_700,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-05-03', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::RUNNING,
                performanceMetrics: ['runningThresholdPace' => 255, 'weeklyRunningVolume' => 50.0],
                targetRaceProfile: $targetRace->getProfile(),
                runningWorkoutTargetMode: RunningWorkoutTargetMode::DISTANCE,
            ),
        );

        $weekFourRunIntervals = $this->findSessionByTitle($proposal->getProposedBlocks()[0]->getWeekSkeletons()[3]->getSessions(), 'Run intervals');

        self::assertNotNull($weekFourRunIntervals);

        $headlines = array_map(static fn (array $row): string => $row['headline'], $weekFourRunIntervals->getWorkoutPreviewRows());
        $distanceTargets = array_values(array_filter(
            array_map(
                static fn (array $step): ?int => 'distance' === ($step['targetType'] ?? null) && isset($step['distanceInMeters'])
                    ? (int) $step['distanceInMeters']
                    : null,
                $this->flattenWorkoutSteps($weekFourRunIntervals->getWorkoutSteps()),
            ),
            static fn (?int $distance): bool => null !== $distance,
        ));

        self::assertContains('Work · Track float rep', $headlines);
        self::assertContains(400, $distanceTargets);
        self::assertContains(200, $distanceTargets);
    }

    public function testGenerateAddsStrideEconomyToSomeEasyRuns(): void
    {
        $generator = new TrainingPlanGenerator();
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Half marathon target',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5_700,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $proposal = $generator->generate(
            targetRace: $targetRace,
            planStartDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            allRaceEvents: [$targetRace],
            existingBlocks: [
                $this->createBlock('2026-04-06', '2026-04-19', TrainingBlockPhase::BUILD, $targetRace->getId()),
            ],
            existingSessions: [],
            referenceDate: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            linkedTrainingPlan: $this->createLinkedTrainingPlan(
                discipline: TrainingPlanDiscipline::RUNNING,
                sportSchedule: ['runDays' => [2, 4, 6, 7], 'longRunDays' => [7]],
                performanceMetrics: ['runningThresholdPace' => 255, 'weeklyRunningVolume' => 50.0],
                targetRaceProfile: $targetRace->getProfile(),
            ),
        );

        $secondWeekEasyRun = $this->findSessionByTitle($proposal->getProposedBlocks()[0]->getWeekSkeletons()[1]->getSessions(), 'Easy run');

        self::assertNotNull($secondWeekEasyRun);

        $headlines = array_map(static fn (array $row): string => $row['headline'], $secondWeekEasyRun->getWorkoutPreviewRows());

        self::assertContains('Work · Smooth stride', $headlines);
    }

    /**
     * @param list<\App\Domain\TrainingPlanner\PlanGenerator\ProposedSession> $sessions
     */
    private function findSessionByTitle(array $sessions, string $title): ?\App\Domain\TrainingPlanner\PlanGenerator\ProposedSession
    {
        foreach ($sessions as $session) {
            if ($session->getTitle() === $title) {
                return $session;
            }
        }

        return null;
    }

    /**
     * @param list<\App\Domain\TrainingPlanner\PlanGenerator\ProposedSession> $sessions
     *
     * @return list<\App\Domain\TrainingPlanner\PlanGenerator\ProposedSession>
     */
    private function findSessionsOnDay(array $sessions, string $day, ?ActivityType $activityType = null): array
    {
        return array_values(array_filter($sessions, static function ($session) use ($day, $activityType): bool {
            if ($session->getDay()->format('Y-m-d') !== $day) {
                return false;
            }

            return null === $activityType || $session->getActivityType() === $activityType;
        }));
    }

    /**
     * @param list<array<string, mixed>> $workoutSteps
     *
     * @return list<array<string, mixed>>
     */
    private function flattenWorkoutSteps(array $workoutSteps): array
    {
        $flattenedSteps = [];

        foreach ($workoutSteps as $workoutStep) {
            if (!is_array($workoutStep)) {
                continue;
            }

            $flattenedSteps[] = $workoutStep;

            if (is_array($workoutStep['steps'] ?? null)) {
                $flattenedSteps = [...$flattenedSteps, ...$this->flattenWorkoutSteps($workoutStep['steps'])];
            }
        }

        return $flattenedSteps;
    }

    private function parsePaceTargetToSeconds(string $pace): int
    {
        $normalizedPace = trim(str_replace('/km', '', $pace));
        [$minutes, $seconds] = array_map('intval', explode(':', $normalizedPace));

        return ($minutes * 60) + $seconds;
    }

    private function createReadinessContext(
        ?ReadinessScore $readinessScore = null,
        ?int $forecastDaysUntilTsbHealthy = null,
        ?int $forecastDaysUntilAcRatioHealthy = null,
    ): RaceReadinessContext {
        return new RaceReadinessContext(
            targetRace: null,
            primaryTrainingBlock: null,
            targetRaceCountdownDays: null,
            hasRaceEventInContextWindow: false,
            estimatedLoad: 0.0,
            activityTypeSummaries: [],
            disciplineCounts: ['swim' => 0, 'bike' => 0, 'run' => 0],
            sessionCount: 0,
            distinctSessionDayCount: 0,
            hardSessionCount: 0,
            easySessionCount: 0,
            brickDayCount: 0,
            hasLongRideSession: false,
            hasLongRunSession: false,
            readinessScore: $readinessScore,
            forecastConfidence: null,
            forecastDaysUntilTsbHealthy: $forecastDaysUntilTsbHealthy,
            forecastDaysUntilAcRatioHealthy: $forecastDaysUntilAcRatioHealthy,
        );
    }

    private function createTrainingSessionRecommendation(
        ActivityType $activityType,
        string $title,
        string $notes,
        int $targetDurationInSeconds,
        PlannedSessionIntensity $targetIntensity,
        TrainingBlockPhase $sessionPhase,
        TrainingSessionObjective $sessionObjective,
        array $workoutSteps,
    ): TrainingSession {
        return TrainingSession::create(
            trainingSessionId: TrainingSessionId::random(),
            sourcePlannedSessionId: null,
            activityType: $activityType,
            title: $title,
            notes: $notes,
            targetLoad: null,
            targetDurationInSeconds: $targetDurationInSeconds,
            targetIntensity: $targetIntensity,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::WORKOUT_TARGETS,
            lastPlannedOn: SerializableDateTime::fromString('2026-03-20 00:00:00'),
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-03-20 08:00:00'),
            workoutSteps: $workoutSteps,
            sessionSource: TrainingSessionSource::PLANNED_SESSION,
            sessionPhase: $sessionPhase,
            sessionObjective: $sessionObjective,
        );
    }

    private function createTrainingSessionRepositoryStub(callable $resolver): TrainingSessionRepository
    {
        return new class($resolver) implements TrainingSessionRepository {
            public function __construct(
                private $resolver,
            ) {
            }

            public function upsert(TrainingSession $trainingSession): void
            {
                throw new \BadMethodCallException('Not implemented for this test stub.');
            }

            public function deleteById(TrainingSessionId $trainingSessionId): void
            {
                throw new \BadMethodCallException('Not implemented for this test stub.');
            }

            public function findById(TrainingSessionId $trainingSessionId): ?TrainingSession
            {
                throw new \BadMethodCallException('Not implemented for this test stub.');
            }

            public function findBySourcePlannedSessionId(\App\Domain\TrainingPlanner\PlannedSessionId $plannedSessionId): ?TrainingSession
            {
                throw new \BadMethodCallException('Not implemented for this test stub.');
            }

            public function findDuplicatesOf(TrainingSession $trainingSession, ?TrainingSessionId $excludeTrainingSessionId = null): array
            {
                throw new \BadMethodCallException('Not implemented for this test stub.');
            }

            public function findRecommended(ActivityType $activityType, int $limit = 12, ?TrainingSessionRecommendationCriteria $criteria = null): array
            {
                return ($this->resolver)($activityType, $criteria, $limit);
            }
        };
    }

    private function createLinkedTrainingPlan(
        TrainingPlanDiscipline $discipline,
        ?array $sportSchedule = null,
        ?array $performanceMetrics = null,
        ?\App\Domain\TrainingPlanner\RaceEventProfile $targetRaceProfile = null,
        ?TrainingFocus $trainingFocus = null,
        TrainingPlanType $type = TrainingPlanType::RACE,
        ?TrainingBlockStyle $trainingBlockStyle = null,
        ?RunningWorkoutTargetMode $runningWorkoutTargetMode = null,
        bool $runHillSessionsEnabled = false,
    ): TrainingPlan {
        return TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: $type,
            startDay: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            targetRaceEventId: null,
            title: 'Linked preferences',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            discipline: $discipline,
            sportSchedule: $sportSchedule,
            performanceMetrics: $performanceMetrics,
            targetRaceProfile: $targetRaceProfile,
            trainingFocus: $trainingFocus,
            trainingBlockStyle: $trainingBlockStyle,
            runningWorkoutTargetMode: $runningWorkoutTargetMode,
            runHillSessionsEnabled: $runHillSessionsEnabled,
        );
    }

    private function createTargetRace(): RaceEvent
    {
        return RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_DISTANCE_TRIATHLON,
            title: 'West Friesland',
            location: 'Hoorn',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 19_800,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );
    }

    private function createBlock(
        string $startDay,
        string $endDay,
        TrainingBlockPhase $phase,
        ?\App\Domain\TrainingPlanner\RaceEventId $targetRaceEventId,
    ): TrainingBlock {
        return TrainingBlock::create(
            trainingBlockId: TrainingBlockId::random(),
            startDay: SerializableDateTime::fromString(sprintf('%s 00:00:00', $startDay)),
            endDay: SerializableDateTime::fromString(sprintf('%s 00:00:00', $endDay)),
            targetRaceEventId: $targetRaceEventId,
            phase: $phase,
            title: ucfirst($phase->value),
            focus: null,
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );
    }
}