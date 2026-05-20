<?php

declare(strict_types=1);

namespace App\Tests\Domain\TrainingPlanner;

use App\Domain\Activity\ActivityType;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionDemandClassifier;
use App\Domain\TrainingPlanner\PlannedSessionEstimationSource;
use App\Domain\TrainingPlanner\PlannedSessionId;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Domain\TrainingPlanner\PlannedSessionLinkStatus;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use PHPUnit\Framework\TestCase;

final class PlannedSessionDemandClassifierTest extends TestCase
{
    public function testIsHardRespectsExplicitHardIntensityOverEstimate(): void
    {
        $session = $this->createPlannedSession(
            title: 'Threshold run',
            targetLoad: 62.0,
            targetIntensity: PlannedSessionIntensity::HARD,
        );

        self::assertTrue(PlannedSessionDemandClassifier::isHard($session, [
            (string) $session->getId() => 62.0,
        ]));
    }

    public function testIsEasyRespectsExplicitEasyIntensityEvenWithHighEstimate(): void
    {
        $session = $this->createPlannedSession(
            title: 'High-load easy ride',
            targetLoad: 127.6,
            targetIntensity: PlannedSessionIntensity::EASY,
        );

        self::assertTrue(PlannedSessionDemandClassifier::isEasy($session, [
            (string) $session->getId() => 127.6,
        ]));
        self::assertFalse(PlannedSessionDemandClassifier::isHard($session, [
            (string) $session->getId() => 127.6,
        ]));
    }

    public function testLoadThresholdsClassifyFallbackSessions(): void
    {
        $hardSession = $this->createPlannedSession(
            title: 'Unlabelled big day',
            targetLoad: 120.0,
            targetIntensity: null,
        );
        $easySession = $this->createPlannedSession(
            title: 'Unlabelled easy day',
            targetLoad: 42.0,
            targetIntensity: null,
        );

        self::assertTrue(PlannedSessionDemandClassifier::isHard($hardSession, [
            (string) $hardSession->getId() => 120.0,
        ]));
        self::assertTrue(PlannedSessionDemandClassifier::isEasy($easySession, [
            (string) $easySession->getId() => 42.0,
        ]));
    }

    private function createPlannedSession(string $title, float $targetLoad, ?PlannedSessionIntensity $targetIntensity): PlannedSession
    {
        return PlannedSession::create(
            plannedSessionId: PlannedSessionId::random(),
            day: SerializableDateTime::fromString('2026-04-16 00:00:00'),
            activityType: ActivityType::RUN,
            title: $title,
            notes: null,
            targetLoad: $targetLoad,
            targetDurationInSeconds: 3600,
            targetIntensity: $targetIntensity,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
        );
    }
}