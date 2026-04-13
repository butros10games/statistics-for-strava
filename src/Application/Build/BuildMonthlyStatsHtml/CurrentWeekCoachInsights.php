<?php

declare(strict_types=1);

namespace App\Application\Build\BuildMonthlyStatsHtml;

final readonly class CurrentWeekCoachInsights
{
    /**
     * @param list<array{activityType: \App\Domain\Activity\ActivityType, count: int}> $activityTypeSummaries
     * @param array<string, true> $keySessionIds
     * @param array<string, true> $brickSessionIds
     * @param null|array{label: string, tone: string, title: string, body: string} $raceIntent
     * @param list<array{tone: string, title: string, body: string}> $coachCues
     */
    public function __construct(
        private float $estimatedLoad,
        private array $activityTypeSummaries,
        private array $keySessionIds,
        private array $brickSessionIds,
        private ?array $raceIntent,
        private array $coachCues,
    ) {
    }

    public function getEstimatedLoad(): float
    {
        return $this->estimatedLoad;
    }

    /**
     * @return list<array{activityType: \App\Domain\Activity\ActivityType, count: int}>
     */
    public function getActivityTypeSummaries(): array
    {
        return $this->activityTypeSummaries;
    }

    /**
     * @return array<string, true>
     */
    public function getKeySessionIds(): array
    {
        return $this->keySessionIds;
    }

    /**
     * @return array<string, true>
     */
    public function getBrickSessionIds(): array
    {
        return $this->brickSessionIds;
    }

    /**
     * @return null|array{label: string, tone: string, title: string, body: string}
     */
    public function getRaceIntent(): ?array
    {
        return $this->raceIntent;
    }

    /**
     * @return list<array{tone: string, title: string, body: string}>
     */
    public function getCoachCues(): array
    {
        return $this->coachCues;
    }
}
