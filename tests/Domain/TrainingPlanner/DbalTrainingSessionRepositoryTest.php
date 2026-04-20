<?php

declare(strict_types=1);

namespace App\Tests\Domain\TrainingPlanner;

use App\Domain\Activity\ActivityType;
use App\Domain\TrainingPlanner\DbalTrainingSessionRepository;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionEstimationSource;
use App\Domain\TrainingPlanner\PlannedSessionId;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Domain\TrainingPlanner\PlannedSessionLinkStatus;
use App\Domain\TrainingPlanner\TrainingSession;
use App\Domain\TrainingPlanner\TrainingSessionId;
use App\Domain\TrainingPlanner\TrainingSessionObjective;
use App\Domain\TrainingPlanner\TrainingSessionRecommendationCriteria;
use App\Domain\TrainingPlanner\TrainingSessionRepository;
use App\Domain\TrainingPlanner\TrainingSessionSource;
use App\Domain\TrainingPlanner\TrainingBlockPhase;
use App\Tests\ContainerTestCase;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final class DbalTrainingSessionRepositoryTest extends ContainerTestCase
{
    private TrainingSessionRepository $repository;

    public function testUpsertAndFindBySourcePlannedSessionId(): void
    {
        $plannedSession = $this->createPlannedSession(
            plannedSessionId: PlannedSessionId::random(),
            activityType: ActivityType::RUN,
            title: 'Tuesday tempo run',
            day: '2026-04-15 00:00:00',
            targetLoad: 58.4,
            targetDurationInSeconds: 3600,
            updatedAt: '2026-04-10 09:00:00',
        );

        $this->repository->upsert(TrainingSession::createFromPlannedSession($plannedSession));

        $storedTrainingSession = $this->repository->findBySourcePlannedSessionId($plannedSession->getId());
        self::assertNotNull($storedTrainingSession);
        self::assertSame('Tuesday tempo run', $storedTrainingSession->getTitle());
        self::assertSame(58.4, $storedTrainingSession->getTargetLoad());
        self::assertSame('2026-04-15 00:00:00', $storedTrainingSession->getLastPlannedOn()?->format('Y-m-d H:i:s'));
    }

    public function testUpdatingSyncedRecordPreservesTrainingSessionId(): void
    {
        $plannedSessionId = PlannedSessionId::random();
        $initialPlannedSession = $this->createPlannedSession(
            plannedSessionId: $plannedSessionId,
            activityType: ActivityType::RUN,
            title: 'Initial workout',
            day: '2026-04-16 00:00:00',
            targetLoad: 44.0,
            targetDurationInSeconds: 3000,
            updatedAt: '2026-04-10 09:00:00',
        );

        $this->repository->upsert(TrainingSession::createFromPlannedSession($initialPlannedSession));
        $storedBeforeUpdate = $this->repository->findBySourcePlannedSessionId($plannedSessionId);
        self::assertNotNull($storedBeforeUpdate);

        $updatedPlannedSession = $this->createPlannedSession(
            plannedSessionId: $plannedSessionId,
            activityType: ActivityType::RUN,
            title: 'Updated workout',
            day: '2026-04-18 00:00:00',
            targetLoad: 61.2,
            targetDurationInSeconds: 3900,
            updatedAt: '2026-04-12 12:15:00',
        );

        $this->repository->upsert(TrainingSession::createFromPlannedSession($updatedPlannedSession, $storedBeforeUpdate));

        $storedAfterUpdate = $this->repository->findBySourcePlannedSessionId($plannedSessionId);
        self::assertNotNull($storedAfterUpdate);
        self::assertSame((string) $storedBeforeUpdate->getId(), (string) $storedAfterUpdate->getId());
        self::assertSame('Updated workout', $storedAfterUpdate->getTitle());
        self::assertSame(61.2, $storedAfterUpdate->getTargetLoad());
        self::assertSame(3900, $storedAfterUpdate->getTargetDurationInSeconds());
        self::assertSame('2026-04-18 00:00:00', $storedAfterUpdate->getLastPlannedOn()?->format('Y-m-d H:i:s'));
    }

    public function testFindRecommendedReturnsMostRecentMatchingActivityTypeOnly(): void
    {
        $this->repository->upsert(TrainingSession::createFromPlannedSession($this->createPlannedSession(
            plannedSessionId: PlannedSessionId::random(),
            activityType: ActivityType::RUN,
            title: 'Easy run',
            day: '2026-04-08 00:00:00',
            updatedAt: '2026-04-08 07:00:00',
        )));
        $this->repository->upsert(TrainingSession::createFromPlannedSession($this->createPlannedSession(
            plannedSessionId: PlannedSessionId::random(),
            activityType: ActivityType::RUN,
            title: 'Threshold run',
            day: '2026-04-12 00:00:00',
            updatedAt: '2026-04-12 07:00:00',
        )));
        $this->repository->upsert(TrainingSession::createFromPlannedSession($this->createPlannedSession(
            plannedSessionId: PlannedSessionId::random(),
            activityType: ActivityType::RIDE,
            title: 'Long ride',
            day: '2026-04-13 00:00:00',
            updatedAt: '2026-04-13 07:00:00',
        )));
        $this->repository->upsert(TrainingSession::createFromPlannedSession($this->createPlannedSession(
            plannedSessionId: PlannedSessionId::random(),
            activityType: ActivityType::RUN,
            title: 'VO2 run',
            day: '2026-04-11 00:00:00',
            updatedAt: '2026-04-11 07:00:00',
        )));

        $recommendedTrainingSessions = $this->repository->findRecommended(ActivityType::RUN, 2);

        self::assertCount(2, $recommendedTrainingSessions);
        self::assertSame('Threshold run', $recommendedTrainingSessions[0]->getTitle());
        self::assertSame('VO2 run', $recommendedTrainingSessions[1]->getTitle());
        self::assertSame(ActivityType::RUN, $recommendedTrainingSessions[0]->getActivityType());
        self::assertSame(ActivityType::RUN, $recommendedTrainingSessions[1]->getActivityType());
    }

    public function testFindDuplicatesOfMatchesEquivalentSessionsIgnoringSourceAndDates(): void
    {
        $firstDuplicate = TrainingSession::create(
            trainingSessionId: TrainingSessionId::random(),
            sourcePlannedSessionId: PlannedSessionId::random(),
            activityType: ActivityType::RUN,
            title: 'Library tempo run',
            notes: '5 x 5 minutes at threshold',
            targetLoad: 74.0,
            targetDurationInSeconds: 4200,
            targetIntensity: PlannedSessionIntensity::MODERATE,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::WORKOUT_TARGETS,
            lastPlannedOn: SerializableDateTime::fromString('2026-04-08 00:00:00'),
            createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-08 07:00:00'),
            workoutSteps: [[
                'itemId' => 'tempo-step',
                'parentBlockId' => null,
                'type' => 'steady',
                'label' => 'Tempo block',
                'repetitions' => 1,
                'targetType' => 'time',
                'conditionType' => '',
                'durationInSeconds' => 4200,
                'distanceInMeters' => null,
                'targetPace' => '4:20/km',
                'targetPower' => null,
                'targetHeartRate' => null,
                'recoveryAfterInSeconds' => null,
            ]],
        );
        $secondDuplicate = TrainingSession::create(
            trainingSessionId: TrainingSessionId::random(),
            sourcePlannedSessionId: PlannedSessionId::random(),
            activityType: ActivityType::RUN,
            title: 'Library tempo run',
            notes: '5 x 5 minutes at threshold',
            targetLoad: 74.0,
            targetDurationInSeconds: 4200,
            targetIntensity: PlannedSessionIntensity::MODERATE,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::WORKOUT_TARGETS,
            lastPlannedOn: SerializableDateTime::fromString('2026-04-12 00:00:00'),
            createdAt: SerializableDateTime::fromString('2026-04-02 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-12 07:00:00'),
            workoutSteps: [[
                'itemId' => 'tempo-step',
                'parentBlockId' => null,
                'type' => 'steady',
                'label' => 'Tempo block',
                'repetitions' => 1,
                'targetType' => 'time',
                'conditionType' => '',
                'durationInSeconds' => 4200,
                'distanceInMeters' => null,
                'targetPace' => '4:20/km',
                'targetPower' => null,
                'targetHeartRate' => null,
                'recoveryAfterInSeconds' => null,
            ]],
        );
        $differentSession = TrainingSession::create(
            trainingSessionId: TrainingSessionId::random(),
            sourcePlannedSessionId: PlannedSessionId::random(),
            activityType: ActivityType::RUN,
            title: 'Different workout',
            notes: 'Short intervals',
            targetLoad: 60.0,
            targetDurationInSeconds: 3000,
            targetIntensity: PlannedSessionIntensity::HARD,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
            lastPlannedOn: SerializableDateTime::fromString('2026-04-09 00:00:00'),
            createdAt: SerializableDateTime::fromString('2026-04-03 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-09 07:00:00'),
            workoutSteps: [],
        );

        $this->repository->upsert($firstDuplicate);
        $this->repository->upsert($secondDuplicate);
        $this->repository->upsert($differentSession);

        $matchingDuplicates = $this->repository->findDuplicatesOf(TrainingSession::create(
            trainingSessionId: TrainingSessionId::random(),
            sourcePlannedSessionId: PlannedSessionId::random(),
            activityType: ActivityType::RUN,
            title: 'Library tempo run',
            notes: '5 x 5 minutes at threshold',
            targetLoad: 74.0,
            targetDurationInSeconds: 4200,
            targetIntensity: PlannedSessionIntensity::MODERATE,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::WORKOUT_TARGETS,
            lastPlannedOn: SerializableDateTime::fromString('2026-04-20 00:00:00'),
            createdAt: SerializableDateTime::fromString('2026-04-20 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-20 08:00:00'),
            workoutSteps: [[
                'itemId' => 'tempo-step',
                'parentBlockId' => null,
                'type' => 'steady',
                'label' => 'Tempo block',
                'repetitions' => 1,
                'targetType' => 'time',
                'conditionType' => '',
                'durationInSeconds' => 4200,
                'distanceInMeters' => null,
                'targetPace' => '4:20/km',
                'targetPower' => null,
                'targetHeartRate' => null,
                'recoveryAfterInSeconds' => null,
            ]],
        ));

        self::assertCount(2, $matchingDuplicates);
        self::assertSame((string) $secondDuplicate->getId(), (string) $matchingDuplicates[0]->getId());
        self::assertSame((string) $firstDuplicate->getId(), (string) $matchingDuplicates[1]->getId());
    }

    public function testFindRecommendedCanFilterByMetadata(): void
    {
        $this->repository->upsert(TrainingSession::create(
            trainingSessionId: TrainingSessionId::random(),
            sourcePlannedSessionId: null,
            activityType: ActivityType::RUN,
            title: 'Race-pace rehearsal',
            notes: 'Specific work',
            targetLoad: 82.0,
            targetDurationInSeconds: 5400,
            targetIntensity: PlannedSessionIntensity::HARD,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::WORKOUT_TARGETS,
            sessionSource: TrainingSessionSource::RESEARCH_LIBRARY,
            sessionPhase: TrainingBlockPhase::PEAK,
            sessionObjective: TrainingSessionObjective::RACE_SPECIFIC,
            lastPlannedOn: SerializableDateTime::fromString('2026-04-18 00:00:00'),
            createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-18 08:00:00'),
            workoutSteps: [[
                'itemId' => 'step-1',
                'parentBlockId' => null,
                'type' => 'steady',
                'label' => 'Race pace',
                'repetitions' => 1,
                'targetType' => 'time',
                'conditionType' => null,
                'durationInSeconds' => 5400,
                'distanceInMeters' => null,
                'targetPace' => '4:05/km',
                'targetPower' => null,
                'targetHeartRate' => null,
                'recoveryAfterInSeconds' => null,
            ]],
        ));
        $this->repository->upsert(TrainingSession::create(
            trainingSessionId: TrainingSessionId::random(),
            sourcePlannedSessionId: null,
            activityType: ActivityType::RUN,
            title: 'Easy aerobic run',
            notes: 'Endurance support',
            targetLoad: 40.0,
            targetDurationInSeconds: 3000,
            targetIntensity: PlannedSessionIntensity::EASY,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
            sessionSource: TrainingSessionSource::PLANNED_SESSION,
            sessionPhase: TrainingBlockPhase::BASE,
            sessionObjective: TrainingSessionObjective::ENDURANCE,
            lastPlannedOn: SerializableDateTime::fromString('2026-04-17 00:00:00'),
            createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-17 08:00:00'),
        ));

        $recommended = $this->repository->findRecommended(
            ActivityType::RUN,
            5,
            new TrainingSessionRecommendationCriteria(
                sessionPhase: TrainingBlockPhase::PEAK,
                sessionObjective: TrainingSessionObjective::RACE_SPECIFIC,
                sessionSource: TrainingSessionSource::RESEARCH_LIBRARY,
                requiresWorkoutSteps: true,
            ),
        );

        self::assertCount(1, $recommended);
        self::assertSame('Race-pace rehearsal', $recommended[0]->getTitle());
        self::assertSame(TrainingBlockPhase::PEAK, $recommended[0]->getSessionPhase());
        self::assertSame(TrainingSessionObjective::RACE_SPECIFIC, $recommended[0]->getSessionObjective());
        self::assertSame(TrainingSessionSource::RESEARCH_LIBRARY, $recommended[0]->getSessionSource());
    }

    public function testCreateFromPlannedSessionInfersMetadataFromWorkoutDescription(): void
    {
        $plannedSession = $this->createPlannedSession(
            plannedSessionId: PlannedSessionId::random(),
            activityType: ActivityType::RUN,
            title: 'Taper sharpener',
            day: '2026-04-15 00:00:00',
            targetLoad: 45.0,
            targetDurationInSeconds: 2400,
            updatedAt: '2026-04-10 09:00:00',
        );

        $trainingSession = TrainingSession::createFromPlannedSession($plannedSession);

        self::assertSame(TrainingSessionSource::PLANNED_SESSION, $trainingSession->getSessionSource());
        self::assertSame(TrainingBlockPhase::TAPER, $trainingSession->getSessionPhase());
        self::assertSame(TrainingSessionObjective::RACE_SPECIFIC, $trainingSession->getSessionObjective());
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new DbalTrainingSessionRepository($this->getConnection());
    }

    private function createPlannedSession(
        PlannedSessionId $plannedSessionId,
        ActivityType $activityType,
        string $title,
        string $day,
        ?float $targetLoad = 50.0,
        ?int $targetDurationInSeconds = 3600,
        string $updatedAt = '2026-04-10 09:00:00',
    ): PlannedSession {
        return PlannedSession::create(
            plannedSessionId: $plannedSessionId,
            day: SerializableDateTime::fromString($day),
            activityType: $activityType,
            title: $title,
            notes: 'Stored for later recommendation use',
            targetLoad: $targetLoad,
            targetDurationInSeconds: $targetDurationInSeconds,
            targetIntensity: PlannedSessionIntensity::MODERATE,
            templateActivityId: null,
            workoutSteps: [[
                'itemId' => 'step-1',
                'parentBlockId' => null,
                'type' => 'steady',
                'label' => 'Main set',
                'repetitions' => 1,
                'targetType' => 'time',
                'conditionType' => null,
                'durationInSeconds' => $targetDurationInSeconds,
                'distanceInMeters' => null,
                'targetPace' => ActivityType::RUN === $activityType ? '4:45/km' : null,
                'targetPower' => ActivityType::RIDE === $activityType ? 235 : null,
                'targetHeartRate' => null,
                'recoveryAfterInSeconds' => null,
            ]],
            estimationSource: PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString($updatedAt),
        );
    }
}
