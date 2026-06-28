<?php

declare(strict_types=1);

namespace App\Tests\Domain\Dashboard\Widget\TrainingLoad;

use App\Domain\Dashboard\Widget\TrainingLoad\DailyTrainingGuidance;
use App\Domain\Dashboard\Widget\TrainingLoad\ReadinessScore;
use App\Domain\Dashboard\Widget\TrainingLoad\RecoveryTrendWarningType;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingMetrics;
use PHPUnit\Framework\TestCase;

final class DailyTrainingGuidanceTest extends TestCase
{
    public function testItPromptsForARecoveryCheckInWhenReadinessIsMissing(): void
    {
        $guidance = DailyTrainingGuidance::fromSignals(
            readinessScore: null,
            trainingMetrics: TrainingMetrics::create([]),
            restDaysInLast7Days: 2,
            recoveryTrendWarnings: [],
        );

        self::assertSame('Complete today\'s check-in', $guidance->getTitle());
        self::assertSame('neutral', $guidance->getTone());
        self::assertSame('Missing', $guidance->getSignals()[0]['value']);
    }

    public function testItPrioritizesRecoveryWhenReadinessIsSuppressed(): void
    {
        $guidance = DailyTrainingGuidance::fromSignals(
            readinessScore: ReadinessScore::of(34),
            trainingMetrics: TrainingMetrics::create([]),
            restDaysInLast7Days: 1,
            recoveryTrendWarnings: [],
        );

        self::assertSame('Keep today easy', $guidance->getTitle());
        self::assertSame('danger', $guidance->getTone());
    }

    public function testItRebuildsMomentumWhenLoadIsTooLow(): void
    {
        $guidance = DailyTrainingGuidance::fromSignals(
            readinessScore: ReadinessScore::of(68),
            trainingMetrics: $this->trainingMetrics([0, 0, 0, 0, 0, 0, 0]),
            restDaysInLast7Days: 4,
            recoveryTrendWarnings: [],
        );

        self::assertSame('Rebuild momentum', $guidance->getTitle());
        self::assertSame('caution', $guidance->getTone());
        self::assertSame('Low load', $guidance->getSignals()[2]['value']);
    }

    public function testItSurfacesRecoveryTrendWarnings(): void
    {
        $guidance = DailyTrainingGuidance::fromSignals(
            readinessScore: ReadinessScore::of(72),
            trainingMetrics: TrainingMetrics::create([]),
            restDaysInLast7Days: 1,
            recoveryTrendWarnings: [RecoveryTrendWarningType::MONOTONY_RISK],
        );

        self::assertSame('Keep today easy', $guidance->getTitle());
        self::assertSame('1 signal', $guidance->getSignals()[4]['value']);
        self::assertSame('caution', $guidance->getSignals()[4]['tone']);
    }

    /**
     * @param list<int> $dailyLoads
     */
    private function trainingMetrics(array $dailyLoads): TrainingMetrics
    {
        $loadsByDay = [];
        foreach ($dailyLoads as $index => $dailyLoad) {
            $loadsByDay[sprintf('2026-04-%02d', $index + 1)] = $dailyLoad;
        }

        return TrainingMetrics::create($loadsByDay);
    }
}
