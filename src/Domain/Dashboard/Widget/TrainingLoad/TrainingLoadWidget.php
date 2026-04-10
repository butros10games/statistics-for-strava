<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

use App\Domain\Activity\Stream\ActivityHeartRateRepository;
use App\Domain\Dashboard\Widget\Wellness\FindDailyRecoveryCheckIns\FindDailyRecoveryCheckIns;
use App\Domain\Dashboard\Widget\Wellness\FindDailyRecoveryCheckIns\FindDailyRecoveryCheckInsResponse;
use App\Domain\Dashboard\Widget\Wellness\FindWellnessMetrics\FindWellnessMetrics;
use App\Domain\Dashboard\Widget\Wellness\FindWellnessMetrics\FindWellnessMetricsResponse;
use App\Domain\Wellness\WellnessSource;
use App\Domain\Dashboard\Widget\TrainingLoad\FindNumberOfRestDays\FindNumberOfRestDays;
use App\Domain\Dashboard\Widget\Widget;
use App\Domain\Dashboard\Widget\WidgetConfiguration;
use App\Infrastructure\CQRS\Query\Bus\QueryBus;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use League\Flysystem\FilesystemOperator;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final readonly class TrainingLoadWidget implements Widget
{
    public function __construct(
        private ActivityHeartRateRepository $activityHeartRateRepository,
        private IntegratedDailyLoadCalculator $integratedDailyLoadCalculator,
        private QueryBus $queryBus,
        private FilesystemOperator $buildStorage,
        private Environment $twig,
        private TranslatorInterface $translator,
        private Clock $clock,
        private WellnessReadinessCalculator $wellnessReadinessCalculator,
    ) {
    }

    public function guardValidConfiguration(WidgetConfiguration $configuration): void
    {
    }

    public function getDefaultConfiguration(): WidgetConfiguration
    {
        return WidgetConfiguration::empty();
    }

    public function render(SerializableDateTime $now, WidgetConfiguration $configuration): string
    {
        $timeInHeartRateZonesForLast30Days = $this->activityHeartRateRepository->findTotalTimeInSecondsInHeartRateZonesForLast30Days();

        $loadCalculationFrom = SerializableDateTime::fromString(
            $now->modify('- '.(TrainingLoadChart::NUMBER_OF_DAYS_TO_DISPLAY + 210).' days')->format('Y-m-d 00:00:00')
        );
        /** @var FindWellnessMetricsResponse $allWellnessMetrics */
        $allWellnessMetrics = $this->queryBus->ask(new FindWellnessMetrics(
            dateRange: DateRange::fromDates(
                from: $loadCalculationFrom,
                till: SerializableDateTime::fromString($now->format('Y-m-d 23:59:59')),
            ),
            source: WellnessSource::GARMIN,
        ));

        /** @var FindDailyRecoveryCheckInsResponse $allRecoveryCheckIns */
        $allRecoveryCheckIns = $this->queryBus->ask(new FindDailyRecoveryCheckIns(
            dateRange: DateRange::fromDates(
                from: $loadCalculationFrom,
                till: SerializableDateTime::fromString($now->format('Y-m-d 23:59:59')),
            ),
        ));

        $intensities = $this->integratedDailyLoadCalculator->calculateForDateRange(DateRange::fromDates(
            from: $loadCalculationFrom,
            till: SerializableDateTime::fromString($now->format('Y-m-d 23:59:59')),
        ));

        $trainingMetrics = TrainingMetrics::create($intensities);

        $wellnessMetrics = $this->filterWellnessMetricsByDateRange(
            $allWellnessMetrics,
            SerializableDateTime::fromString($now->modify('-29 days')->format('Y-m-d 00:00:00')),
        );

        $latestRecoveryCheckInOverall = $allRecoveryCheckIns->getLatestRecord();
        $latestRecoveryCheckIn = $this->latestRecoveryCheckInForToday($allRecoveryCheckIns, $now);

        $readinessScore = $this->wellnessReadinessCalculator->calculate($trainingMetrics, $wellnessMetrics, $latestRecoveryCheckIn);
        $latestWellnessRecord = $wellnessMetrics->getLatestRecord();
        $wellnessBaselineRecords = count($wellnessMetrics->getRecords()) > 1 ? array_slice($wellnessMetrics->getRecords(), 0, -1) : $wellnessMetrics->getRecords();

        $numberOfRestDays = $this->queryBus->ask(new FindNumberOfRestDays(DateRange::fromDates(
            from: $now->modify('-6 days'),
            till: $now,
        )))->getNumberOfRestDays();

        $this->buildStorage->write(
            'training-load.html',
            $this->twig->render('html/dashboard/training-load.html.twig', [
                'trainingLoadChart' => Json::encode(
                    TrainingLoadChart::create(
                        trainingMetrics: $trainingMetrics,
                        now: $now,
                        translator: $this->translator,
                    )->build()
                ),
                'trainingMetrics' => $trainingMetrics,
                'readinessScore' => $readinessScore,
                'latestWellnessHrv' => $latestWellnessRecord['hrv'] ?? null,
                'wellnessHrvBaseline' => $this->averageWellnessMetric($wellnessBaselineRecords, 'hrv'),
                'latestWellnessSleepScore' => $latestWellnessRecord['sleepScore'] ?? null,
                'trainingLoadForecast' => TrainingLoadForecastProjection::create(
                    metrics: $trainingMetrics,
                    now: $this->clock->getCurrentDateTimeImmutable()
                ),
                'latestRecoveryCheckIn' => $latestRecoveryCheckIn,
                'latestRecoveryCheckInOverall' => $latestRecoveryCheckInOverall,
                'shouldPromptRecoveryCheckIn' => null === $latestRecoveryCheckIn,
                'restDaysInLast7Days' => $numberOfRestDays,
                'timeInHeartRateZonesForLast30Days' => $timeInHeartRateZonesForLast30Days,
            ])
        );

        return $this->twig->load('html/dashboard/widget/widget--training-load.html.twig')->render([
            'timeInHeartRateZonesForLast30Days' => $timeInHeartRateZonesForLast30Days,
            'trainingMetrics' => $trainingMetrics,
            'readinessScore' => $readinessScore,
            'latestWellnessHrv' => $latestWellnessRecord['hrv'] ?? null,
            'wellnessHrvBaseline' => $this->averageWellnessMetric($wellnessBaselineRecords, 'hrv'),
            'latestWellnessSleepScore' => $latestWellnessRecord['sleepScore'] ?? null,
            'latestRecoveryCheckIn' => $latestRecoveryCheckIn,
            'latestRecoveryCheckInOverall' => $latestRecoveryCheckInOverall,
            'shouldPromptRecoveryCheckIn' => null === $latestRecoveryCheckIn,
            'restDaysInLast7Days' => $numberOfRestDays,
        ]);
    }

    /**
     * @param list<array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}> $records
     */
    private function averageWellnessMetric(array $records, string $field): ?float
    {
        $values = array_values(array_filter(
            array_map(static fn (array $record): int|float|null => $record[$field], $records),
            static fn (int|float|null $value): bool => null !== $value,
        ));

        if ([] === $values) {
            return null;
        }

        return array_sum($values) / count($values);
    }

    private function filterWellnessMetricsByDateRange(FindWellnessMetricsResponse $response, SerializableDateTime $from): FindWellnessMetricsResponse
    {
        $filteredRecords = array_values(array_filter(
            $response->getRecords(),
            static fn (array $record): bool => $record['day'] >= $from->format('Y-m-d'),
        ));

        $lastRecord = [] === $filteredRecords ? null : $filteredRecords[array_key_last($filteredRecords)];

        return new FindWellnessMetricsResponse(
            records: $filteredRecords,
            latestDay: null === $lastRecord ? null : SerializableDateTime::fromString($lastRecord['day'])->setTime(0, 0),
        );
    }

    /**
     * @return array{day: string, fatigue: int, soreness: int, stress: int, motivation: int, sleepQuality: int}|null
     */
    private function latestRecoveryCheckInForToday(FindDailyRecoveryCheckInsResponse $response, SerializableDateTime $now): ?array
    {
        $latestRecord = $response->getLatestRecord();
        if (null === $latestRecord) {
            return null;
        }

        return $latestRecord['day'] === $now->format('Y-m-d') ? $latestRecord : null;
    }

}
