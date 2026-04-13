<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class PlannedSessionForecastBuilder
{
    public function __construct(
        private PlannedSessionRepository $plannedSessionRepository,
        private PlannedSessionLoadEstimator $plannedSessionLoadEstimator,
    ) {
    }

    public function build(SerializableDateTime $now, int $horizon = 7): PlannedSessionForecast
    {
        $horizon = max(1, $horizon);
        $today = $now->setTime(0, 0);
        $from = $today;
        $till = $today->modify(sprintf('+%d days', $horizon))->setTime(23, 59, 59);

        $currentDayProjectedLoad = 0.0;
        $projectedLoads = array_fill_keys(range(1, $horizon), 0.0);
        $estimates = [];

        foreach ($this->plannedSessionRepository->findByDateRange(DateRange::fromDates($from, $till)) as $plannedSession) {
            $estimate = $this->plannedSessionLoadEstimator->estimate($plannedSession);
            if (null === $estimate) {
                continue;
            }

            if ($estimate->getDay()->format('Y-m-d') === $today->format('Y-m-d')) {
                if ($this->isCompletedForToday($plannedSession)) {
                    continue;
                }

                $currentDayProjectedLoad = round($currentDayProjectedLoad + $estimate->getEstimatedLoad(), 1);
                $estimates[] = $estimate;

                continue;
            }

            $dayOffset = (int) $today->diff($estimate->getDay())->format('%a');
            if ($dayOffset < 1 || $dayOffset > $horizon) {
                continue;
            }

            $projectedLoads[$dayOffset] = round($projectedLoads[$dayOffset] + $estimate->getEstimatedLoad(), 1);
            $estimates[] = $estimate;
        }

        return [] === $estimates
            ? PlannedSessionForecast::empty($horizon)
            : PlannedSessionForecast::create($currentDayProjectedLoad, $projectedLoads, $estimates);
    }

    private function isCompletedForToday(PlannedSession $plannedSession): bool
    {
        return PlannedSessionLinkStatus::LINKED === $plannedSession->getLinkStatus()
            && null !== $plannedSession->getLinkedActivityId();
    }
}
