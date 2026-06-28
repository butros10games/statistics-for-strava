<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityType;
use App\Domain\Athlete\AthleteRepository;
use App\Domain\Performance\PerformanceAnchor\PerformanceAnchorConfidence;
use App\Domain\Performance\PerformanceAnchor\PerformanceAnchorHistory;
use App\Domain\Performance\PerformanceAnchor\PerformanceAnchorType;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

/**
 * @phpstan-type LoadEstimateData array{estimatedLoad: float, confidenceScore: float, methodDetails: string, sampleCount: ?int, lowerEstimatedLoad: float, upperEstimatedLoad: float}
 * @phpstan-type LoadPerHourEstimateData array{loadPerHour: float, confidenceScore: float, methodDetails: string, sampleCount: ?int, uncertaintyRatio: float}
 * @phpstan-type LoadPerHourSummary array{loadPerHour: float, sampleCount: int}
 */
final class PlannedSessionLoadEstimator
{
    private const int RECENT_ACTIVITY_SAMPLE_LIMIT = 12;
    private const int EFFORT_MATCH_NEAREST_SAMPLE_COUNT = 3;

    private ?TrainingPlannerActivityIndex $activityIndex = null;
    /** @var array<string, ?float> */
    private array $historicalLoadPerHourByActivityType = [];
    /** @var array<string, int> */
    private array $historicalLoadPerHourSampleCountByActivityType = [];
    /** @var array<string, list<array{effort: float, loadPerHour: float}>> */
    private array $effortLoadPerHourSamplesByKey = [];
    private ?float $globalHistoricalLoadPerHour = null;
    private ?int $globalHistoricalLoadPerHourSampleCount = null;

    public function __construct(
        private readonly ActivityRepository $activityRepository,
        private readonly AthleteRepository $athleteRepository,
        private readonly PerformanceAnchorHistory $performanceAnchorHistory,
    ) {
    }

    public function estimate(PlannedSession $plannedSession): ?PlannedSessionLoadEstimate
    {
        if (null !== ($estimate = $this->estimateFromTemplate($plannedSession))) {
            return $this->createLoadEstimate(
                plannedSession: $plannedSession,
                estimationSource: PlannedSessionEstimationSource::TEMPLATE,
                estimate: $estimate,
            );
        }

        if (PlannedSessionEstimationSource::MANUAL_TARGET_LOAD === $plannedSession->getEstimationSource()
            && null !== $plannedSession->getTargetLoad()) {
            $estimatedLoad = round($plannedSession->getTargetLoad(), 1);

            return $this->createLoadEstimate(
                plannedSession: $plannedSession,
                estimationSource: PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
                estimate: [
                    'estimatedLoad' => $estimatedLoad,
                    'confidenceScore' => 1.0,
                    'methodDetails' => 'Manual target load supplied on the planned session.',
                    'sampleCount' => null,
                    'lowerEstimatedLoad' => $estimatedLoad,
                    'upperEstimatedLoad' => $estimatedLoad,
                ],
            );
        }

        if (null !== ($estimate = $this->estimateFromWorkoutTargets($plannedSession))) {
            return $this->createLoadEstimate(
                plannedSession: $plannedSession,
                estimationSource: PlannedSessionEstimationSource::WORKOUT_TARGETS,
                estimate: $estimate,
            );
        }

        if (null !== ($estimate = $this->estimateFromDurationAndIntensity($plannedSession))) {
            return $this->createLoadEstimate(
                plannedSession: $plannedSession,
                estimationSource: PlannedSessionEstimationSource::DURATION_INTENSITY,
                estimate: $estimate,
            );
        }

        return null;
    }

    public function getHistoricalLoadPerHourForActivityType(ActivityType $activityType): ?float
    {
        if (array_key_exists($activityType->value, $this->historicalLoadPerHourByActivityType)) {
            return $this->historicalLoadPerHourByActivityType[$activityType->value];
        }

        $activities = $this->activityIndex()->byActivityType($activityType);
        $summary = $this->calculateAverageLoadPerHourSummary($activities);
        $this->historicalLoadPerHourSampleCountByActivityType[$activityType->value] = $summary['sampleCount'] ?? 0;

        return $this->historicalLoadPerHourByActivityType[$activityType->value] = $summary['loadPerHour'] ?? null;
    }

    public function getGlobalHistoricalLoadPerHour(): ?float
    {
        if (null !== $this->globalHistoricalLoadPerHour) {
            return $this->globalHistoricalLoadPerHour;
        }

        $summary = $this->calculateAverageLoadPerHourSummary($this->activityIndex()->all());
        $this->globalHistoricalLoadPerHourSampleCount = $summary['sampleCount'] ?? 0;

        return $this->globalHistoricalLoadPerHour = $summary['loadPerHour'] ?? null;
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

    /**
     * @return LoadEstimateData|null
     */
    private function estimateFromTemplate(PlannedSession $plannedSession): ?array
    {
        $templateActivityId = $plannedSession->getTemplateActivityId();
        if (!$templateActivityId instanceof \App\Domain\Activity\ActivityId) {
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
        $methodDetails = 'Template activity load estimated from the matched activity heart-rate load.';
        $confidenceScore = 0.9;
        $uncertaintyRatio = 0.1;
        if (null !== $targetDurationInSeconds && $targetDurationInSeconds > 0 && $templateActivity->getMovingTimeInSeconds() > 0) {
            $estimatedLoad *= $targetDurationInSeconds / $templateActivity->getMovingTimeInSeconds();
            $methodDetails = 'Template activity load scaled to the planned target duration.';
            $confidenceScore = 0.84;
            $uncertaintyRatio = 0.14;
        }

        return $this->createLoadEstimateData(
            estimatedLoad: $estimatedLoad,
            confidenceScore: $confidenceScore,
            methodDetails: $methodDetails,
            sampleCount: 1,
            uncertaintyRatio: $uncertaintyRatio,
        );
    }

    /**
     * @return LoadEstimateData|null
     */
    private function estimateFromWorkoutTargets(PlannedSession $plannedSession): ?array
    {
        if (!$plannedSession->hasWorkoutSteps()) {
            return null;
        }

        $estimate = $this->estimateWorkoutSequenceLoad($plannedSession, $plannedSession->getWorkoutSteps());
        if (null === $estimate || $estimate['estimatedLoad'] <= 0) {
            return null;
        }

        return $estimate;
    }

    /**
     * @return LoadEstimateData|null
     */
    private function estimateFromDurationAndIntensity(PlannedSession $plannedSession): ?array
    {
        $targetDurationInSeconds = $plannedSession->getTargetDurationInSeconds();
        $targetIntensity = $plannedSession->getTargetIntensity();
        if (null === $targetDurationInSeconds || $targetDurationInSeconds <= 0 || !$targetIntensity instanceof PlannedSessionIntensity) {
            return null;
        }

        $historicalLoadPerHour = $this->getHistoricalLoadPerHourForActivityType($plannedSession->getActivityType());
        $sampleCount = $this->getHistoricalLoadPerHourSampleCountForActivityType($plannedSession->getActivityType());
        $methodDetails = 'Activity-specific historical load per hour adjusted by planned duration and intensity.';
        $confidenceScore = $this->confidenceScoreFromSampleCount($sampleCount, 0.47, 0.82);
        $uncertaintyRatio = $this->uncertaintyRatioFromSampleCount($sampleCount, 0.18, 0.36);

        if (null === $historicalLoadPerHour) {
            $historicalLoadPerHour = $this->getGlobalHistoricalLoadPerHour();
            $sampleCount = $this->getGlobalHistoricalLoadPerHourSampleCount();
            $methodDetails = 'Global historical load per hour adjusted by planned duration and intensity.';
            $confidenceScore = $this->confidenceScoreFromSampleCount($sampleCount, 0.38, 0.68);
            $uncertaintyRatio = $this->uncertaintyRatioFromSampleCount($sampleCount, 0.25, 0.42);
        }

        if (null === $historicalLoadPerHour) {
            return null;
        }

        $estimatedLoad = ($targetDurationInSeconds / 3600) * $historicalLoadPerHour * $this->getIntensityMultiplier($targetIntensity);

        return $this->createLoadEstimateData(
            estimatedLoad: $estimatedLoad,
            confidenceScore: $confidenceScore,
            methodDetails: $methodDetails,
            sampleCount: $sampleCount,
            uncertaintyRatio: $uncertaintyRatio,
        );
    }

    /**
     * @param list<array{itemId: string, parentBlockId: ?string, type: string, label: ?string, repetitions: int, targetType: ?string, conditionType: ?string, durationInSeconds: ?int, distanceInMeters: ?int, targetPace: ?string, targetPower: ?int, targetHeartRate: ?int, recoveryAfterInSeconds: ?int}> $workoutSteps
     *
     * @return LoadEstimateData|null
     */
    private function estimateWorkoutSequenceLoad(PlannedSession $plannedSession, array $workoutSteps, ?string $parentBlockId = null): ?array
    {
        $totalEstimatedLoad = 0.0;
        $lowerEstimatedLoad = 0.0;
        $upperEstimatedLoad = 0.0;
        $weightedConfidenceScore = 0.0;
        $sampleCount = null;
        $methodDetails = [];

        foreach ($workoutSteps as $workoutStep) {
            if (($workoutStep['parentBlockId'] ?? null) !== $parentBlockId) {
                continue;
            }

            $stepType = PlannedSessionStepType::tryFrom($workoutStep['type']) ?? PlannedSessionStepType::INTERVAL;
            if ($stepType->isContainer()) {
                $childEstimate = $this->estimateWorkoutSequenceLoad($plannedSession, $workoutSteps, $workoutStep['itemId']);
                if (null === $childEstimate) {
                    return null;
                }

                $this->addRepeatedStepEstimate(
                    aggregateEstimatedLoad: $totalEstimatedLoad,
                    aggregateLowerEstimatedLoad: $lowerEstimatedLoad,
                    aggregateUpperEstimatedLoad: $upperEstimatedLoad,
                    aggregateWeightedConfidenceScore: $weightedConfidenceScore,
                    aggregateSampleCount: $sampleCount,
                    aggregateMethodDetails: $methodDetails,
                    estimate: $childEstimate,
                    repetitions: max(1, $workoutStep['repetitions']),
                );

                continue;
            }

            $stepEstimate = $this->estimateWorkoutStepLoad($plannedSession, $workoutStep);
            if (null === $stepEstimate) {
                return null;
            }

            $this->addRepeatedStepEstimate(
                aggregateEstimatedLoad: $totalEstimatedLoad,
                aggregateLowerEstimatedLoad: $lowerEstimatedLoad,
                aggregateUpperEstimatedLoad: $upperEstimatedLoad,
                aggregateWeightedConfidenceScore: $weightedConfidenceScore,
                aggregateSampleCount: $sampleCount,
                aggregateMethodDetails: $methodDetails,
                estimate: $stepEstimate,
                repetitions: max(1, $workoutStep['repetitions']),
            );
        }

        if ($totalEstimatedLoad <= 0.0) {
            return null;
        }

        return [
            'estimatedLoad' => round($totalEstimatedLoad, 1),
            'confidenceScore' => round($weightedConfidenceScore / $totalEstimatedLoad, 2),
            'methodDetails' => $this->summarizeWorkoutMethodDetails($methodDetails),
            'sampleCount' => $sampleCount,
            'lowerEstimatedLoad' => round($lowerEstimatedLoad, 1),
            'upperEstimatedLoad' => round($upperEstimatedLoad, 1),
        ];
    }

    /**
     * @param array{type: string, targetType: ?string, conditionType: ?string, durationInSeconds: ?int, distanceInMeters: ?int, targetPace: ?string, targetPower: ?int, targetHeartRate: ?int} $workoutStep
     *
     * @return LoadEstimateData|null
     */
    private function estimateWorkoutStepLoad(PlannedSession $plannedSession, array $workoutStep): ?array
    {
        $estimatedStepDurationInSeconds = $this->estimateWorkoutStepDurationInSeconds($workoutStep);
        if (null === $estimatedStepDurationInSeconds || $estimatedStepDurationInSeconds <= 0) {
            return null;
        }

        $activityType = $plannedSession->getActivityType();
        if (null !== ($workoutStep['targetPower'] ?? null)
            && $workoutStep['targetPower'] > 0
            && $activityType->supportsPowerData()) {
            $loadPerHourEstimate = $this->estimateLoadPerHourFromTargetPower($activityType, $plannedSession->getDay(), $workoutStep['targetPower']);
            if (null !== $loadPerHourEstimate) {
                return $this->createLoadEstimateDataFromLoadPerHour(
                    loadPerHourEstimate: $loadPerHourEstimate,
                    durationInSeconds: $estimatedStepDurationInSeconds,
                );
            }
        }

        if (ActivityType::RUN === $activityType && null !== ($workoutStep['targetPace'] ?? null)) {
            $loadPerHourEstimate = $this->estimateLoadPerHourFromTargetPace($activityType, $workoutStep['targetPace']);
            if (null !== $loadPerHourEstimate) {
                return $this->createLoadEstimateDataFromLoadPerHour(
                    loadPerHourEstimate: $loadPerHourEstimate,
                    durationInSeconds: $estimatedStepDurationInSeconds,
                );
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

        $fallbackLoadPerHourEstimate = $this->estimateFallbackWorkoutStepLoadPerHour($plannedSession, $workoutStep);
        if (null === $fallbackLoadPerHourEstimate) {
            return null;
        }

        return $this->createLoadEstimateDataFromLoadPerHour(
            loadPerHourEstimate: $fallbackLoadPerHourEstimate,
            durationInSeconds: $estimatedStepDurationInSeconds,
        );
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

    /**
     * @return LoadPerHourEstimateData|null
     */
    private function estimateLoadPerHourFromTargetPower(ActivityType $activityType, \DateTimeImmutable $day, int $targetPower): ?array
    {
        if ($targetPower <= 0) {
            return null;
        }

        if ($activityType->supportsPowerData()) {
            try {
                $thresholdPowerAnchor = $this->performanceAnchorHistory->find(
                    PerformanceAnchorType::fromActivityType($activityType),
                    SerializableDateTime::fromString($day->format('Y-m-d')),
                );
                $thresholdPower = $thresholdPowerAnchor->getValue();
                if ($thresholdPower > 0) {
                    $intensityFactor = $this->clamp($targetPower / $thresholdPower, 0.35, 1.8);

                    return [
                        'loadPerHour' => round(($intensityFactor ** 2) * 100, 1),
                        'confidenceScore' => $this->confidenceScoreFromAnchorConfidence($thresholdPowerAnchor->getConfidence(), $thresholdPowerAnchor->getSampleSize()),
                        'methodDetails' => sprintf(
                            'Power target scaled from %s threshold power anchor.',
                            ActivityType::RIDE === $activityType ? 'cycling' : 'running',
                        ),
                        'sampleCount' => $thresholdPowerAnchor->getSampleSize(),
                        'uncertaintyRatio' => $this->uncertaintyRatioFromAnchorConfidence($thresholdPowerAnchor->getConfidence()),
                    ];
                }
            } catch (EntityNotFound) {
            }
        }

        return $this->estimateLoadPerHourFromEffortSamples(
            targetEffort: (float) $targetPower,
            samples: $this->getPowerLoadPerHourSamplesForActivityType($activityType),
            higherEffortIsHarder: true,
            methodDetails: 'Power target matched against recent activity power/load samples.',
        );
    }

    /**
     * @return LoadPerHourEstimateData|null
     */
    private function estimateLoadPerHourFromTargetPace(ActivityType $activityType, ?string $targetPace): ?array
    {
        $secondsPerMeter = $this->parsePaceSecondsPerMeter($targetPace);
        if (null === $secondsPerMeter) {
            return null;
        }

        return $this->estimateLoadPerHourFromEffortSamples(
            targetEffort: $secondsPerMeter * 1000,
            samples: $this->getPaceLoadPerHourSamplesForActivityType($activityType),
            higherEffortIsHarder: false,
            methodDetails: 'Pace target matched against recent run pace/load samples.',
        );
    }

    /**
     * @return LoadEstimateData|null
     */
    private function estimateLoadFromTargetHeartRate(int $targetHeartRate, int $durationInSeconds, \DateTimeImmutable $on): ?array
    {
        $athlete = $this->athleteRepository->find();
        $measurementDay = SerializableDateTime::fromDateTimeImmutable($on);
        $restingHeartRate = $athlete->getRestingHeartRateFormula($measurementDay);
        $maxHeartRate = $athlete->getMaxHeartRate($measurementDay);
        if ($maxHeartRate <= $restingHeartRate) {
            return null;
        }

        $intensity = ($targetHeartRate - $restingHeartRate) / ($maxHeartRate - $restingHeartRate);
        $intensity = max(0.0, min(1.5, $intensity));
        $bannisterKFactor = $athlete->isMale() ? 1.92 : 1.67;

        return $this->createLoadEstimateData(
            estimatedLoad: ($durationInSeconds / 60) * $intensity * exp($bannisterKFactor * $intensity),
            confidenceScore: 0.72,
            methodDetails: 'Heart-rate target estimated with athlete heart-rate reserve and Bannister TRIMP formula.',
            sampleCount: null,
            uncertaintyRatio: 0.22,
        );
    }

    /**
     * @param array{type: string, targetType: ?string, conditionType: ?string, durationInSeconds: ?int, distanceInMeters: ?int, targetPace: ?string, targetPower: ?int, targetHeartRate: ?int} $workoutStep
     *
     * @return LoadPerHourEstimateData|null
     */
    private function estimateFallbackWorkoutStepLoadPerHour(PlannedSession $plannedSession, array $workoutStep): ?array
    {
        $historicalLoadPerHour = $this->getHistoricalLoadPerHourForActivityType($plannedSession->getActivityType());
        $sampleCount = $this->getHistoricalLoadPerHourSampleCountForActivityType($plannedSession->getActivityType());
        $methodDetails = 'Workout step fallback from activity-specific historical load per hour.';
        $confidenceScore = $this->confidenceScoreFromSampleCount($sampleCount, 0.42, 0.72);
        $uncertaintyRatio = $this->uncertaintyRatioFromSampleCount($sampleCount, 0.24, 0.42);

        if (null === $historicalLoadPerHour) {
            $historicalLoadPerHour = $this->getGlobalHistoricalLoadPerHour();
            $sampleCount = $this->getGlobalHistoricalLoadPerHourSampleCount();
            $methodDetails = 'Workout step fallback from global historical load per hour.';
            $confidenceScore = $this->confidenceScoreFromSampleCount($sampleCount, 0.34, 0.62);
            $uncertaintyRatio = $this->uncertaintyRatioFromSampleCount($sampleCount, 0.28, 0.46);
        }

        if (null === $historicalLoadPerHour) {
            return null;
        }

        $sessionIntensityMultiplier = $plannedSession->getTargetIntensity() instanceof PlannedSessionIntensity
            ? $this->getIntensityMultiplier($plannedSession->getTargetIntensity())
            : null;
        $stepType = PlannedSessionStepType::tryFrom($workoutStep['type']) ?? PlannedSessionStepType::INTERVAL;
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

        return [
            'loadPerHour' => round($historicalLoadPerHour * $multiplier, 1),
            'confidenceScore' => $confidenceScore,
            'methodDetails' => $methodDetails,
            'sampleCount' => $sampleCount,
            'uncertaintyRatio' => $uncertaintyRatio,
        ];
    }

    /**
     * @param list<array{effort: float, loadPerHour: float}> $samples
     *
     * @return LoadPerHourEstimateData|null
     */
    private function estimateLoadPerHourFromEffortSamples(float $targetEffort, array $samples, bool $higherEffortIsHarder, string $methodDetails): ?array
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
        $sampleCount = count($nearestSamples);

        return [
            'loadPerHour' => round($referenceLoadPerHour * $this->clamp($effortRatio, 0.75, 1.35), 1),
            'confidenceScore' => $this->confidenceScoreFromSampleCount($sampleCount, 0.46, 0.76),
            'methodDetails' => sprintf('%s Nearest samples used: %d.', $methodDetails, $sampleCount),
            'sampleCount' => $sampleCount,
            'uncertaintyRatio' => $this->uncertaintyRatioFromSampleCount($sampleCount, 0.2, 0.38),
        ];
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
            if (null === $load) {
                continue;
            }
            if ($load <= 0) {
                continue;
            }

            $effort = match ($metric) {
                'power' => $activity->getNormalizedPower() ?? $activity->getAveragePower(),
                'pace' => $activity->getPaceInSecPerKm()->toFloat(),
                default => null,
            };
            if (!is_numeric($effort)) {
                continue;
            }
            if ($effort <= 0) {
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
        $activities = $this->activityIndex()->byActivityType($activityType);

        usort(
            $activities,
            static fn (Activity $left, Activity $right): int => $right->getStartDate() <=> $left->getStartDate(),
        );

        return $activities;
    }

    private function activityIndex(): TrainingPlannerActivityIndex
    {
        return $this->activityIndex ??= TrainingPlannerActivityIndex::fromActivities($this->activityRepository->findAll());
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
     * @param LoadEstimateData $estimate
     */
    private function createLoadEstimate(
        PlannedSession $plannedSession,
        PlannedSessionEstimationSource $estimationSource,
        array $estimate,
    ): PlannedSessionLoadEstimate {
        return PlannedSessionLoadEstimate::create(
            plannedSession: $plannedSession,
            estimatedLoad: $estimate['estimatedLoad'],
            estimationSource: $estimationSource,
            confidenceLabel: $this->confidenceLabelFromScore($estimate['confidenceScore']),
            confidenceScore: $estimate['confidenceScore'],
            methodDetails: $estimate['methodDetails'],
            sampleCount: $estimate['sampleCount'],
            lowerEstimatedLoad: $estimate['lowerEstimatedLoad'],
            upperEstimatedLoad: $estimate['upperEstimatedLoad'],
        );
    }

    /**
     * @return LoadEstimateData
     */
    private function createLoadEstimateData(
        float $estimatedLoad,
        float $confidenceScore,
        string $methodDetails,
        ?int $sampleCount,
        float $uncertaintyRatio,
    ): array {
        $estimatedLoad = round(max(0.0, $estimatedLoad), 1);
        $uncertaintyRatio = $this->clamp($uncertaintyRatio, 0.0, 0.75);

        return [
            'estimatedLoad' => $estimatedLoad,
            'confidenceScore' => round($this->clamp($confidenceScore, 0.0, 1.0), 2),
            'methodDetails' => $methodDetails,
            'sampleCount' => $sampleCount,
            'lowerEstimatedLoad' => round(max(0.0, $estimatedLoad * (1 - $uncertaintyRatio)), 1),
            'upperEstimatedLoad' => round($estimatedLoad * (1 + $uncertaintyRatio), 1),
        ];
    }

    /**
     * @param LoadPerHourEstimateData $loadPerHourEstimate
     *
     * @return LoadEstimateData
     */
    private function createLoadEstimateDataFromLoadPerHour(array $loadPerHourEstimate, int $durationInSeconds): array
    {
        return $this->createLoadEstimateData(
            estimatedLoad: ($durationInSeconds / 3600) * $loadPerHourEstimate['loadPerHour'],
            confidenceScore: $loadPerHourEstimate['confidenceScore'],
            methodDetails: $loadPerHourEstimate['methodDetails'],
            sampleCount: $loadPerHourEstimate['sampleCount'],
            uncertaintyRatio: $loadPerHourEstimate['uncertaintyRatio'],
        );
    }

    /**
     * @param LoadEstimateData    $estimate
     * @param array<string, true> $aggregateMethodDetails
     */
    private function addRepeatedStepEstimate(
        float &$aggregateEstimatedLoad,
        float &$aggregateLowerEstimatedLoad,
        float &$aggregateUpperEstimatedLoad,
        float &$aggregateWeightedConfidenceScore,
        ?int &$aggregateSampleCount,
        array &$aggregateMethodDetails,
        array $estimate,
        int $repetitions,
    ): void {
        $repetitions = max(1, $repetitions);
        $estimatedLoad = $estimate['estimatedLoad'] * $repetitions;

        $aggregateEstimatedLoad += $estimatedLoad;
        $aggregateLowerEstimatedLoad += $estimate['lowerEstimatedLoad'] * $repetitions;
        $aggregateUpperEstimatedLoad += $estimate['upperEstimatedLoad'] * $repetitions;
        $aggregateWeightedConfidenceScore += $estimate['confidenceScore'] * $estimatedLoad;
        $aggregateMethodDetails[$estimate['methodDetails']] = true;

        if (null !== $estimate['sampleCount']) {
            $aggregateSampleCount = max($aggregateSampleCount ?? 0, $estimate['sampleCount']);
        }
    }

    /**
     * @param array<string, true> $methodDetails
     */
    private function summarizeWorkoutMethodDetails(array $methodDetails): string
    {
        $details = array_keys($methodDetails);
        if ([] === $details) {
            return 'Workout target estimate from planned workout steps.';
        }
        if (1 === count($details)) {
            return $details[0];
        }

        return sprintf('Mixed workout target methods: %s', implode(' ', array_slice($details, 0, 3)));
    }

    private function confidenceLabelFromScore(float $score): string
    {
        return match (true) {
            $score >= 0.8 => 'High confidence',
            $score >= 0.5 => 'Medium confidence',
            default => 'Low confidence',
        };
    }

    private function confidenceScoreFromSampleCount(int $sampleCount, float $minimumScore, float $maximumScore): float
    {
        $sampleCoverage = $this->clamp($sampleCount / self::RECENT_ACTIVITY_SAMPLE_LIMIT, 0.0, 1.0);

        return round($minimumScore + (($maximumScore - $minimumScore) * $sampleCoverage), 2);
    }

    private function uncertaintyRatioFromSampleCount(int $sampleCount, float $bestRatio, float $worstRatio): float
    {
        $sampleCoverage = $this->clamp($sampleCount / self::RECENT_ACTIVITY_SAMPLE_LIMIT, 0.0, 1.0);

        return round($bestRatio + ((1.0 - $sampleCoverage) * ($worstRatio - $bestRatio)), 2);
    }

    private function confidenceScoreFromAnchorConfidence(PerformanceAnchorConfidence $confidence, int $sampleSize): float
    {
        $score = match ($confidence) {
            PerformanceAnchorConfidence::HIGH => 0.88,
            PerformanceAnchorConfidence::MEDIUM => 0.74,
            PerformanceAnchorConfidence::LOW => 0.58,
        };

        return round($this->clamp($score + min(0.06, max(0, $sampleSize - 1) * 0.01), 0.0, 0.94), 2);
    }

    private function uncertaintyRatioFromAnchorConfidence(PerformanceAnchorConfidence $confidence): float
    {
        return match ($confidence) {
            PerformanceAnchorConfidence::HIGH => 0.12,
            PerformanceAnchorConfidence::MEDIUM => 0.18,
            PerformanceAnchorConfidence::LOW => 0.28,
        };
    }

    private function getHistoricalLoadPerHourSampleCountForActivityType(ActivityType $activityType): int
    {
        if (!array_key_exists($activityType->value, $this->historicalLoadPerHourSampleCountByActivityType)) {
            $this->getHistoricalLoadPerHourForActivityType($activityType);
        }

        return $this->historicalLoadPerHourSampleCountByActivityType[$activityType->value] ?? 0;
    }

    private function getGlobalHistoricalLoadPerHourSampleCount(): int
    {
        if (null === $this->globalHistoricalLoadPerHourSampleCount) {
            $this->getGlobalHistoricalLoadPerHour();
        }

        return $this->globalHistoricalLoadPerHourSampleCount ?? 0;
    }

    /**
     * @param array<int, Activity> $activities
     *
     * @return LoadPerHourSummary|null
     */
    private function calculateAverageLoadPerHourSummary(array $activities): ?array
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
            if (null === $load) {
                continue;
            }
            if ($load <= 0) {
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

        return [
            'loadPerHour' => array_sum($loadPerHourSamples) / count($loadPerHourSamples),
            'sampleCount' => count($loadPerHourSamples),
        ];
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
