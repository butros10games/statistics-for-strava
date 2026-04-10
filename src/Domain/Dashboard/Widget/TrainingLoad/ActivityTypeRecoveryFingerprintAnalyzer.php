<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

use App\Domain\Activity\ActivityType;

final class ActivityTypeRecoveryFingerprintAnalyzer
{
    /**
     * @param list<array{day: string, activityType: ActivityType, load: int, nextDayHrv: ?float, nextDaySleepScore: ?int, nextDayFatigue: ?int}> $activityDaySamples
     *
     * @return list<ActivityTypeRecoveryFingerprint>
     */
    public function analyze(array $activityDaySamples): array
    {
        if (count($activityDaySamples) < 3) {
            return [];
        }

        $overallAverageHrv = $this->averageNumericField($activityDaySamples, 'nextDayHrv');
        $overallAverageSleepScore = $this->averageNumericField($activityDaySamples, 'nextDaySleepScore');
        $overallAverageFatigue = $this->averageNumericField($activityDaySamples, 'nextDayFatigue');

        $samplesPerActivityType = [];
        foreach ($activityDaySamples as $sample) {
            $samplesPerActivityType[$sample['activityType']->value][] = $sample;
        }

        $fingerprints = [];
        foreach ($samplesPerActivityType as $activityTypeValue => $samples) {
            if (count($samples) < 3) {
                continue;
            }

            $averageLoad = $this->averageNumericField($samples, 'load') ?? 0.0;
            $averageHrv = $this->averageNumericField($samples, 'nextDayHrv');
            $averageSleepScore = $this->averageNumericField($samples, 'nextDaySleepScore');
            $averageFatigue = $this->averageNumericField($samples, 'nextDayFatigue');

            $nextDayHrvDelta = null === $overallAverageHrv || null === $averageHrv ? null : round($averageHrv - $overallAverageHrv, 1);
            $nextDaySleepScoreDelta = null === $overallAverageSleepScore || null === $averageSleepScore ? null : round($averageSleepScore - $overallAverageSleepScore, 1);
            $nextDayFatigueDelta = null === $overallAverageFatigue || null === $averageFatigue ? null : round($averageFatigue - $overallAverageFatigue, 1);

            $fingerprints[] = new ActivityTypeRecoveryFingerprint(
                activityType: ActivityType::from($activityTypeValue),
                sampleSize: count($samples),
                averageLoad: round($averageLoad, 1),
                nextDayHrvDelta: $nextDayHrvDelta,
                nextDaySleepScoreDelta: $nextDaySleepScoreDelta,
                nextDayFatigueDelta: $nextDayFatigueDelta,
                profile: $this->determineProfile($nextDayHrvDelta, $nextDaySleepScoreDelta, $nextDayFatigueDelta),
            );
        }

        usort(
            $fingerprints,
            static function (ActivityTypeRecoveryFingerprint $left, ActivityTypeRecoveryFingerprint $right): int {
                if ($left->getSampleSize() !== $right->getSampleSize()) {
                    return $right->getSampleSize() <=> $left->getSampleSize();
                }

                $rightScore = abs($right->getNextDayHrvDelta() ?? 0.0) + abs($right->getNextDaySleepScoreDelta() ?? 0.0) + abs($right->getNextDayFatigueDelta() ?? 0.0);
                $leftScore = abs($left->getNextDayHrvDelta() ?? 0.0) + abs($left->getNextDaySleepScoreDelta() ?? 0.0) + abs($left->getNextDayFatigueDelta() ?? 0.0);

                return $rightScore <=> $leftScore;
            }
        );

        return array_slice($fingerprints, 0, 4);
    }

    private function determineProfile(?float $nextDayHrvDelta, ?float $nextDaySleepScoreDelta, ?float $nextDayFatigueDelta): ActivityTypeRecoveryFingerprintProfile
    {
        if (
            (null !== $nextDayHrvDelta && $nextDayHrvDelta <= -3.0)
            || (null !== $nextDaySleepScoreDelta && $nextDaySleepScoreDelta <= -3.0)
            || (null !== $nextDayFatigueDelta && $nextDayFatigueDelta >= 0.5)
        ) {
            return ActivityTypeRecoveryFingerprintProfile::NEEDS_BUFFER;
        }

        if (
            (null !== $nextDayHrvDelta && $nextDayHrvDelta >= 2.0)
            || (null !== $nextDaySleepScoreDelta && $nextDaySleepScoreDelta >= 2.0)
            || (null !== $nextDayFatigueDelta && $nextDayFatigueDelta <= -0.4)
        ) {
            return ActivityTypeRecoveryFingerprintProfile::BOUNCES_BACK;
        }

        return ActivityTypeRecoveryFingerprintProfile::MIXED_RESPONSE;
    }

    /**
     * @param list<array{day: string, activityType: ActivityType, load: int, nextDayHrv: ?float, nextDaySleepScore: ?int, nextDayFatigue: ?int}> $samples
     */
    private function averageNumericField(array $samples, string $field): ?float
    {
        $values = array_values(array_filter(
            array_map(static fn (array $sample): int|float|null => $sample[$field], $samples),
            static fn (int|float|null $value): bool => null !== $value,
        ));

        if ([] === $values) {
            return null;
        }

        return array_sum($values) / count($values);
    }
}