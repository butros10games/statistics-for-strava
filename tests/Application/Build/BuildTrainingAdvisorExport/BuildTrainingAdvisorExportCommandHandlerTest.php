<?php

declare(strict_types=1);

namespace App\Tests\Application\Build\BuildTrainingAdvisorExport;

use App\Application\Build\BuildTrainingAdvisorExport\BuildTrainingAdvisorExport;
use App\Domain\Activity\ActivityType;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionEstimationSource;
use App\Domain\TrainingPlanner\PlannedSessionId;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Domain\TrainingPlanner\PlannedSessionLinkStatus;
use App\Domain\TrainingPlanner\PlannedSessionRepository;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventId;
use App\Domain\TrainingPlanner\RaceEventPriority;
use App\Domain\TrainingPlanner\RaceEventRepository;
use App\Domain\TrainingPlanner\RaceEventType;
use App\Domain\TrainingPlanner\TrainingBlock;
use App\Domain\TrainingPlanner\TrainingBlockId;
use App\Domain\TrainingPlanner\TrainingBlockPhase;
use App\Domain\TrainingPlanner\TrainingBlockRepository;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\Application\BuildAppFilesTestCase;

final class BuildTrainingAdvisorExportCommandHandlerTest extends BuildAppFilesTestCase
{
    public function testHandle(): void
    {
        if (!class_exists(\MessageFormatter::class)) {
            self::assertTrue(true);

            return;
        }

        $this->provideFullTestSet();

        $this->commandBus->dispatch(new BuildTrainingAdvisorExport(
            SerializableDateTime::fromString('2023-10-17 16:15:04')
        ));

        $apiStorage = $this->getContainer()->get('api.storage');

        self::assertTrue($apiStorage->fileExists('exports/training-advisor.json'));

        $payload = Json::uncompressAndDecode($apiStorage->read('exports/training-advisor.json'));

        self::assertSame(1, $payload['version']);
        self::assertSame('training-advisor', $payload['exportType']);
        self::assertSame('2023-10-17 16:15:04', $payload['generatedAt']);
        self::assertSame(42, $payload['windows']['recentActivityDays']);
        self::assertNotEmpty($payload['recentActivities']['items']);
        self::assertArrayHasKey('performanceAnchors', $payload);
        self::assertArrayHasKey('trainingMetrics', $payload['currentStatus']);
        self::assertArrayHasKey('readiness', $payload['currentStatus']);
        self::assertArrayHasKey('last42Days', $payload['trainingLoad']);
        self::assertArrayHasKey('raceReadinessContext', $payload);
        self::assertArrayHasKey('items', $payload['upcomingPlannedSessions']);
        self::assertArrayHasKey('projection', $payload['upcomingPlannedSessions']);
        self::assertArrayHasKey('confidence', $payload['upcomingPlannedSessions']['summary']);
        self::assertArrayHasKey('label', $payload['upcomingPlannedSessions']['summary']['confidence']);
    }

    public function testHandleBuildsRaceReadinessContextIntoExport(): void
    {
        if (!class_exists(\MessageFormatter::class)) {
            self::assertTrue(true);

            return;
        }

        $this->provideFullTestSet();

        /** @var RaceEventRepository $raceEventRepository */
        $raceEventRepository = $this->getContainer()->get(RaceEventRepository::class);
        $targetRace = $this->createRaceEvent(
            day: '2023-11-12 00:00:00',
            type: RaceEventType::HALF_DISTANCE_TRIATHLON,
            title: 'Autumn 70.3',
        );
        $raceEventRepository->upsert($targetRace);

        /** @var TrainingBlockRepository $trainingBlockRepository */
        $trainingBlockRepository = $this->getContainer()->get(TrainingBlockRepository::class);
        $trainingBlockRepository->upsert($this->createTrainingBlock(
            startDay: '2023-10-16 00:00:00',
            endDay: '2023-10-29 00:00:00',
            phase: TrainingBlockPhase::BUILD,
            title: 'October race build',
            targetRaceEventId: $targetRace->getId(),
        ));

        /** @var PlannedSessionRepository $plannedSessionRepository */
        $plannedSessionRepository = $this->getContainer()->get(PlannedSessionRepository::class);
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2023-10-18 00:00:00',
            activityType: ActivityType::RIDE,
            title: 'Wednesday long ride',
            targetLoad: 82.0,
            targetDurationInSeconds: 7200,
            targetIntensity: PlannedSessionIntensity::MODERATE,
        ));
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2023-10-20 00:00:00',
            activityType: ActivityType::RUN,
            title: 'Friday long run',
            targetLoad: 64.0,
            targetDurationInSeconds: 5400,
            targetIntensity: PlannedSessionIntensity::MODERATE,
        ));

        $this->commandBus->dispatch(new BuildTrainingAdvisorExport(
            SerializableDateTime::fromString('2023-10-17 16:15:04')
        ));

        $payload = Json::uncompressAndDecode($this->getContainer()->get('api.storage')->read('exports/training-advisor.json'));

        self::assertSame('Autumn 70.3', $payload['raceReadinessContext']['targetRace']['title']);
        self::assertSame(26, $payload['raceReadinessContext']['countdownDays']);
        self::assertSame('triathlon', $payload['raceReadinessContext']['targetRace']['family']);
        self::assertSame('halfDistanceTriathlon', $payload['raceReadinessContext']['targetRace']['profile']);
        self::assertSame('build', $payload['raceReadinessContext']['trainingBlock']['phase']);
        self::assertSame(2, $payload['raceReadinessContext']['plannerSummary']['sessionCount']);
        self::assertTrue($payload['raceReadinessContext']['plannerSummary']['hasLongRideSession']);
        self::assertTrue($payload['raceReadinessContext']['plannerSummary']['hasLongRunSession']);
        self::assertArrayHasKey('forecast', $payload['raceReadinessContext']);
        self::assertArrayHasKey('confidence', $payload['raceReadinessContext']['forecast']);
    }

    private function createPlannedSession(
        string $day,
        ActivityType $activityType,
        string $title,
        float $targetLoad,
        int $targetDurationInSeconds,
        PlannedSessionIntensity $targetIntensity,
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
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
        );
    }

    private function createRaceEvent(string $day, RaceEventType $type, string $title): RaceEvent
    {
        return RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString($day),
            type: $type,
            title: $title,
            location: null,
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: null,
            createdAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
        );
    }

    private function createTrainingBlock(
        string $startDay,
        string $endDay,
        TrainingBlockPhase $phase,
        string $title,
        ?RaceEventId $targetRaceEventId = null,
    ): TrainingBlock {
        return TrainingBlock::create(
            trainingBlockId: TrainingBlockId::random(),
            startDay: SerializableDateTime::fromString($startDay),
            endDay: SerializableDateTime::fromString($endDay),
            targetRaceEventId: $targetRaceEventId,
            phase: $phase,
            title: $title,
            focus: null,
            notes: null,
            createdAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
        );
    }
}
