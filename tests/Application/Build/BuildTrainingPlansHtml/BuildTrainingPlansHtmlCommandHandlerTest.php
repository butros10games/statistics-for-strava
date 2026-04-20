<?php

declare(strict_types=1);

namespace App\Tests\Application\Build\BuildTrainingPlansHtml;

use App\Application\Build\BuildTrainingPlansHtml\BuildTrainingPlansHtml;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventId;
use App\Domain\TrainingPlanner\RaceEventPriority;
use App\Domain\TrainingPlanner\RaceEventRepository;
use App\Domain\TrainingPlanner\RaceEventType;
use App\Domain\TrainingPlanner\TrainingPlan;
use App\Domain\TrainingPlanner\TrainingBlockStyle;
use App\Domain\TrainingPlanner\TrainingFocus;
use App\Domain\TrainingPlanner\TrainingPlanDiscipline;
use App\Domain\TrainingPlanner\TrainingPlanId;
use App\Domain\TrainingPlanner\TrainingPlanRepository;
use App\Domain\TrainingPlanner\TrainingPlanType;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\Application\BuildAppFilesTestCase;

final class BuildTrainingPlansHtmlCommandHandlerTest extends BuildAppFilesTestCase
{
    public function testHandleBuildsEmptyPlansPage(): void
    {
        if (!class_exists(\MessageFormatter::class)) {
            self::assertTrue(true);

            return;
        }

        $this->commandBus->dispatch(new BuildTrainingPlansHtml(SerializableDateTime::fromString('2026-04-14 08:00:00')));

        $buildStorage = $this->getContainer()->get('build.storage');

        self::assertTrue($buildStorage->fileExists('training-plans.html'));
        self::assertStringContainsString('Start your season plan', $buildStorage->read('training-plans.html'));
    }

    public function testHandleBuildsPlanTimelineAndUnlinkedRaces(): void
    {
        if (!class_exists(\MessageFormatter::class)) {
            self::assertTrue(true);

            return;
        }

        /** @var RaceEventRepository $raceEventRepository */
        $raceEventRepository = $this->getContainer()->get(RaceEventRepository::class);
        $linkedRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_DISTANCE_TRIATHLON,
            title: 'Ironman 70.3 Nice',
            location: 'Nice',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 17100,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );
        $integratedBRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-07 00:00:00'),
            type: RaceEventType::RUN_10K,
            title: 'Nice tune-up 10K',
            location: 'Nice',
            notes: null,
            priority: RaceEventPriority::B,
            targetFinishTimeInSeconds: 2700,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );
        $unlinkedRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-09-13 00:00:00'),
            type: RaceEventType::MARATHON,
            title: 'Berlin Marathon',
            location: 'Berlin',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 11400,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );
        $raceEventRepository->upsert($linkedRace);
        $raceEventRepository->upsert($integratedBRace);
        $raceEventRepository->upsert($unlinkedRace);

        /** @var TrainingPlanRepository $trainingPlanRepository */
        $trainingPlanRepository = $this->getContainer()->get(TrainingPlanRepository::class);
        $trainingPlanRepository->upsert(TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::TRAINING,
            startDay: SerializableDateTime::fromString('2026-04-14 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-05-12 00:00:00'),
            targetRaceEventId: null,
            title: 'Early season durability',
            notes: 'Build consistency before the first race-specific phase.',
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        ));
        $trainingPlanRepository->upsert(TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::RACE,
            startDay: SerializableDateTime::fromString('2026-05-15 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            targetRaceEventId: $linkedRace->getId(),
            title: 'Nice build',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        ));

        $this->commandBus->dispatch(new BuildTrainingPlansHtml(SerializableDateTime::fromString('2026-04-14 08:00:00')));

        $trainingPlansHtml = $this->getContainer()->get('build.storage')->read('training-plans.html');

        self::assertStringContainsString('Early season durability', $trainingPlansHtml);
        self::assertStringContainsString('Nice build', $trainingPlansHtml);
        self::assertStringContainsString('Gap of 2 days', $trainingPlansHtml);
        self::assertStringContainsString('Nice tune-up 10K', $trainingPlansHtml);
        self::assertStringContainsString('Berlin Marathon', $trainingPlansHtml);
        self::assertStringContainsString('Create next plan', $trainingPlansHtml);
        self::assertStringContainsString('Open in race planner', $trainingPlansHtml);
        self::assertStringContainsString('/race-planner/plan-', $trainingPlansHtml);
        self::assertStringContainsString('Plan manager', $trainingPlansHtml);
        self::assertStringContainsString('New plan', $trainingPlansHtml);
    }

    public function testHandleBuildsRichPlanMetadataSummaries(): void
    {
        if (!class_exists(\MessageFormatter::class)) {
            self::assertTrue(true);

            return;
        }

        /** @var TrainingPlanRepository $trainingPlanRepository */
        $trainingPlanRepository = $this->getContainer()->get(TrainingPlanRepository::class);
        $trainingPlanRepository->upsert(TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::TRAINING,
            startDay: SerializableDateTime::fromString('2026-04-14 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-05-12 00:00:00'),
            targetRaceEventId: null,
            title: 'Bike durability block',
            notes: 'Keep the cadence tidy and the pressure steady.',
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            discipline: TrainingPlanDiscipline::CYCLING,
            sportSchedule: ['bikeDays' => [2, 6], 'longRideDays' => [6]],
            performanceMetrics: ['cyclingFtp' => 280, 'weeklyBikingVolume' => 8.5],
            trainingFocus: TrainingFocus::BIKE,
        ));

        $this->commandBus->dispatch(new BuildTrainingPlansHtml(SerializableDateTime::fromString('2026-04-14 08:00:00')));

        $trainingPlansHtml = $this->getContainer()->get('build.storage')->read('training-plans.html');

        self::assertStringContainsString('Cycling', $trainingPlansHtml);
        self::assertStringContainsString('Bike focus', $trainingPlansHtml);
        self::assertStringContainsString('Bike Tue/Sat', $trainingPlansHtml);
        self::assertStringContainsString('Long ride Sat', $trainingPlansHtml);
        self::assertStringContainsString('FTP 280W', $trainingPlansHtml);
        self::assertStringContainsString('Bike vol 8.5 h/wk', $trainingPlansHtml);
    }

    public function testHandleShowsSpeedEnduranceBuildObjective(): void
    {
        if (!class_exists(\MessageFormatter::class)) {
            self::assertTrue(true);

            return;
        }

        /** @var TrainingPlanRepository $trainingPlanRepository */
        $trainingPlanRepository = $this->getContainer()->get(TrainingPlanRepository::class);
        $trainingPlanRepository->upsert(TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::TRAINING,
            startDay: SerializableDateTime::fromString('2026-04-14 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-05-12 00:00:00'),
            targetRaceEventId: null,
            title: 'Run sharpening block',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            discipline: TrainingPlanDiscipline::RUNNING,
            trainingBlockStyle: TrainingBlockStyle::SPEED_ENDURANCE,
        ));

        $this->commandBus->dispatch(new BuildTrainingPlansHtml(SerializableDateTime::fromString('2026-04-14 08:00:00')));

        $trainingPlansHtml = $this->getContainer()->get('build.storage')->read('training-plans.html');

        self::assertStringContainsString('Run sharpening block', $trainingPlansHtml);
        self::assertStringContainsString('Speed-endurance build', $trainingPlansHtml);
    }

    public function testHandleUsesFurthestPlanEndForNextSuggestedStartDay(): void
    {
        if (!class_exists(\MessageFormatter::class)) {
            self::assertTrue(true);

            return;
        }

        /** @var TrainingPlanRepository $trainingPlanRepository */
        $trainingPlanRepository = $this->getContainer()->get(TrainingPlanRepository::class);
        $trainingPlanRepository->upsert(TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::TRAINING,
            startDay: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-06-30 00:00:00'),
            targetRaceEventId: null,
            title: 'Long season arc',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        ));
        $trainingPlanRepository->upsert(TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::TRAINING,
            startDay: SerializableDateTime::fromString('2026-05-01 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-05-31 00:00:00'),
            targetRaceEventId: null,
            title: 'Short sharpening block',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-01-02 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-02 08:00:00'),
        ));

        $this->commandBus->dispatch(new BuildTrainingPlansHtml(SerializableDateTime::fromString('2026-04-14 08:00:00')));

        $trainingPlansHtml = $this->getContainer()->get('build.storage')->read('training-plans.html');

        self::assertStringContainsString('2026-07-01', $trainingPlansHtml);
        self::assertStringNotContainsString('2026-06-01', $trainingPlansHtml);
    }
}