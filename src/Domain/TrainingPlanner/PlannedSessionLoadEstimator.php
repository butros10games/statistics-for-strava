<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityType;
use App\Domain\Athlete\AthleteRepository;

final class PlannedSessionLoadEstimator
{
    private const int RECENT_ACTIVITY_SAMPLE_LIMIT = 12;

    /** @var array<string, ?float> */
    private array $historicalLoadPerHourByActivityType = [];
    private ?float $globalHistoricalLoadPerHour = null;

    public function __construct(
        private readonly ActivityRepository $activityRepository,
        private readonly AthleteRepository $athleteRepository,
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

        if (null !== $plannedSession->getTargetLoad()) {
            return PlannedSessionLoadEstimate::create(
                plannedSession: $plannedSession,
                estimatedLoad: $plannedSession->getTargetLoad(),
                estimationSource: PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
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
