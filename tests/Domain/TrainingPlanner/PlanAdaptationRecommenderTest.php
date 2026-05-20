<?php

declare(strict_types=1);

namespace App\Tests\Domain\TrainingPlanner;

use App\Domain\Activity\ActivityType;
use App\Domain\TrainingPlanner\PlanGenerator\PlanAdaptationRecommendationType;
use App\Domain\TrainingPlanner\PlanGenerator\PlanAdaptationRecommender;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionEstimationSource;
use App\Domain\TrainingPlanner\PlannedSessionId;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Domain\TrainingPlanner\PlannedSessionLinkStatus;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventId;
use App\Domain\TrainingPlanner\RaceEventPriority;
use App\Domain\TrainingPlanner\RaceEventType;
use App\Domain\TrainingPlanner\RaceReadinessContextBuilder;
use App\Domain\TrainingPlanner\TrainingBlock;
use App\Domain\TrainingPlanner\TrainingBlockId;
use App\Domain\TrainingPlanner\TrainingBlockPhase;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use PHPUnit\Framework\TestCase;

final class PlanAdaptationRecommenderTest extends TestCase
{
    use BuildsPlannerFixtures;

    public function testRecommendFlagsUpcomingWeeksWithoutEnoughSessions(): void
    {
        $recommender = new PlanAdaptationRecommender();
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
        $buildBlock = TrainingBlock::create(
            trainingBlockId: TrainingBlockId::random(),
            startDay: SerializableDateTime::fromString('2026-04-20 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-05-03 00:00:00'),
            targetRaceEventId: $targetRace->getId(),
            phase: TrainingBlockPhase::BUILD,
            title: 'Build',
            focus: null,
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );
        $oneSession = PlannedSession::create(
            plannedSessionId: PlannedSessionId::random(),
            day: SerializableDateTime::fromString('2026-04-22 00:00:00'),
            activityType: ActivityType::RUN,
            title: 'Steady run',
            notes: null,
            targetLoad: 48.0,
            targetDurationInSeconds: 3000,
            targetIntensity: PlannedSessionIntensity::MODERATE,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
        );

        $recommendations = $recommender->recommend(
            targetRace: $targetRace,
            existingBlocks: [$buildBlock],
            existingSessions: [],
            upcomingRaces: [$targetRace],
            plannedSessionEstimatesById: [],
            readinessContext: null,
            now: SerializableDateTime::fromString('2026-04-14 08:00:00'),
            planWindowSessions: [$oneSession],
            planWindowSessionEstimatesById: [(string) $oneSession->getId() => 48.0],
        );

        self::assertNotEmpty($recommendations);
        self::assertContains(
            PlanAdaptationRecommendationType::INCREASE_LOAD,
            array_map(static fn ($recommendation): PlanAdaptationRecommendationType => $recommendation->getType(), $recommendations),
        );
    }

    public function testRecommendDoesNotFlagExplicitEasyHighLoadSessionsAsTooManyHardSessions(): void
    {
        $recommender = new PlanAdaptationRecommender();
        $readinessContextBuilder = new RaceReadinessContextBuilder();
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
        $buildBlock = TrainingBlock::create(
            trainingBlockId: TrainingBlockId::random(),
            startDay: SerializableDateTime::fromString('2026-04-13 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-04-19 00:00:00'),
            targetRaceEventId: $targetRace->getId(),
            phase: TrainingBlockPhase::BUILD,
            title: 'Build',
            focus: null,
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );
        $sessions = [
            $this->createPlannedSession(
                day: '2026-04-14 00:00:00',
                activityType: ActivityType::RUN,
                title: 'Intervals',
                targetLoad: 107.5,
                targetDurationInSeconds: 2640,
                targetIntensity: PlannedSessionIntensity::HARD,
            ),
            $this->createPlannedSession(
                day: '2026-04-15 00:00:00',
                activityType: ActivityType::RUN,
                title: 'High-load easy run',
                targetLoad: 127.6,
                targetDurationInSeconds: 3900,
                targetIntensity: PlannedSessionIntensity::EASY,
            ),
            $this->createPlannedSession(
                day: '2026-04-16 00:00:00',
                activityType: ActivityType::RIDE,
                title: 'Long ride',
                targetLoad: 291.9,
                targetDurationInSeconds: 11_700,
                targetIntensity: PlannedSessionIntensity::HARD,
            ),
            $this->createPlannedSession(
                day: '2026-04-17 00:00:00',
                activityType: ActivityType::RUN,
                title: 'High-load easy ride mislabeled? nope',
                targetLoad: 115.3,
                targetDurationInSeconds: 3600,
                targetIntensity: PlannedSessionIntensity::EASY,
            ),
            $this->createPlannedSession(
                day: '2026-04-19 00:00:00',
                activityType: ActivityType::RUN,
                title: 'Long run fast finish',
                targetLoad: 309.9,
                targetDurationInSeconds: 8700,
                targetIntensity: PlannedSessionIntensity::HARD,
            ),
        ];
        $plannedSessionEstimatesById = [];

        foreach ($sessions as $session) {
            $plannedSessionEstimatesById[(string) $session->getId()] = $session->getTargetLoad();
        }

        $readinessContext = $readinessContextBuilder->build(
            referenceDate: SerializableDateTime::fromString('2026-04-16 08:00:00'),
            plannedSessions: $sessions,
            raceEvents: [],
            trainingBlocks: [$buildBlock],
            currentTrainingBlock: $buildBlock,
            raceEventsById: [(string) $targetRace->getId() => $targetRace],
            plannedSessionEstimatesById: $plannedSessionEstimatesById,
        );

        $recommendations = $recommender->recommend(
            targetRace: $targetRace,
            existingBlocks: [$buildBlock],
            existingSessions: $sessions,
            upcomingRaces: [$targetRace],
            plannedSessionEstimatesById: $plannedSessionEstimatesById,
            readinessContext: $readinessContext,
            now: SerializableDateTime::fromString('2026-04-16 08:00:00'),
        );

        self::assertSame(3, $readinessContext->getHardSessionCount());
        self::assertNotContains(
            'Too many hard sessions this week',
            array_map(static fn ($recommendation): string => $recommendation->getTitle(), $recommendations),
        );
    }

    public function testRecommendReviewsUpcomingWeeksChronologicallyWhenBlocksAreUnsorted(): void
    {
        $recommender = new PlanAdaptationRecommender();
        $targetRace = $this->createRaceEvent('2026-08-16 00:00:00', RaceEventType::HALF_DISTANCE_TRIATHLON, 'Late summer A-race');
        $blocks = [
            $this->createTrainingBlock('2026-06-09 00:00:00', '2026-06-15 00:00:00', TrainingBlockPhase::BUILD, 'Build week 4', $targetRace->getId()),
            $this->createTrainingBlock('2026-05-19 00:00:00', '2026-05-25 00:00:00', TrainingBlockPhase::BUILD, 'Build week 1', $targetRace->getId()),
            $this->createTrainingBlock('2026-06-02 00:00:00', '2026-06-08 00:00:00', TrainingBlockPhase::BUILD, 'Build week 3', $targetRace->getId()),
            $this->createTrainingBlock('2026-05-26 00:00:00', '2026-06-01 00:00:00', TrainingBlockPhase::BUILD, 'Build week 2', $targetRace->getId()),
            $this->createTrainingBlock('2026-06-16 00:00:00', '2026-06-22 00:00:00', TrainingBlockPhase::BUILD, 'Build week 5', $targetRace->getId()),
        ];
        $sessions = [
            $this->createPlannedSession(
                day: '2026-06-03 00:00:00',
                activityType: ActivityType::RUN,
                title: 'Aerobic run',
                targetLoad: 52.0,
                targetDurationInSeconds: 3300,
                targetIntensity: PlannedSessionIntensity::MODERATE,
            ),
            $this->createPlannedSession(
                day: '2026-06-05 00:00:00',
                activityType: ActivityType::RIDE,
                title: 'Steady ride',
                targetLoad: 61.0,
                targetDurationInSeconds: 4200,
                targetIntensity: PlannedSessionIntensity::MODERATE,
            ),
            $this->createPlannedSession(
                day: '2026-06-10 00:00:00',
                activityType: ActivityType::RUN,
                title: 'Tempo run',
                targetLoad: 58.0,
                targetDurationInSeconds: 3000,
                targetIntensity: PlannedSessionIntensity::MODERATE,
            ),
            $this->createPlannedSession(
                day: '2026-06-12 00:00:00',
                activityType: ActivityType::RIDE,
                title: 'Sweet spot ride',
                targetLoad: 74.0,
                targetDurationInSeconds: 4800,
                targetIntensity: PlannedSessionIntensity::MODERATE,
            ),
            $this->createPlannedSession(
                day: '2026-06-17 00:00:00',
                activityType: ActivityType::RUN,
                title: 'Endurance run',
                targetLoad: 49.0,
                targetDurationInSeconds: 3600,
                targetIntensity: PlannedSessionIntensity::EASY,
            ),
            $this->createPlannedSession(
                day: '2026-06-19 00:00:00',
                activityType: ActivityType::RIDE,
                title: 'Endurance ride',
                targetLoad: 68.0,
                targetDurationInSeconds: 5400,
                targetIntensity: PlannedSessionIntensity::MODERATE,
            ),
        ];

        $recommendations = $recommender->recommend(
            targetRace: $targetRace,
            existingBlocks: $blocks,
            existingSessions: [],
            upcomingRaces: [$targetRace],
            plannedSessionEstimatesById: [],
            readinessContext: null,
            now: SerializableDateTime::fromString('2026-05-19 08:00:00'),
            planWindowSessions: $sessions,
            planWindowSessionEstimatesById: $this->buildPlannedSessionEstimatesById($sessions),
        );

        self::assertContains(
            'No sessions in Build week',
            array_map(static fn ($recommendation): string => $recommendation->getTitle(), $recommendations),
        );
    }

}
