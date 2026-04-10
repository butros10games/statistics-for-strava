<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget;

use App\Domain\Dashboard\Widget\Wellness\FindDailyRecoveryCheckIns\FindDailyRecoveryCheckIns;
use App\Domain\Dashboard\Widget\Wellness\FindDailyRecoveryCheckIns\FindDailyRecoveryCheckInsResponse;
use App\Domain\Dashboard\Widget\Wellness\FindWellnessMetrics\FindWellnessMetrics;
use App\Domain\Dashboard\Widget\Wellness\FindWellnessMetrics\FindWellnessMetricsResponse;
use App\Domain\Dashboard\Widget\Wellness\WellnessTrendChart;
use App\Domain\Wellness\WellnessSource;
use App\Infrastructure\CQRS\Query\Bus\QueryBus;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\ValueObject\Measurement\Time\Seconds;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final readonly class WellnessWidget implements Widget
{
    public function __construct(
        private QueryBus $queryBus,
        private Environment $twig,
        private TranslatorInterface $translator,
    ) {
    }

    public function getDefaultConfiguration(): WidgetConfiguration
    {
        return WidgetConfiguration::empty();
    }

    public function guardValidConfiguration(WidgetConfiguration $configuration): void
    {
    }

    public function render(SerializableDateTime $now, WidgetConfiguration $configuration): ?string
    {
        /** @var FindWellnessMetricsResponse $response */
        $response = $this->queryBus->ask(new FindWellnessMetrics(
            dateRange: DateRange::fromDates(
                from: SerializableDateTime::fromString($now->modify('-29 days')->format('Y-m-d 00:00:00')),
                till: SerializableDateTime::fromString($now->format('Y-m-d 23:59:59')),
            ),
            source: WellnessSource::GARMIN,
        ));

        /** @var FindDailyRecoveryCheckInsResponse $recoveryCheckIns */
        $recoveryCheckIns = $this->queryBus->ask(new FindDailyRecoveryCheckIns(
            dateRange: DateRange::fromDates(
                from: SerializableDateTime::fromString($now->modify('-29 days')->format('Y-m-d 00:00:00')),
                till: SerializableDateTime::fromString($now->format('Y-m-d 23:59:59')),
            ),
        ));

        if ($response->isEmpty() && $recoveryCheckIns->isEmpty()) {
            return null;
        }

        $records = $response->getRecords();
        $latestRecord = $response->getLatestRecord();
        $labels = array_map(static fn (array $record): string => SerializableDateTime::fromString($record['day'])->format('m-d'), $records);
        $latestRecoveryCheckIn = $recoveryCheckIns->getLatestRecord();

        $recoveryDefaults = null !== $latestRecoveryCheckIn && $latestRecoveryCheckIn['day'] === $now->format('Y-m-d') ? $latestRecoveryCheckIn : [
            'fatigue' => 3,
            'soreness' => 3,
            'stress' => 3,
            'motivation' => 3,
            'sleepQuality' => 3,
        ];

        return $this->twig->load('html/dashboard/widget/widget--wellness.html.twig')->render([
            'latestDay' => $response->getLatestDay(),
            'latestStepsCount' => $latestRecord['stepsCount'] ?? null,
            'latestSleepDurationInSeconds' => null === ($latestRecord['sleepDurationInSeconds'] ?? null) ? null : Seconds::from($latestRecord['sleepDurationInSeconds']),
            'latestSleepScore' => $latestRecord['sleepScore'] ?? null,
            'latestHrv' => $latestRecord['hrv'] ?? null,
            'averageStepsCount' => $this->average(array_column($records, 'stepsCount')),
            'averageSleepDurationInSeconds' => $this->averageAsSeconds(array_column($records, 'sleepDurationInSeconds')),
            'averageHrv' => $this->average(array_column($records, 'hrv')),
            'stepsTrendChart' => Json::encode(WellnessTrendChart::create(
                title: 'Steps',
                labels: $labels,
                values: array_map(static fn (?int $value): ?int => $value, array_column($records, 'stepsCount')),
                color: '#2563EB',
                unit: 'steps',
                translator: $this->translator,
            )->build()),
            'sleepTrendChart' => Json::encode(WellnessTrendChart::create(
                title: 'Sleep',
                labels: $labels,
                values: array_map(
                    static fn (?int $value): ?float => null === $value ? null : round($value / 3600, 1),
                    array_column($records, 'sleepDurationInSeconds')
                ),
                color: '#7C3AED',
                unit: 'h',
                translator: $this->translator,
            )->build()),
            'hrvTrendChart' => Json::encode(WellnessTrendChart::create(
                title: 'HRV',
                labels: $labels,
                values: array_map(static fn (?float $value): ?float => null === $value ? null : round($value, 1), array_column($records, 'hrv')),
                color: '#059669',
                unit: 'ms',
                translator: $this->translator,
            )->build()),
            'latestRecoveryCheckIn' => $latestRecoveryCheckIn,
            'recoveryCheckInDefaultDay' => $now->format('Y-m-d'),
            'recoveryCheckInFormDefaults' => $recoveryDefaults,
        ]);
    }

    /**
     * @param array<int, int|float|null> $values
     */
    private function average(array $values): ?float
    {
        $numericValues = array_values(array_filter($values, static fn (int|float|null $value): bool => null !== $value));
        if ([] === $numericValues) {
            return null;
        }

        return array_sum($numericValues) / count($numericValues);
    }

    /**
     * @param array<int, int|float|null> $values
     */
    private function averageAsSeconds(array $values): ?Seconds
    {
        $average = $this->average($values);

        return null === $average ? null : Seconds::from((int) round($average));
    }
}