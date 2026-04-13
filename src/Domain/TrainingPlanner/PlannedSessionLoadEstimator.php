<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityType;
use App\Domain\Athlete\AthleteRepository;
use App\Domain\Performance\PerformanceAnchor\PerformanceAnchorHistory;
use App\Domain\Performance\PerformanceAnchor\PerformanceAnchorType;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final class PlannedSessionLoadEstimator
{
    private const int RECENT_ACTIVITY_SAMPLE_LIMIT = 12;
    private const int EFFORT_MATCH_NEAREST_SAMPLE_COUNT = 3;

    /** @var array<string, ?float> */
    private array $historicalLoadPerHourByActivityType = [];
    /** @var array<string, list<array{effort: float, loadPerHour: float}>> */
    private array $effortLoadPerHourSamplesByKey = [];
    private ?float $globalHistoricalLoadPerHour = null;

    public function __construct(
        private readonly ActivityRepository $activityRepository,
        private readonly AthleteRepository $athleteRepository,
        private readonly PerformanceAnchorHistory $performanceAnchorHistory,
    ) {
    }

    public function estimate(PlannedSession $plannedSession): ?PlannedSessionLoadEstimate
    {
        if (null !== ($estimatedLoad = $this->estimateFromTemplate($plannedSession))) {
            return PlannedSessionLoadEstimate::create(
                plannedSession: $plannedSession,
                estimatedLoad: $estimatedLoad,
                estimationSource: PlannedSessionEstimationSource::TEMPLATE,
            );
        }

        if (PlannedSessionEstimationSource::MANUAL_TARGET_LOAD === $plannedSession->getEstimationSource()
            && null !== $plannedSession->getTargetLoad()) {
            return PlannedSessionLoadEstimate::create(
                plannedSession: $plannedSession,
                estimatedLoad: $plannedSession->getTargetLoad(),
                estimationSource: PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
            );
        }

        if (null !== ($estimatedLoad = $this->estimateFromWorkoutTargets($plannedSession))) {
            return PlannedSessionLoadEstimate::create(
                plannedSession: $plannedSession,
                estimatedLoad: $estimatedLoad,
                estimationSource: PlannedSessionEstimationSource::WORKOUT_TARGETS,
            );
        }

        if (null !== ($estimatedLoad = $this->estimateFromDurationAndIntensity($plannedSession))) {
            return PlannedSessionLoadEstimate::create(
                plannedSession: $plannedSession,
                estimatedLoad: $estimatedLoad,
                estimationSource: PlannedSessionEstimationSource::DURATION_INTENSITY,
            );
        }

        return null;
    }

    public function getHistoricalLoadPerHourForActivityType(ActivityType $activityType): ?float
    {
        if (array_key_exists($activityType->value, $this->historicalLoadPerHourByActivityType)) {
            return $this->historicalLoadPerHourByActivityType[$activityType->value];
        }

        $activities = array_filter(
            iterator_to_array($this->activityRepository->findAll()),
            static fn (Activity $activity): bool => $activity->getSportType()->getActivityType() === $activityType,
        );

        return $this->historicalLoadPerHourByActivityType[$activityType->value] = $this->calculateAverageLoadPerHour($activities);
    }

    public function getGlobalHistoricalLoadPerHour(): ?float
    {
        if (null !== $this->globalHistoricalLoadPerHour) {
            return $this->globalHistoricalLoadPerHour;
        }

        return $this->globalHistoricalLoadPerHour = $this->calculateAverageLoadPerHour(iterator_to_array($this->activityRepository->findAll()));
    }

    /**
     * @return list<array{effort: float, loadPerHour: float}>
     */
    public function getPowerLoadPerHourSamplesForActivityType(ActivityType $activityType): array
    {
        return $this->getEffortLoadPerHourSamples($activityType, 'power');
    }

    /**
     * @return list<array{effort: float, loadPerHour: float}>
     */
    public function getPaceLoadPerHourSamplesForActivityType(ActivityType $activityType): array
    {
        return $this->getEffortLoadPerHourSamples($activityType, 'pace');
    }

    /**
     * @return list<array{setOn: string, ftp: int}>
     */
    public function getFtpHistoryForActivityType(ActivityType $activityType): array
    {
        if (!$activityType->supportsPowerData()) {
            return [];
        }

        $history = [];
        foreach ($this->getPerformanceAnchorsForActivityType($activityType) as $anchor) {
            $history[] = [
                'setOn' => $anchor['setOn'],
                'ftp' => (int) round($anchor['value']),
            ];
        }

        return $history;
    }

    /**
     * @return list<array{setOn: string, value: float, unit: string, source: string, confidence: string, sampleSize: int}>
     */
    public function getPerformanceAnchorsForActivityType(ActivityType $activityType): array
    {
        if (!$activityType->supportsPowerData()) {
            return [];
        }

        return $this->performanceAnchorHistory->exportForAITooling()[PerformanceAnchorType::fromActivityType($activityType)->value] ?? [];
    }

    private function estimateFromTemplate(PlannedSession $plannedSession): ?float
    {
        $templateActivityId = $plannedSession->getTemplateActivityId();
        if (null === $templateActivityId) {
            return null;
        }

        try {
            $templateActivity = $this->activityRepository->find($templateActivityId);
        } catch (\Throwable) {
            return null;
        }

        $templateLoad = $this->estimateActivityLoad($templateActivity);
        if (null === $templateLoad || $templateLoad <= 0) {
            return null;
        }

        $estimatedLoad = $templateLoad;
        $targetDurationInSeconds = $plannedSession->getTargetDurationInSeconds();
        if (null !== $targetDurationInSeconds && $targetDurationInSeconds > 0 && $templateActivity->getMovingTimeInSeconds() > 0) {
            $estimatedLoad *= $targetDurationInSeconds / $templateActivity->getMovingTimeInSeconds();
        }

        return round($estimatedLoad, 1);
    }

    private function estimateFromWorkoutTargets(PlannedSession $plannedSession): ?float
    {
        if (!$plannedSession->hasWorkoutSteps()) {
            return null;
        }

        $estimatedLoad = $this->estimateWorkoutSequenceLoad($plannedSession, $plannedSession->getWorkoutSteps());
        if (null === $estimatedLoad || $estimatedLoad <= 0) {
            return null;
        }

        return round($estimatedLoad, 1);
    }

    private function estimateFromDurationAndIntensity(PlannedSession $plannedSession): ?float
    {
        $targetDurationInSeconds = $plannedSession->getTargetDurationInSeconds();
        $targetIntensity = $plannedSession->getTargetIntensity();
        if (null === $targetDurationInSeconds || $targetDurationInSeconds <= 0 || null === $targetIntensity) {
            return null;
        }

        $historicalLoadPerHour = $this->getHistoricalLoadPerHourForActivityType($plannedSession->getActivityType())
            ?? $this->getGlobalHistoricalLoadPerHour();

        if (null === $historicalLoadPerHour) {
            return null;
        }

        $estimatedLoad = ($targetDurationInSeconds / 3600) * $historicalLoadPerHour * $this->getIntensityMultiplier($targetIntensity);

        return round($estimatedLoad, 1);
    }

    /**
     * @param list<array{itemId: string, parentBlockId: ?string, type: string, label: ?string, repetitions: int, targetType: ?string, conditionType: ?string, durationInSeconds: ?int, distanceInMeters: ?int, targetPace: ?string, targetPower: ?int, targetHeartRate: ?int, recoveryAfterInSeconds: ?int}> $workoutSteps
     */
    private function estimateWorkoutSequenceLoad(PlannedSession $plannedSession, array $workoutSteps, ?string $parentBlockId = null): ?float
    {
        $totalEstimatedLoad = 0.0;

        foreach ($workoutSteps as $workoutStep) {
            if (($workoutStep['parentBlockId'] ?? null) !== $parentBlockId) {
                continue;
            }

            $stepType = PlannedSessionStepType::tryFrom($workoutStep['type']) ?? PlannedSessionStepType::INTERVAL;
            if ($stepType->isContainer()) {
                $childLoad = $this->estimateWorkoutSequenceLoad($plannedSession, $workoutSteps, $workoutStep['itemId']);
                if (null === $childLoad) {
                    return null;
                }

                $totalEstimatedLoad += max(1, $workoutStep['repetitions']) * $childLoad;

                continue;
            }

            $stepLoad = $this->estimateWorkoutStepLoad($plannedSession, $workoutStep);
            if (null === $stepLoad) {
                return null;
            }

            $totalEstimatedLoad += max(1, $workoutStep['repetitions']) * $stepLoad;
        }

        return $totalEstimatedLoad;
    }

    /**
     * @param array{type: string, targetType: ?string, conditionType: ?string, durationInSeconds: ?int, distanceInMeters: ?int, targetPace: ?string, targetPower: ?int, targetHeartRate: ?int} $workoutStep
     */
    private function estimateWorkoutStepLoad(PlannedSession $plannedSession, array $workoutStep): ?float
    {
        $estimatedStepDurationInSeconds = $this->estimateWorkoutStepDurationInSeconds($workoutStep);
        if (null === $estimatedStepDurationInSeconds || $estimatedStepDurationInSeconds <= 0) {
            return null;
        }

        $activityType = $plannedSession->getActivityType();
        if (null !== ($workoutStep['targetPower'] ?? null)
            && $workoutStep['targetPower'] > 0
            && $activityType->supportsPowerData()) {
            $loadPerHour = $this->estimateLoadPerHourFromTargetPower($activityType, $plannedSession->getDay(), $workoutStep['targetPower']);
            if (null !== $loadPerHour) {
                return round(($estimatedStepDurationInSeconds / 3600) * $loadPerHour, 1);
            }
        }

        if (ActivityType::RUN === $activityType && null !== ($workoutStep['targetPace'] ?? null)) {
            $loadPerHour = $this->estimateLoadPerHourFromTargetPace($activityType, $workoutStep['targetPace']);
            if (null !== $loadPerHour) {
                return round(($estimatedStepDurationInSeconds / 3600) * $loadPerHour, 1);
            }
        }

        if (null !== ($workoutStep['targetHeartRate'] ?? null) && $workoutStep['targetHeartRate'] > 0) {
            $heartRateLoad = $this->estimateLoadFromTargetHeartRate(
                targetHeartRate: $workoutStep['targetHeartRate'],
                durationInSeconds: $estimatedStepDurationInSeconds,
                on: $plannedSession->getDay(),
            );
            if (null !== $heartRateLoad) {
                return $heartRateLoad;
            }
        }

        $fallbackLoadPerHour = $this->estimateFallbackWorkoutStepLoadPerHour($plannedSession, $workoutStep);
        if (null === $fallbackLoadPerHour) {
            return null;
        }

        return round(($estimatedStepDurationInSeconds / 3600) * $fallbackLoadPerHour, 1);
    }

    /**
     * @param array{type: string, targetType: ?string, conditionType: ?string, durationInSeconds: ?int, distanceInMeters: ?int, targetPace: ?string, targetPower: ?int, targetHeartRate: ?int} $workoutStep
     */
    private function estimateWorkoutStepDurationInSeconds(array $workoutStep): ?int
    {
        $targetType = PlannedSessionStepTargetType::tryFrom((string) ($workoutStep['targetType'] ?? ''));
        if (PlannedSessionStepTargetType::HEART_RATE === $targetType) {
            return $workoutStep['durationInSeconds'] ?? null;
        }

        if (null !== ($workoutStep['durationInSeconds'] ?? null) && $workoutStep['durationInSeconds'] > 0) {
            return $workoutStep['durationInSeconds'];
        }

        if (null === ($workoutStep['distanceInMeters'] ?? null) || $workoutStep['distanceInMeters'] <= 0) {
            return null;
        }

        $secondsPerMeter = $this->parsePaceSecondsPerMeter($workoutStep['targetPace'] ?? null);
        if (null === $secondsPerMeter) {
            return null;
        }

        return (int) round($secondsPerMeter * $workoutStep['distanceInMeters']);
    }

    private function estimateLoadPerHourFromTargetPower(ActivityType $activityType, \DateTimeImmutable $day, int $targetPower): ?float
    {
        if ($targetPower <= 0) {
            return null;
        }

        if ($activityType->supportsPowerData()) {
            try {
                $thresholdPower = $this->performanceAnchorHistory->find(
                    PerformanceAnchorType::fromActivityType($activityType),
                    SerializableDateTime::fromString($day->format('Y-m-d')),
                )->getValue();
                if ($thresholdPower > 0) {
                    $intensityFactor = $this->clamp($targetPower / $thresholdPower, 0.35, 1.8);

                    return round(($intensityFactor ** 2) * 100, 1);
                }
            } catch (EntityNotFound) {
            }
        }

        return $this->estimateLoadPerHourFromEffortSamples(
            targetEffort: (float) $targetPower,
            samples: $this->getPowerLoadPerHourSamplesForActivityType($activityType),
            higherEffortIsHarder: true,
        );
    }

    private function estimateLoadPerHourFromTargetPace(ActivityType $activityType, ?string $targetPace): ?float
    {
        $secondsPerMeter = $this->parsePaceSecondsPerMeter($targetPace);
        if (null === $secondsPerMeter) {
            return null;
        }

        return $this->estimateLoadPerHourFromEffortSamples(
            targetEffort: $secondsPerMeter * 1000,
            samples: $this->getPaceLoadPerHourSamplesForActivityType($activityType),
            higherEffortIsHarder: false,
        );
    }

    private function estimateLoadFromTargetHeartRate(int $targetHeartRate, int $durationInSeconds, \DateTimeImmutable $on): ?float
    {
        $athlete = $this->athleteRepository->find();
        $restingHeartRate = $athlete->getRestingHeartRateFormula($on);
        $maxHeartRate = $athlete->getMaxHeartRate($on);
        if ($maxHeartRate <= $restingHeartRate) {
            return null;
        }

        $intensity = ($targetHeartRate - $restingHeartRate) / ($maxHeartRate - $restingHeartRate);
        $intensity = max(0.0, min(1.5, $intensity));
        $bannisterKFactor = $athlete->isMale() ? 1.92 : 1.67;

        return round(($durationInSeconds / 60) * $intensity * exp($bannisterKFactor * $intensity), 1);
    }

    /**
     * @param array{type: string, targetType: ?string, conditionType: ?string, durationInSeconds: ?int, distanceInMeters: ?int, targetPace: ?string, targetPower: ?int, targetHeartRate: ?int} $workoutStep
     */
    private function estimateFallbackWorkoutStepLoadPerHour(PlannedSession $plannedSession, array $workoutStep): ?float
    {
        $historicalLoadPerHour = $this->getHistoricalLoadPerHourForActivityType($plannedSession->getActivityType())
            ?? $this->getGlobalHistoricalLoadPerHour();
        if (null === $historicalLoadPerHour) {
            return null;
        }

        $sessionIntensityMultiplier = null === $plannedSession->getTargetIntensity()
            ? null
            : $this->getIntensityMultiplier($plannedSession->getTargetIntensity());
        $stepType = PlannedSessionStepType::tryFrom($workoutStep['type'] ?? '') ?? PlannedSessionStepType::INTERVAL;
        $defaultMultiplier = match ($stepType) {
            PlannedSessionStepType::RECOVERY => 0.65,
            PlannedSessionStepType::WARMUP, PlannedSessionStepType::COOLDOWN => 0.8,
            PlannedSessionStepType::INTERVAL => 1.15,
            default => 1.0,
        };

        $multiplier = match (true) {
            null === $sessionIntensityMultiplier => $defaultMultiplier,
            PlannedSessionStepType::RECOVERY === $stepType => min($sessionIntensityMultiplier, $defaultMultiplier),
            PlannedSessionStepType::WARMUP === $stepType, PlannedSessionStepType::COOLDOWN === $stepType => min($sessionIntensityMultiplier, $defaultMultiplier),
            PlannedSessionStepType::INTERVAL === $stepType => max($sessionIntensityMultiplier, $defaultMultiplier),
            default => $sessionIntensityMultiplier,
        };

        return round($historicalLoadPerHour * $multiplier, 1);
    }

    /**
     * @param list<array{effort: float, loadPerHour: float}> $samples
     */
    private function estimateLoadPerHourFromEffortSamples(float $targetEffort, array $samples, bool $higherEffortIsHarder): ?float
    {
        if ($targetEffort <= 0 || [] === $samples) {
            return null;
        }

        usort(
            $samples,
            fn (array $left, array $right): int => $this->compareEffortDistance($targetEffort, $left['effort'], $right['effort']),
        );

        $nearestSamples = array_slice($samples, 0, self::EFFORT_MATCH_NEAREST_SAMPLE_COUNT);
        $weightedLoadPerHour = 0.0;
        $weightedEffort = 0.0;
        $totalWeight = 0.0;

        foreach ($nearestSamples as $sample) {
            $distance = $this->calculateRelativeEffortDistance($targetEffort, $sample['effort']);
            $weight = 1 / max(0.05, $distance + 0.05);

            $weightedLoadPerHour += $sample['loadPerHour'] * $weight;
            $weightedEffort += $sample['effort'] * $weight;
            $totalWeight += $weight;
        }

        if ($totalWeight <= 0.0) {
            return null;
        }

        $referenceLoadPerHour = $weightedLoadPerHour / $totalWeight;
        $referenceEffort = $weightedEffort / $totalWeight;
        $effortRatio = $higherEffortIsHarder
            ? $targetEffort / max(1.0, $referenceEffort)
            : $referenceEffort / max(1.0, $targetEffort);

        return round($referenceLoadPerHour * $this->clamp($effortRatio, 0.75, 1.35), 1);
    }

    private function compareEffortDistance(float $targetEffort, float $leftEffort, float $rightEffort): int
    {
        return $this->calculateRelativeEffortDistance($targetEffort, $leftEffort) <=> $this->calculateRelativeEffortDistance($targetEffort, $rightEffort);
    }

    private function calculateRelativeEffortDistance(float $targetEffort, float $sampleEffort): float
    {
        if ($targetEffort <= 0 || $sampleEffort <= 0) {
            return INF;
        }

        return abs(log($targetEffort / $sampleEffort));
    }

    /**
     * @return list<array{effort: float, loadPerHour: float}>
     */
    private function getEffortLoadPerHourSamples(ActivityType $activityType, string $metric): array
    {
        $cacheKey = sprintf('%s.%s', $activityType->value, $metric);
        if (array_key_exists($cacheKey, $this->effortLoadPerHourSamplesByKey)) {
            return $this->effortLoadPerHourSamplesByKey[$cacheKey];
        }

        $samples = [];
        foreach ($this->getRecentActivitiesForType($activityType) as $activity) {
            if ($activity->getMovingTimeInSeconds() <= 0) {
                continue;
            }

            $load = $this->estimateActivityLoad($activity);
            if (null === $load || $load <= 0) {
                continue;
            }

            $effort = match ($metric) {
                'power' => $activity->getNormalizedPower() ?? $activity->getAveragePower(),
                'pace' => $activity->getPaceInSecPerKm()->toFloat(),
                default => null,
            };
            if (!is_numeric($effort) || $effort <= 0) {
                continue;
            }

            $samples[] = [
                'effort' => (float) $effort,
                'loadPerHour' => round($load / ($activity->getMovingTimeInSeconds() / 3600), 1),
            ];

            if (count($samples) >= self::RECENT_ACTIVITY_SAMPLE_LIMIT) {
                break;
            }
        }

        return $this->effortLoadPerHourSamplesByKey[$cacheKey] = $samples;
    }

    /**
     * @return list<Activity>
     */
    private function getRecentActivitiesForType(ActivityType $activityType): array
    {
        $activities = array_values(array_filter(
            iterator_to_array($this->activityRepository->findAll()),
            static fn (Activity $activity): bool => $activity->getSportType()->getActivityType() === $activityType,
        ));

        usort(
            $activities,
            static fn (Activity $left, Activity $right): int => $right->getStartDate() <=> $left->getStartDate(),
        );

        return $activities;
    }

    private function parsePaceSecondsPerMeter(?string $targetPace): ?float
    {
        if (null === $targetPace) {
            return null;
        }

        if (!preg_match('/^\s*(\d+):(\d{2})(?:\s*\/\s*(km|mi))?\s*$/i', $targetPace, $matches)) {
            return null;
        }

        $seconds = ((int) $matches[1] * 60) + (int) $matches[2];
        $unit = strtolower($matches[3] ?? 'km');
        $meters = 'mi' === $unit ? 1609.344 : 1000.0;

        return $seconds / $meters;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    /**
     * @param array<int, Activity> $activities
     */
    private function calculateAverageLoadPerHour(array $activities): ?float
    {
        usort(
            $activities,
            static fn (Activity $left, Activity $right): int => $right->getStartDate() <=> $left->getStartDate(),
        );

        $loadPerHourSamples = [];
        foreach ($activities as $activity) {
            if ($activity->getMovingTimeInSeconds() <= 0) {
                continue;
            }

            $load = $this->estimateActivityLoad($activity);
            if (null === $load || $load <= 0) {
                continue;
            }

            $loadPerHourSamples[] = $load / ($activity->getMovingTimeInSeconds() / 3600);
            if (count($loadPerHourSamples) >= self::RECENT_ACTIVITY_SAMPLE_LIMIT) {
                break;
            }
        }

        if ([] === $loadPerHourSamples) {
            return null;
        }

        return array_sum($loadPerHourSamples) / count($loadPerHourSamples);
    }

    public function estimateActivityLoad(Activity $activity): ?float
    {
        $averageHeartRate = $activity->getAverageHeartRate();
        if (null === $averageHeartRate || $activity->getMovingTimeInSeconds() <= 0) {
            return null;
        }

        $athlete = $this->athleteRepository->find();
        $restingHeartRate = $athlete->getRestingHeartRateFormula($activity->getStartDate());
        $maxHeartRate = $athlete->getMaxHeartRate($activity->getStartDate());
        if ($maxHeartRate <= $restingHeartRate) {
            return null;
        }

        $intensity = ($averageHeartRate - $restingHeartRate) / ($maxHeartRate - $restingHeartRate);
        $intensity = max(0.0, min(1.5, $intensity));
        $bannisterKFactor = $athlete->isMale() ? 1.92 : 1.67;

        return round(($activity->getMovingTimeInSeconds() / 60) * $intensity * exp($bannisterKFactor * $intensity), 1);
    }

    public function getIntensityMultiplier(PlannedSessionIntensity $targetIntensity): float
    {
        return match ($targetIntensity) {
            PlannedSessionIntensity::EASY => 0.8,
            PlannedSessionIntensity::MODERATE => 1.0,
            PlannedSessionIntensity::HARD => 1.2,
            PlannedSessionIntensity::RACE => 1.35,
        };
    }
}
