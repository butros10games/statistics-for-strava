<?php

declare(strict_types=1);

namespace App\Tests\Domain\Dashboard\Widget\TrainingLoad;

use App\Domain\Dashboard\Widget\TrainingLoad\ReadinessAssessment;
use App\Domain\Dashboard\Widget\TrainingLoad\ReadinessFactor;
use App\Domain\Dashboard\Widget\TrainingLoad\ReadinessMismatchAnalyzer;
use PHPUnit\Framework\TestCase;

final class ReadinessMismatchAnalyzerTest extends TestCase
{
    private ReadinessMismatchAnalyzer $analyzer;

    public function testItDetectsWhenSubjectiveStateLooksWorseThanObjectiveMetrics(): void
    {
        $insight = $this->analyzer->analyze(
            $this->buildAssessment(
                ReadinessFactor::create(ReadinessFactor::KEY_HRV, 'HRV', 3.0),
                ReadinessFactor::create(ReadinessFactor::KEY_SLEEP_SCORE, 'Sleep score', 1.0),
                ReadinessFactor::create(ReadinessFactor::KEY_TSB, 'Form (TSB)', -2.0),
                ReadinessFactor::create(ReadinessFactor::KEY_RECOVERY_CHECK_IN, 'Recovery check-in', -12.0),
            ),
            ['day' => '2026-04-07', 'fatigue' => 4, 'soreness' => 4, 'stress' => 3, 'motivation' => 2, 'sleepQuality' => 2],
        );

        self::assertNotNull($insight);
        self::assertSame('subjectiveWorse', $insight->getKey());
    }

    public function testItDetectsWhenObjectiveRecoveryMetricsLagBehindPerceivedState(): void
    {
        $insight = $this->analyzer->analyze(
            $this->buildAssessment(
                ReadinessFactor::create(ReadinessFactor::KEY_HRV, 'HRV', -9.0),
                ReadinessFactor::create(ReadinessFactor::KEY_SLEEP_DURATION, 'Sleep duration', -4.0),
                ReadinessFactor::create(ReadinessFactor::KEY_MONOTONY, 'Monotony', -3.0),
                ReadinessFactor::create(ReadinessFactor::KEY_RECOVERY_CHECK_IN, 'Recovery check-in', -1.0),
            ),
            ['day' => '2026-04-07', 'fatigue' => 2, 'soreness' => 2, 'stress' => 2, 'motivation' => 4, 'sleepQuality' => 4],
        );

        self::assertNotNull($insight);
        self::assertSame('objectiveWorse', $insight->getKey());
    }

    public function testItFlagsStressDominantDaysSeparately(): void
    {
        $insight = $this->analyzer->analyze(
            $this->buildAssessment(
                ReadinessFactor::create(ReadinessFactor::KEY_HRV, 'HRV', -2.0),
                ReadinessFactor::create(ReadinessFactor::KEY_SLEEP_SCORE, 'Sleep score', 0.0),
                ReadinessFactor::create(ReadinessFactor::KEY_TSB, 'Form (TSB)', -1.0),
                ReadinessFactor::create(ReadinessFactor::KEY_RECOVERY_CHECK_IN, 'Recovery check-in', -8.0),
            ),
            ['day' => '2026-04-07', 'fatigue' => 3, 'soreness' => 2, 'stress' => 5, 'motivation' => 2, 'sleepQuality' => 3],
        );

        self::assertNotNull($insight);
        self::assertSame('stressDominant', $insight->getKey());
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->analyzer = new ReadinessMismatchAnalyzer();
    }

    private function buildAssessment(ReadinessFactor ...$factors): ReadinessAssessment
    {
        return ReadinessAssessment::fromFactors(62, [
            ReadinessFactor::create(ReadinessFactor::KEY_BASELINE, 'Baseline', 55.0, false),
            ...$factors,
        ]);
    }
}
