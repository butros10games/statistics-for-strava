<?php

declare(strict_types=1);

namespace App\Tests\Application\Build\BuildRacePlannerHtml;

use App\Application\Build\BuildRacePlannerHtml\BuildRacePlannerHtml;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityType;
use App\Infrastructure\Serialization\Json;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionEstimationSource;
use App\Domain\TrainingPlanner\PlannedSessionId;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Domain\TrainingPlanner\PlannedSessionLinkStatus;
use App\Domain\TrainingPlanner\PlannedSessionRepository;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventId;
use App\Domain\TrainingPlanner\RaceEventPriority;
use App\Domain\TrainingPlanner\RaceEventProfile;
use App\Domain\TrainingPlanner\RaceEventRepository;
use App\Domain\TrainingPlanner\RaceEventType;
use App\Domain\TrainingPlanner\RacePlannerConfiguration;
use App\Domain\TrainingPlanner\TrainingPlan;
use App\Domain\TrainingPlanner\TrainingBlockStyle;
use App\Domain\TrainingPlanner\TrainingPlanId;
use App\Domain\TrainingPlanner\TrainingPlanDiscipline;
use App\Domain\TrainingPlanner\TrainingPlanRepository;
use App\Domain\TrainingPlanner\TrainingPlanType;
use App\Domain\TrainingPlanner\TrainingBlock;
use App\Domain\TrainingPlanner\TrainingBlockId;
use App\Domain\TrainingPlanner\TrainingBlockPhase;
use App\Domain\TrainingPlanner\TrainingBlockRepository;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\Application\BuildAppFilesTestCase;

final class BuildRacePlannerHtmlCommandHandlerTest extends BuildAppFilesTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(\MessageFormatter::class)) {
            self::markTestSkipped('intl/message formatter support is not available in this environment.');
        }
    }

    public function testHandleBuildsRacePlannerPage(): void
    {
        $this->commandBus->dispatch(new BuildRacePlannerHtml(SerializableDateTime::fromString('2026-10-14 08:00:00')));

        $buildStorage = $this->getContainer()->get('build.storage');

        self::assertTrue($buildStorage->fileExists('race-planner.html'));
        self::assertStringContainsString('No upcoming races', $buildStorage->read('race-planner.html'));
    }

    public function testHandleBuildsDedicatedPlannerPageForTrainingPlanPreview(): void
    {
        /** @var TrainingPlanRepository $trainingPlanRepository */
        $trainingPlanRepository = $this->getContainer()->get(TrainingPlanRepository::class);
        $trainingPlan = TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::TRAINING,
            startDay: SerializableDateTime::fromString('2026-12-01 00:00:00'),
            endDay: SerializableDateTime::fromString('2027-01-24 00:00:00'),
            targetRaceEventId: null,
            title: 'Winter bridge block',
            notes: 'Keep the routine alive between race cycles.',
            createdAt: SerializableDateTime::fromString('2026-10-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-10-01 08:00:00'),
            discipline: TrainingPlanDiscipline::TRIATHLON,
            performanceMetrics: [
                'runningThresholdPace' => 255,
                'weeklyRunningVolume' => 48.0,
            ],
            targetRaceProfile: \App\Domain\TrainingPlanner\RaceEventProfile::HALF_DISTANCE_TRIATHLON,
            trainingFocus: \App\Domain\TrainingPlanner\TrainingFocus::RUN,
        );
        $trainingPlanRepository->upsert($trainingPlan);

        $this->commandBus->dispatch(new BuildRacePlannerHtml(SerializableDateTime::fromString('2026-10-14 08:00:00')));

        $buildStorage = $this->getContainer()->get('build.storage');
        $planPreviewPath = sprintf('race-planner/plan-%s.html', $trainingPlan->getId());

        self::assertTrue($buildStorage->fileExists($planPreviewPath));

        $planPreviewHtml = $buildStorage->read($planPreviewPath);
        $apiStorage = $this->getContainer()->get('api.storage');
        $planExportPath = sprintf('exports/training-plans/%s.json', $trainingPlan->getId());

        self::assertStringContainsString('Winter bridge block', $planPreviewHtml);
        self::assertStringContainsString('Planner view', $planPreviewHtml);
        self::assertStringContainsString('Plan manager', $planPreviewHtml);
        self::assertStringContainsString('/api/exports/training-plans/', $planPreviewHtml);
        self::assertStringContainsString('Copy AI review prompt', $planPreviewHtml);
        self::assertStringContainsString('data-training-plan-analysis-prompt', $planPreviewHtml);
        self::assertTrue($apiStorage->fileExists($planExportPath));
        self::assertStringContainsString('This is a development plan', $planPreviewHtml);
        self::assertStringContainsString('Running performance', $planPreviewHtml);
        self::assertStringContainsString('Projected threshold', $planPreviewHtml);
        self::assertStringContainsString('Half marathon', $planPreviewHtml);
        self::assertStringContainsString('Threshold 4:', $planPreviewHtml);
        self::assertStringContainsString('Prediction basis', $planPreviewHtml);
        self::assertStringContainsString('Weekly run volume', $planPreviewHtml);
        self::assertStringContainsString('Planned run structure', $planPreviewHtml);
        self::assertStringContainsString('Week threshold', $planPreviewHtml);
        self::assertStringContainsString('Targets in this session scale from your projected fitness for this week.', $planPreviewHtml);
        self::assertStringContainsString('week-adjusted pace', $planPreviewHtml);
        self::assertStringContainsString('ideal full-plan potential', $planPreviewHtml);
        self::assertStringNotContainsString('until race day', $planPreviewHtml);
        self::assertStringNotContainsString('Peak (', $planPreviewHtml);
        self::assertStringNotContainsString('Taper (', $planPreviewHtml);
        self::assertStringNotContainsString('Recovery (', $planPreviewHtml);
        self::assertStringNotContainsString('Save recovery to calendar', $planPreviewHtml);
        self::assertStringNotContainsString('Race calendar', $planPreviewHtml);
        self::assertStringNotContainsString('Sun 24 Jan', $planPreviewHtml);

        $payload = Json::uncompressAndDecode($apiStorage->read($planExportPath));

        self::assertSame('training-plan', $payload['exportType']);
        self::assertSame((string) $trainingPlan->getId(), $payload['plan']['id']);
        self::assertSame('Winter bridge block', $payload['plan']['title']);
        self::assertSame('/race-planner/plan-'.$trainingPlan->getId(), $payload['urls']['planner']);
        self::assertSame('/api/exports/training-plans/'.$trainingPlan->getId().'.json', $payload['urls']['export']);
        self::assertArrayHasKey('proposal', $payload);
        self::assertArrayHasKey('suggestedPrompts', $payload['usageGuide']);
        self::assertArrayHasKey('reviewPromptTemplate', $payload['usageGuide']);
        self::assertStringContainsString('/api/exports/training-plans/'.$trainingPlan->getId().'.json', $payload['usageGuide']['reviewPromptTemplate']);
    }

    public function testHandleKeepsRaceCalendarForPreviewWithRealLinkedRace(): void
    {
        /** @var RaceEventRepository $raceEventRepository */
        $raceEventRepository = $this->getContainer()->get(RaceEventRepository::class);
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2027-01-24 00:00:00'),
            type: RaceEventType::HALF_DISTANCE_TRIATHLON,
            title: 'Winter benchmark race',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 19_800,
            createdAt: SerializableDateTime::fromString('2026-10-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-10-01 08:00:00'),
        );
        $raceEventRepository->upsert($targetRace);

        /** @var TrainingPlanRepository $trainingPlanRepository */
        $trainingPlanRepository = $this->getContainer()->get(TrainingPlanRepository::class);
        $trainingPlan = TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::RACE,
            startDay: SerializableDateTime::fromString('2026-12-01 00:00:00'),
            endDay: SerializableDateTime::fromString('2027-01-24 00:00:00'),
            targetRaceEventId: $targetRace->getId(),
            title: 'Winter benchmark build',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-10-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-10-01 08:00:00'),
            discipline: TrainingPlanDiscipline::TRIATHLON,
            performanceMetrics: [
                'runningThresholdPace' => 255,
                'weeklyRunningVolume' => 52.0,
            ],
            targetRaceProfile: RaceEventProfile::HALF_DISTANCE_TRIATHLON,
            trainingFocus: \App\Domain\TrainingPlanner\TrainingFocus::RUN,
        );
        $trainingPlanRepository->upsert($trainingPlan);

        $this->commandBus->dispatch(new BuildRacePlannerHtml(SerializableDateTime::fromString('2026-10-14 08:00:00')));

        $buildStorage = $this->getContainer()->get('build.storage');
        $planPreviewPath = sprintf('race-planner/plan-%s.html', $trainingPlan->getId());

        self::assertTrue($buildStorage->fileExists($planPreviewPath));

        $planPreviewHtml = $buildStorage->read($planPreviewPath);

        self::assertStringContainsString('Race calendar', $planPreviewHtml);
        self::assertStringContainsString('Running forecast', $planPreviewHtml);
        self::assertStringContainsString('Prediction basis', $planPreviewHtml);
        self::assertStringContainsString('Directional estimate only', $planPreviewHtml);
        self::assertStringContainsString('ideal full-plan potential', $planPreviewHtml);
        self::assertStringContainsString('Winter benchmark race', $planPreviewHtml);
        self::assertStringContainsString('Sun 24 Jan', $planPreviewHtml);
    }

    public function testHandleShowsAdherenceAwareRunningTrajectoryWhenPastSessionsExist(): void
    {
        /** @var RaceEventRepository $raceEventRepository */
        $raceEventRepository = $this->getContainer()->get(RaceEventRepository::class);
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-11-29 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Trajectory goal race',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5700,
            createdAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
        );
        $raceEventRepository->upsert($targetRace);

        /** @var TrainingPlanRepository $trainingPlanRepository */
        $trainingPlanRepository = $this->getContainer()->get(TrainingPlanRepository::class);
        $trainingPlanRepository->upsert(TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::RACE,
            startDay: SerializableDateTime::fromString('2026-09-29 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-11-29 00:00:00'),
            targetRaceEventId: $targetRace->getId(),
            title: 'Trajectory-aware race plan',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
            discipline: TrainingPlanDiscipline::RUNNING,
            performanceMetrics: [
                'runningThresholdPace' => 255,
                'weeklyRunningVolume' => 52.0,
            ],
            targetRaceProfile: RaceEventProfile::HALF_MARATHON,
            trainingFocus: \App\Domain\TrainingPlanner\TrainingFocus::RUN,
        ));

        /** @var PlannedSessionRepository $plannedSessionRepository */
        $plannedSessionRepository = $this->getContainer()->get(PlannedSessionRepository::class);
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2026-10-01 00:00:00',
            title: 'Run intervals 1',
            targetLoad: 52.0,
            targetDurationInSeconds: 3900,
            targetIntensity: PlannedSessionIntensity::HARD,
            isLinked: true,
        ));
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2026-10-04 00:00:00',
            title: 'Long run',
            targetLoad: 60.0,
            targetDurationInSeconds: 6000,
            targetIntensity: PlannedSessionIntensity::MODERATE,
            isLinked: false,
        ));
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2026-10-08 00:00:00',
            title: 'Run intervals 2',
            targetLoad: 53.0,
            targetDurationInSeconds: 3900,
            targetIntensity: PlannedSessionIntensity::HARD,
            isLinked: true,
        ));
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2026-10-11 00:00:00',
            title: 'Easy run',
            targetLoad: 24.0,
            targetDurationInSeconds: 2700,
            targetIntensity: PlannedSessionIntensity::EASY,
            isLinked: false,
        ));

        $this->commandBus->dispatch(new BuildRacePlannerHtml(SerializableDateTime::fromString('2026-10-14 08:00:00')));

        $racePlannerHtml = $this->getContainer()->get('build.storage')->read('race-planner.html');

        self::assertStringContainsString('Current trajectory', $racePlannerHtml);
        self::assertStringContainsString('Completed run work', $racePlannerHtml);
        self::assertStringContainsString('2/4 runs · 2/2 key · 0/1 long', $racePlannerHtml);
        self::assertStringContainsString('trajectory uses completed linked run sessions scheduled before today', $racePlannerHtml);
    }

    public function testHandleHighlightsDoubleRunDaysInTrainingPlanPreview(): void
    {
        /** @var TrainingPlanRepository $trainingPlanRepository */
        $trainingPlanRepository = $this->getContainer()->get(TrainingPlanRepository::class);
        $trainingPlan = TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::TRAINING,
            startDay: SerializableDateTime::fromString('2026-12-01 00:00:00'),
            endDay: SerializableDateTime::fromString('2027-02-21 00:00:00'),
            targetRaceEventId: null,
            title: 'Advanced run frequency block',
            notes: 'Preview intentional double-run scheduling.',
            createdAt: SerializableDateTime::fromString('2026-10-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-10-01 08:00:00'),
            discipline: TrainingPlanDiscipline::RUNNING,
            sportSchedule: ['runDays' => [1, 2, 4, 6, 7], 'longRunDays' => [7]],
            performanceMetrics: [
                'runningThresholdPace' => 255,
                'weeklyRunningVolume' => 58.0,
            ],
            targetRaceProfile: RaceEventProfile::HALF_MARATHON,
            trainingFocus: null,
            trainingBlockStyle: TrainingBlockStyle::SPEED_ENDURANCE,
        );
        $trainingPlanRepository->upsert($trainingPlan);

        $this->commandBus->dispatch(new BuildRacePlannerHtml(SerializableDateTime::fromString('2026-10-14 08:00:00')));

        $buildStorage = $this->getContainer()->get('build.storage');
        $planPreviewPath = sprintf('race-planner/plan-%s.html', $trainingPlan->getId());

        self::assertTrue($buildStorage->fileExists($planPreviewPath));

        $planPreviewHtml = $buildStorage->read($planPreviewPath);

        self::assertStringContainsString('Advanced run frequency block', $planPreviewHtml);
        self::assertStringContainsString('Secondary easy run', $planPreviewHtml);
        self::assertStringContainsString('Double run day', $planPreviewHtml);
        self::assertStringContainsString('2nd run', $planPreviewHtml);
    }

    public function testHandleScopesWeeklyRecommendationsToCurrentWeekSessions(): void
    {
        /** @var RaceEventRepository $raceEventRepository */
        $raceEventRepository = $this->getContainer()->get(RaceEventRepository::class);
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-11-29 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Target half marathon',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5700,
            createdAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
        );
        $raceEventRepository->upsert($targetRace);

        /** @var TrainingBlockRepository $trainingBlockRepository */
        $trainingBlockRepository = $this->getContainer()->get(TrainingBlockRepository::class);
        $trainingBlockRepository->upsert(TrainingBlock::create(
            trainingBlockId: TrainingBlockId::random(),
            startDay: SerializableDateTime::fromString('2026-10-12 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-10-25 00:00:00'),
            targetRaceEventId: $targetRace->getId(),
            phase: TrainingBlockPhase::BUILD,
            title: 'Current build block',
            focus: 'Threshold support',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
        ));

        /** @var PlannedSessionRepository $plannedSessionRepository */
        $plannedSessionRepository = $this->getContainer()->get(PlannedSessionRepository::class);
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2026-10-15 00:00:00',
            title: 'Current-week hard run',
            targetLoad: 52.0,
            targetDurationInSeconds: 3600,
            targetIntensity: PlannedSessionIntensity::HARD,
        ));
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2026-10-18 00:00:00',
            title: 'Current-week long run',
            targetLoad: 60.0,
            targetDurationInSeconds: 5400,
            targetIntensity: PlannedSessionIntensity::MODERATE,
        ));
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2026-11-02 00:00:00',
            title: 'Future hard run 1',
            targetLoad: 58.0,
            targetDurationInSeconds: 3900,
            targetIntensity: PlannedSessionIntensity::HARD,
        ));
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2026-11-04 00:00:00',
            title: 'Future hard run 2',
            targetLoad: 58.0,
            targetDurationInSeconds: 3900,
            targetIntensity: PlannedSessionIntensity::HARD,
        ));
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2026-11-06 00:00:00',
            title: 'Future hard run 3',
            targetLoad: 58.0,
            targetDurationInSeconds: 3900,
            targetIntensity: PlannedSessionIntensity::HARD,
        ));

        $this->commandBus->dispatch(new BuildRacePlannerHtml(SerializableDateTime::fromString('2026-10-14 08:00:00')));

        $racePlannerHtml = $this->getContainer()->get('build.storage')->read('race-planner.html');

        self::assertStringContainsString('Target half marathon', $racePlannerHtml);
        self::assertStringNotContainsString('Too many hard sessions this week', $racePlannerHtml);
        self::assertStringNotContainsString('Session count exceeds recommendation', $racePlannerHtml);
    }

    public function testHandleAnchorsProposalToCurrentDayInsteadOfWeekStart(): void
    {
        /** @var RaceEventRepository $raceEventRepository */
        $raceEventRepository = $this->getContainer()->get(RaceEventRepository::class);
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-11-29 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Late-season goal race',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5700,
            createdAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
        );
        $raceEventRepository->upsert($targetRace);

        $this->commandBus->dispatch(new BuildRacePlannerHtml(SerializableDateTime::fromString('2026-10-14 08:00:00')));

        $racePlannerHtml = $this->getContainer()->get('build.storage')->read('race-planner.html');

        self::assertStringContainsString('Oct 14–Oct 20', $racePlannerHtml);
        self::assertStringNotContainsString('Oct 12–Oct 18', $racePlannerHtml);
    }

    public function testHandleUsesConfiguredPlanStartDayWhenPresent(): void
    {
        /** @var RaceEventRepository $raceEventRepository */
        $raceEventRepository = $this->getContainer()->get(RaceEventRepository::class);
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-11-29 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Configured goal race',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5700,
            createdAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
        );
        $raceEventRepository->upsert($targetRace);

        /** @var RacePlannerConfiguration $racePlannerConfiguration */
        $racePlannerConfiguration = $this->getContainer()->get(RacePlannerConfiguration::class);
        $racePlannerConfiguration->savePlanStartDay(SerializableDateTime::fromString('2026-10-20 00:00:00'));

        $this->commandBus->dispatch(new BuildRacePlannerHtml(SerializableDateTime::fromString('2026-10-14 08:00:00')));

        $racePlannerHtml = $this->getContainer()->get('build.storage')->read('race-planner.html');

        self::assertStringContainsString('Oct 20–Oct 26', $racePlannerHtml);
        self::assertStringContainsString('value="2026-10-20"', $racePlannerHtml);
    }

    public function testHandleShowsRealPlanSetupStateInsteadOfPlannerSourceCopy(): void
    {
        /** @var RaceEventRepository $raceEventRepository */
        $raceEventRepository = $this->getContainer()->get(RaceEventRepository::class);
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-11-29 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Linked goal race',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5700,
            createdAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
        );
        $raceEventRepository->upsert($targetRace);

        /** @var TrainingBlockRepository $trainingBlockRepository */
        $trainingBlockRepository = $this->getContainer()->get(TrainingBlockRepository::class);
        $trainingBlockRepository->upsert(TrainingBlock::create(
            trainingBlockId: TrainingBlockId::random(),
            startDay: SerializableDateTime::fromString('2026-10-12 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-10-25 00:00:00'),
            targetRaceEventId: $targetRace->getId(),
            phase: TrainingBlockPhase::BUILD,
            title: 'Existing planner structure',
            focus: 'Build around the existing season',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
        ));

        /** @var TrainingPlanRepository $trainingPlanRepository */
        $trainingPlanRepository = $this->getContainer()->get(TrainingPlanRepository::class);
        $trainingPlanRepository->upsert(TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::RACE,
            startDay: SerializableDateTime::fromString('2026-10-12 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-12-13 00:00:00'),
            targetRaceEventId: $targetRace->getId(),
            title: 'Linked goal season plan',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
        ));

        $this->commandBus->dispatch(new BuildRacePlannerHtml(SerializableDateTime::fromString('2026-10-14 08:00:00')));

        $racePlannerHtml = $this->getContainer()->get('build.storage')->read('race-planner.html');

        self::assertStringContainsString('Plan manager', $racePlannerHtml);
        self::assertStringContainsString('Linked goal race', $racePlannerHtml);
        self::assertStringContainsString('Edit plan', $racePlannerHtml);
        self::assertStringNotContainsString('This planner is using your existing training blocks as the season structure.', $racePlannerHtml);
    }

    public function testHandleBuildsWeekSessionsAsCollapsedRowsInDayOrder(): void
    {
        /** @var RaceEventRepository $raceEventRepository */
        $raceEventRepository = $this->getContainer()->get(RaceEventRepository::class);
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-11-29 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Accordion goal race',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5700,
            createdAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
        );
        $raceEventRepository->upsert($targetRace);

        /** @var TrainingBlockRepository $trainingBlockRepository */
        $trainingBlockRepository = $this->getContainer()->get(TrainingBlockRepository::class);
        $trainingBlockRepository->upsert(TrainingBlock::create(
            trainingBlockId: TrainingBlockId::random(),
            startDay: SerializableDateTime::fromString('2026-10-12 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-10-25 00:00:00'),
            targetRaceEventId: $targetRace->getId(),
            phase: TrainingBlockPhase::BUILD,
            title: 'Accordion build block',
            focus: 'Show the week in actual order',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
        ));

        /** @var PlannedSessionRepository $plannedSessionRepository */
        $plannedSessionRepository = $this->getContainer()->get(PlannedSessionRepository::class);
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2026-10-19 00:00:00',
            title: 'Sunday long run',
            targetLoad: 72.0,
            targetDurationInSeconds: 6000,
            targetIntensity: PlannedSessionIntensity::MODERATE,
        ));
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2026-10-15 00:00:00',
            title: 'Thursday threshold run',
            targetLoad: 58.0,
            targetDurationInSeconds: 3600,
            targetIntensity: PlannedSessionIntensity::HARD,
        ));
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2026-10-14 00:00:00',
            title: 'Wednesday easy run',
            targetLoad: 24.0,
            targetDurationInSeconds: 2700,
            targetIntensity: PlannedSessionIntensity::EASY,
        ));

        $this->commandBus->dispatch(new BuildRacePlannerHtml(SerializableDateTime::fromString('2026-10-14 08:00:00')));

        $racePlannerHtml = $this->getContainer()->get('build.storage')->read('race-planner.html');

        $wednesdayPosition = strpos($racePlannerHtml, 'Wednesday easy run');
        $thursdayPosition = strpos($racePlannerHtml, 'Thursday threshold run');
        $sundayPosition = strpos($racePlannerHtml, 'Sunday long run');

        self::assertNotFalse($wednesdayPosition);
        self::assertNotFalse($thursdayPosition);
        self::assertNotFalse($sundayPosition);
        self::assertLessThan($thursdayPosition, $wednesdayPosition);
        self::assertLessThan($sundayPosition, $thursdayPosition);
        self::assertStringContainsString('group/week rounded-lg border border-gray-100 bg-gray-50/40', $racePlannerHtml);
        self::assertStringContainsString('group/session rounded-lg border border-gray-100 bg-white', $racePlannerHtml);
        self::assertStringContainsString('Wednesday', $racePlannerHtml);
        self::assertStringContainsString('Thursday', $racePlannerHtml);
        self::assertStringContainsString('Sunday', $racePlannerHtml);
    }

    public function testHandleShowsReadableWeeklyLoadAndDisciplineTimeSummary(): void
    {
        /** @var RaceEventRepository $raceEventRepository */
        $raceEventRepository = $this->getContainer()->get(RaceEventRepository::class);
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-11-29 00:00:00'),
            type: RaceEventType::HALF_DISTANCE_TRIATHLON,
            title: 'Summary goal race',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 19_800,
            createdAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
        );
        $raceEventRepository->upsert($targetRace);

        /** @var TrainingBlockRepository $trainingBlockRepository */
        $trainingBlockRepository = $this->getContainer()->get(TrainingBlockRepository::class);
        $trainingBlockRepository->upsert(TrainingBlock::create(
            trainingBlockId: TrainingBlockId::random(),
            startDay: SerializableDateTime::fromString('2026-10-12 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-10-18 00:00:00'),
            targetRaceEventId: $targetRace->getId(),
            phase: TrainingBlockPhase::BUILD,
            title: 'Readable stats block',
            focus: 'Show weekly totals clearly',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
        ));

        /** @var PlannedSessionRepository $plannedSessionRepository */
        $plannedSessionRepository = $this->getContainer()->get(PlannedSessionRepository::class);
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2026-10-13 00:00:00',
            title: 'Pool endurance',
            targetLoad: 22.0,
            targetDurationInSeconds: 2700,
            targetIntensity: PlannedSessionIntensity::EASY,
            activityType: ActivityType::WATER_SPORTS,
        ));
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2026-10-15 00:00:00',
            title: 'Bike endurance',
            targetLoad: 54.0,
            targetDurationInSeconds: 5400,
            targetIntensity: PlannedSessionIntensity::MODERATE,
            activityType: ActivityType::RIDE,
        ));
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2026-10-17 00:00:00',
            title: 'Steady run',
            targetLoad: 42.0,
            targetDurationInSeconds: 4200,
            targetIntensity: PlannedSessionIntensity::MODERATE,
            activityType: ActivityType::RUN,
        ));

        $this->commandBus->dispatch(new BuildRacePlannerHtml(SerializableDateTime::fromString('2026-10-22 08:00:00')));

        $racePlannerHtml = $this->getContainer()->get('build.storage')->read('race-planner.html');

        self::assertStringContainsString('Load ', $racePlannerHtml);
        self::assertStringContainsString('Swim 45m', $racePlannerHtml);
        self::assertStringContainsString('Bike 1h 30m', $racePlannerHtml);
        self::assertStringContainsString('Run 1h 10m', $racePlannerHtml);
    }

    private function createPlannedSession(
        string $day,
        string $title,
        float $targetLoad,
        int $targetDurationInSeconds,
        PlannedSessionIntensity $targetIntensity,
        ActivityType $activityType = ActivityType::RUN,
        bool $isLinked = false,
    ): PlannedSession {
        return PlannedSession::create(
            plannedSessionId: PlannedSessionId::random(),
            day: SerializableDateTime::fromString($day),
            activityType: $activityType,
            title: $title,
            notes: null,
            targetLoad: $targetLoad,
            targetDurationInSeconds: $targetDurationInSeconds,
            targetIntensity: $targetIntensity,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
            linkedActivityId: $isLinked ? ActivityId::random() : null,
            linkStatus: $isLinked ? PlannedSessionLinkStatus::LINKED : PlannedSessionLinkStatus::UNLINKED,
            createdAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
        );
    }
}