<?php

declare(strict_types=1);

namespace App\Tests\Domain\Dashboard\Widget\TrainingLoad;

use App\Domain\Activity\ActivityType;
use App\Domain\Dashboard\Widget\TrainingLoad\ActivityTypeRecoveryFingerprintAnalyzer;
use App\Domain\Dashboard\Widget\TrainingLoad\ActivityTypeRecoveryFingerprintProfile;
use PHPUnit\Framework\TestCase;

final class ActivityTypeRecoveryFingerprintAnalyzerTest extends TestCase
{
    public function testItBuildsRecoveryFingerprintsPerActivityType(): void
    {
        $fingerprints = (new ActivityTypeRecoveryFingerprintAnalyzer())->analyze([
            ['day' => '2026-03-28', 'activityType' => ActivityType::RUN, 'load' => 120, 'nextDayHrv' => 58.0, 'nextDaySleepScore' => 72, 'nextDayFatigue' => 4],
            ['day' => '2026-03-30', 'activityType' => ActivityType::RUN, 'load' => 125, 'nextDayHrv' => 57.0, 'nextDaySleepScore' => 71, 'nextDayFatigue' => 4],
            ['day' => '2026-04-01', 'activityType' => ActivityType::RUN, 'load' => 118, 'nextDayHrv' => 59.0, 'nextDaySleepScore' => 73, 'nextDayFatigue' => 4],
            ['day' => '2026-03-29', 'activityType' => ActivityType::RIDE, 'load' => 135, 'nextDayHrv' => 69.0, 'nextDaySleepScore' => 83, 'nextDayFatigue' => 2],
            ['day' => '2026-03-31', 'activityType' => ActivityType::RIDE, 'load' => 142, 'nextDayHrv' => 70.0, 'nextDaySleepScore' => 84, 'nextDayFatigue' => 2],
            ['day' => '2026-04-02', 'activityType' => ActivityType::RIDE, 'load' => 138, 'nextDayHrv' => 68.0, 'nextDaySleepScore' => 82, 'nextDayFatigue' => 2],
        ]);

        $runFingerprint = current(array_filter($fingerprints, static fn ($fingerprint): bool => ActivityType::RUN === $fingerprint->getActivityType()));
        $rideFingerprint = current(array_filter($fingerprints, static fn ($fingerprint): bool => ActivityType::RIDE === $fingerprint->getActivityType()));

        self::assertNotFalse($runFingerprint);
        self::assertNotFalse($rideFingerprint);
        self::assertSame(ActivityTypeRecoveryFingerprintProfile::NEEDS_BUFFER, $runFingerprint->getProfile());
        self::assertSame(ActivityTypeRecoveryFingerprintProfile::BOUNCES_BACK, $rideFingerprint->getProfile());
    }
}