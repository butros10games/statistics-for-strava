<?php

declare(strict_types=1);

namespace App\Tests\Domain\TrainingPlanner;

use App\Domain\Activity\ActivityType;
use App\Domain\Dashboard\Widget\TrainingLoad\ReadinessScore;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingLoadForecastProjection;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingMetrics;
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

final class RaceReadinessContextBuilderTest extends TestCase
{
    private RaceReadinessContextBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new RaceReadinessContextBuilder();
    }

    public function testBuildPrefersTrainingBlockTargetRaceAndCapturesPlannerShape(): void
    {
        $referenceDate = SerializableDateTime::fromString('2023-10-17 16:15:04');
        $targetRace = $this->createRaceEvent(
            day: '2023-11-12 00:00:00',
            type: RaceEventType::FULL_DISTANCE_TRIATHLON,
            title: 'A-race Iron-distance',
        );
        $currentWeekTuneUpRace = $this->createRaceEvent(
            day: '2023-10-19 00:00:00',
            type: RaceEventType::RUN,
            title: 'Local 10K tune-up',
        );
        $trainingBlock = $this->createTrainingBlock(
            startDay: '2023-10-09 00:00:00',
            endDay: '2023-10-29 00:00:00',
            phase: TrainingBlockPhase::BUILD,
            title: 'October long-course build',
            targetRaceEventId: $targetRace->getId(),
        );
        $plannedSessions = [
            $this->createPlannedSession(
                day: '2023-10-18 00:00:00',
                activityType: ActivityType::RIDE,
                title: 'Wednesday long ride',
                targetLoad: 82.0,
                targetDurationInSeconds: 7200,
                targetIntensity: PlannedSessionIntensity::MODERATE,
            ),
            $this->createPlannedSession(
                day: '2023-10-20 00:00:00',
                activityType: ActivityType::RUN,
                title: 'Friday long run',
                targetLoad: 64.0,
                targetDurationInSeconds: 5400,
                targetIntensity: PlannedSessionIntensity::MODERATE,
            ),
            $this->createPlannedSession(
                day: '2023-10-20 00:00:00',
                activityType: ActivityType::RIDE,
                title: 'Friday bike primer',
                targetLoad: 55.0,
                targetDurationInSeconds: 4200,
                targetIntensity: PlannedSessionIntensity::HARD,
            ),
        ];

        $context = $this->builder->build(
            referenceDate: $referenceDate,
            plannedSessions: $plannedSessions,
            raceEvents: [$currentWeekTuneUpRace],
            trainingBlocks: [$trainingBlock],
            currentTrainingBlock: $trainingBlock,
            raceEventsById: [
                (string) $targetRace->getId() => $targetRace,
                (string) $currentWeekTuneUpRace->getId() => $currentWeekTuneUpRace,
            ],
            plannedSessionEstimatesById: $this->buildPlannedSessionEstimatesById($plannedSessions),
        );

        self::assertSame('A-race Iron-distance', $context->getTargetRace()?->getTitle());
        self::assertSame(26, $context->getTargetRaceCountdownDays());
        self::assertSame('October long-course build', $context->getPrimaryTrainingBlock()?->getTitle());
        self::assertTrue($context->hasRaceEventInContextWindow());
        self::assertSame(3, $context->getSessionCount());
        self::assertSame(2, $context->getDistinctSessionDayCount());
        self::assertSame(1, $context->getHardSessionCount());
        self::assertSame(1, $context->getBrickDayCount());
        self::assertTrue($context->hasLongRideSession());
        self::assertTrue($context->hasLongRunSession());
        self::assertSame(['swim' => 0, 'bike' => 2, 'run' => 1], $context->getDisciplineCounts());
        self::assertSame(201.0, $context->getEstimatedLoad());
    }

    public function testBuildCarriesReadinessAndForecastSignals(): void
    {
        $projection = TrainingLoadForecastProjection::createWithProjectedLoads(
            metrics: TrainingMetrics::create([
                '2023-10-03' => 92,
                '2023-10-04' => 88,
                '2023-10-05' => 94,
                '2023-10-06' => 90,
                '2023-10-07' => 86,
                '2023-10-08' => 84,
                '2023-10-09' => 82,
                '2023-10-10' => 80,
                '2023-10-11' => 78,
                '2023-10-12' => 76,
                '2023-10-13' => 74,
                '2023-10-14' => 72,
                '2023-10-15' => 70,
                '2023-10-16' => 68,
            ]),
            now: SerializableDateTime::fromString('2023-10-17 16:15:04'),
            projectedLoads: [1 => 45, 2 => 35, 3 => 25, 4 => 20, 5 => 15, 6 => 10, 7 => 0],
            horizon: 7,
        );

        $context = $this->builder->build(
            referenceDate: SerializableDateTime::fromString('2023-10-17 16:15:04'),
            plannedSessions: [],
            raceEvents: [],
            trainingBlocks: [],
            currentTrainingBlock: null,
            raceEventsById: [],
            plannedSessionEstimatesById: [],
            readinessScore: ReadinessScore::of(72),
            forecastProjection: $projection,
        );

        self::assertSame(72, $context->getReadinessScore()?->getValue());
        self::assertSame('high', $context->getForecastConfidence()?->value);
        self::assertNotNull($context->getForecastDaysUntilTsbHealthy());
        self::assertNotNull($context->getForecastDaysUntilAcRatioHealthy());
    }

    public function testBuildDoesNotCountEasyHighLoadSessionsAsHardWhenIntensityIsExplicit(): void
    {
        $referenceDate = SerializableDateTime::fromString('2026-04-16 08:00:00');
        $plannedSessions = [
            $this->createPlannedSession(
                day: '2026-04-14 00:00:00',
                activityType: ActivityType::RUN,
                title: 'Fast intervals',
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
                title: 'High-load easy ride',
                targetLoad: 115.3,
                targetDurationInSeconds: 3600,
                targetIntensity: PlannedSessionIntensity::EASY,
            ),
        ];

        $context = $this->builder->build(
            referenceDate: $referenceDate,
            plannedSessions: $plannedSessions,
            raceEvents: [],
            trainingBlocks: [],
            currentTrainingBlock: null,
            raceEventsById: [],
            plannedSessionEstimatesById: $this->buildPlannedSessionEstimatesById($plannedSessions),
        );

        self::assertSame(1, $context->getHardSessionCount());
        self::assertSame(2, $context->getEasySessionCount());
    }

    private function buildPlannedSessionEstimatesById(array $plannedSessions): array
    {
        $estimatesById = [];

        foreach ($plannedSessions as $plannedSession) {
            $estimatesById[(string) $plannedSession->getId()] = $plannedSession->getTargetLoad();
        }

        return $estimatesById;
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