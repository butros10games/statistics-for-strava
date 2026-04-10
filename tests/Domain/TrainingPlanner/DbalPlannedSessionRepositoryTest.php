<?php

declare(strict_types=1);

namespace App\Tests\Domain\TrainingPlanner;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityType;
use App\Domain\TrainingPlanner\DbalPlannedSessionRepository;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionEstimationSource;
use App\Domain\TrainingPlanner\PlannedSessionId;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Domain\TrainingPlanner\PlannedSessionLinkStatus;
use App\Domain\TrainingPlanner\PlannedSessionRepository;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;

final class DbalPlannedSessionRepositoryTest extends ContainerTestCase
{
    private PlannedSessionRepository $repository;

    public function testUpsertFindAndDelete(): void
    {
        $id = PlannedSessionId::random();
        $this->repository->upsert(PlannedSession::create(
            plannedSessionId: $id,
            day: SerializableDateTime::fromString('2026-04-09 06:00:00'),
            activityType: ActivityType::RUN,
            title: 'Morning run',
            notes: 'Easy aerobic session',
            targetLoad: 65.5,
            targetDurationInSeconds: 4500,
            targetIntensity: PlannedSessionIntensity::EASY,
            templateActivityId: ActivityId::fromUnprefixed('111'),
            estimationSource: PlannedSessionEstimationSource::TEMPLATE,
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: SerializableDateTime::fromString('2026-04-07 09:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-07 09:00:00'),
            workoutSteps: [
                [
                    'itemId' => 'warmup-1',
                    'parentBlockId' => null,
                    'type' => 'warmup',
                    'label' => 'Easy start',
                    'repetitions' => 1,
                    'targetType' => 'time',
                    'conditionType' => null,
                    'durationInSeconds' => 900,
                    'targetPace' => '5:30/km',
                    'recoveryAfterInSeconds' => null,
                ],
                [
                    'itemId' => 'block-1',
                    'parentBlockId' => null,
                    'type' => 'repeatBlock',
                    'label' => 'Main set',
                    'repetitions' => 6,
                    'targetType' => null,
                    'conditionType' => null,
                    'durationInSeconds' => null,
                    'distanceInMeters' => null,
                    'targetHeartRate' => null,
                    'recoveryAfterInSeconds' => null,
                ],
                [
                    'itemId' => 'interval-1',
                    'parentBlockId' => 'block-1',
                    'type' => 'interval',
                    'label' => '1K reps',
                    'repetitions' => 1,
                    'targetType' => 'distance',
                    'conditionType' => null,
                    'durationInSeconds' => null,
                    'distanceInMeters' => 800,
                    'targetPace' => '4:05/km',
                    'targetHeartRate' => null,
                    'recoveryAfterInSeconds' => null,
                ],
                [
                    'itemId' => 'recovery-1',
                    'parentBlockId' => 'block-1',
                    'type' => 'recovery',
                    'label' => 'Float',
                    'repetitions' => 1,
                    'targetType' => 'time',
                    'conditionType' => null,
                    'durationInSeconds' => 60,
                    'distanceInMeters' => null,
                    'targetPace' => null,
                    'targetHeartRate' => null,
                    'recoveryAfterInSeconds' => null,
                ],
            ],
        ));

        $this->repository->upsert(PlannedSession::create(
            plannedSessionId: PlannedSessionId::random(),
            day: SerializableDateTime::fromString('2026-04-10 06:00:00'),
            activityType: ActivityType::RIDE,
            title: 'Tempo ride',
            notes: null,
            targetLoad: 95.0,
            targetDurationInSeconds: 5400,
            targetIntensity: PlannedSessionIntensity::MODERATE,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
            linkedActivityId: ActivityId::fromUnprefixed('222'),
            linkStatus: PlannedSessionLinkStatus::LINKED,
            createdAt: SerializableDateTime::fromString('2026-04-07 09:30:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-07 09:30:00'),
            workoutSteps: [
                [
                    'itemId' => 'ride-interval-1',
                    'parentBlockId' => null,
                    'type' => 'interval',
                    'label' => 'Tempo pull',
                    'repetitions' => 1,
                    'targetType' => 'time',
                    'conditionType' => null,
                    'durationInSeconds' => 900,
                    'distanceInMeters' => null,
                    'targetPace' => null,
                    'targetPower' => 240,
                    'targetHeartRate' => null,
                    'recoveryAfterInSeconds' => null,
                ],
            ],
        ));

        $records = $this->repository->findByDateRange(DateRange::fromDates(
            from: SerializableDateTime::fromString('2026-04-08 00:00:00'),
            till: SerializableDateTime::fromString('2026-04-11 00:00:00'),
        ));

        self::assertCount(2, $records);
        self::assertSame('Morning run', $this->repository->findById($id)?->getTitle());
        self::assertCount(4, $this->repository->findById($id)?->getWorkoutSteps() ?? []);
        self::assertSame('block-1', $this->repository->findById($id)?->getWorkoutSteps()[2]['parentBlockId']);
        self::assertNull($this->repository->findById($id)?->getWorkoutSteps()[2]['conditionType']);
        self::assertSame(800, $this->repository->findById($id)?->getWorkoutSteps()[2]['distanceInMeters']);
        self::assertCount(1, $this->repository->findByDay(SerializableDateTime::fromString('2026-04-09 16:00:00')));
        self::assertSame(240, $this->repository->findByDay(SerializableDateTime::fromString('2026-04-10 16:00:00'))[0]->getWorkoutSteps()[0]['targetPower']);
        self::assertNull($this->repository->findByDay(SerializableDateTime::fromString('2026-04-10 16:00:00'))[0]->getWorkoutSteps()[0]['targetPace']);
        self::assertSame('2026-04-10 00:00:00', $this->repository->findLatest()?->getDay()->format('Y-m-d H:i:s'));

        $this->repository->delete($id);
        self::assertNull($this->repository->findById($id));
    }

    public function testFindByIdCalculatesWorkoutDurationFromUnitlessRunningPace(): void
    {
        $id = PlannedSessionId::random();

        $this->repository->upsert(PlannedSession::create(
            plannedSessionId: $id,
            day: SerializableDateTime::fromString('2026-04-15 06:00:00'),
            activityType: ActivityType::RUN,
            title: '6x 800 meter',
            notes: null,
            targetLoad: null,
            targetDurationInSeconds: null,
            targetIntensity: PlannedSessionIntensity::HARD,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::UNKNOWN,
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: SerializableDateTime::fromString('2026-04-10 09:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-10 09:00:00'),
            workoutSteps: [
                [
                    'itemId' => 'warmup',
                    'parentBlockId' => null,
                    'type' => 'warmup',
                    'label' => null,
                    'repetitions' => 1,
                    'targetType' => 'time',
                    'conditionType' => 'lapButton',
                    'durationInSeconds' => 600,
                    'distanceInMeters' => null,
                    'targetPace' => '6:00',
                    'targetPower' => null,
                    'targetHeartRate' => null,
                    'recoveryAfterInSeconds' => null,
                ],
                [
                    'itemId' => 'block',
                    'parentBlockId' => null,
                    'type' => 'repeatBlock',
                    'label' => null,
                    'repetitions' => 8,
                    'targetType' => null,
                    'conditionType' => null,
                    'durationInSeconds' => null,
                    'distanceInMeters' => null,
                    'targetPace' => null,
                    'targetPower' => null,
                    'targetHeartRate' => null,
                    'recoveryAfterInSeconds' => null,
                ],
                [
                    'itemId' => 'interval',
                    'parentBlockId' => 'block',
                    'type' => 'interval',
                    'label' => 'ongeveer 3 minuten',
                    'repetitions' => 1,
                    'targetType' => 'distance',
                    'conditionType' => null,
                    'durationInSeconds' => null,
                    'distanceInMeters' => 800,
                    'targetPace' => '4:00',
                    'targetPower' => null,
                    'targetHeartRate' => null,
                    'recoveryAfterInSeconds' => null,
                ],
                [
                    'itemId' => 'recovery',
                    'parentBlockId' => 'block',
                    'type' => 'recovery',
                    'label' => null,
                    'repetitions' => 1,
                    'targetType' => 'time',
                    'conditionType' => null,
                    'durationInSeconds' => 60,
                    'distanceInMeters' => null,
                    'targetPace' => null,
                    'targetPower' => null,
                    'targetHeartRate' => null,
                    'recoveryAfterInSeconds' => null,
                ],
                [
                    'itemId' => 'cooldown',
                    'parentBlockId' => null,
                    'type' => 'cooldown',
                    'label' => null,
                    'repetitions' => 1,
                    'targetType' => 'time',
                    'conditionType' => 'lapButton',
                    'durationInSeconds' => 600,
                    'distanceInMeters' => null,
                    'targetPace' => '6:00',
                    'targetPower' => null,
                    'targetHeartRate' => null,
                    'recoveryAfterInSeconds' => null,
                ],
            ],
        ));

        self::assertSame(3216, $this->repository->findById($id)?->getWorkoutDurationInSeconds());
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new DbalPlannedSessionRepository($this->getConnection());
    }
}
