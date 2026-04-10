<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

use App\Domain\Activity\DailyTrainingLoad;
use App\Domain\Dashboard\Widget\Wellness\FindDailyRecoveryCheckIns\FindDailyRecoveryCheckIns;
use App\Domain\Dashboard\Widget\Wellness\FindWellnessMetrics\FindWellnessMetrics;
use App\Domain\Wellness\WellnessSource;
use App\Infrastructure\CQRS\Query\Bus\QueryBus;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class IntegratedDailyLoadCalculator
{
    public function __construct(
        private DailyTrainingLoad $dailyTrainingLoad,
        private QueryBus $queryBus,
        private WellnessLoadContributionCalculator $wellnessLoadContributionCalculator,
        private RecoveryCheckInLoadContributionCalculator $recoveryCheckInLoadContributionCalculator,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function calculateForDateRange(DateRange $dateRange): array
    {
        $wellnessLoadContributionsByDay = $this->wellnessLoadContributionCalculator->calculateForRecords(
            $this->queryBus->ask(new FindWellnessMetrics(
                dateRange: $dateRange,
                source: WellnessSource::GARMIN,
            ))->getRecords()
        );

        $recoveryCheckInLoadContributionsByDay = $this->recoveryCheckInLoadContributionCalculator->calculateForRecords(
            $this->queryBus->ask(new FindDailyRecoveryCheckIns(
                dateRange: $dateRange,
            ))->getRecords()
        );

        $integratedLoads = [];
        for ($dayOffset = 0; $dayOffset < $dateRange->getNumberOfDays(); ++$dayOffset) {
            $day = SerializableDateTime::fromString(
                $dateRange->getFrom()->modify(sprintf('+%d days', $dayOffset))->format('Y-m-d 00:00:00')
            );
            $dateKey = $day->format('Y-m-d');

            $integratedLoads[$dateKey] = $this->dailyTrainingLoad->calculate($day)
                + ($wellnessLoadContributionsByDay[$dateKey] ?? 0)
                + ($recoveryCheckInLoadContributionsByDay[$dateKey] ?? 0);
        }

        return $integratedLoads;
    }
}