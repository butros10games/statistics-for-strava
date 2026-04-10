<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\Wellness;

use App\Domain\Calendar\Week;
use App\Domain\Calendar\Weeks;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class WeeklyWellnessStatsChart
{
    /**
     * @param list<array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}> $records
     */
    private function __construct(
        private array $records,
        private WellnessMetric $metric,
        private SerializableDateTime $now,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}> $records
     */
    public static function create(
        array $records,
        WellnessMetric $metric,
        SerializableDateTime $now,
        TranslatorInterface $translator,
    ): self {
        return new self(
            records: $records,
            metric: $metric,
            now: $now,
            translator: $translator,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        if ([] === $this->records) {
            return [];
        }

        $weeks = Weeks::create(
            startDate: SerializableDateTime::fromString(sprintf('%s 00:00:00', $this->records[0]['day'])),
            now: $this->now,
        );

        $buckets = [];
        foreach ($weeks as $week) {
            $buckets[$week->getId()] = $this->createEmptyBucket();
        }

        foreach ($this->records as $record) {
            $weekId = Week::fromDate(SerializableDateTime::fromString(sprintf('%s 00:00:00', $record['day'])))->getId();
            if (!array_key_exists($weekId, $buckets)) {
                continue;
            }

            $buckets[$weekId] = $this->addRecordToBucket($buckets[$weekId], $record);
        }

        $values = [];
        $labels = [];
        $weeklyLabels = [];
        foreach ($weeks as $week) {
            $weeklyLabels[] = $week->getLabel();
            $values[] = $this->metric->resolveValue($buckets[$week->getId()]);
        }

        if ([] === array_filter($values, static fn (int|float|null $value): bool => null !== $value)) {
            return [];
        }

        foreach ($weeklyLabels as $index => $weeklyLabel) {
            if (0 === $index || in_array($weeklyLabel, $labels, true)) {
                $labels[] = '';
                continue;
            }

            $labels[] = $weeklyLabel;
        }

        $totalWeeks = count($values);
        $valueSpan = max(1, min(26, $totalWeeks));

        return [
            'animation' => true,
            'backgroundColor' => null,
            'color' => [$this->metric->getColor()],
            'grid' => [
                'left' => '10px',
                'right' => '10px',
                'bottom' => '50px',
                'containLabel' => true,
            ],
            'tooltip' => [
                'trigger' => 'axis',
            ],
            'dataZoom' => [
                [
                    'type' => 'slider',
                    'startValue' => max(0, $totalWeeks - $valueSpan),
                    'endValue' => max(0, $totalWeeks - 1),
                    'minValueSpan' => min(10, $valueSpan),
                    'maxValueSpan' => $valueSpan,
                    'brushSelect' => false,
                    'zoomLock' => false,
                ],
            ],
            'xAxis' => [
                [
                    'type' => 'category',
                    'boundaryGap' => false,
                    'axisTick' => [
                        'show' => false,
                    ],
                    'axisLabel' => [
                        'interval' => 0,
                    ],
                    'data' => $labels,
                    'splitLine' => [
                        'show' => true,
                        'lineStyle' => [
                            'color' => '#E0E6F1',
                        ],
                    ],
                ],
            ],
            'yAxis' => [
                [
                    'type' => 'value',
                    'splitLine' => [
                        'show' => false,
                    ],
                    'axisLabel' => [
                        'formatter' => $this->metric->getAxisLabelFormatter(),
                    ],
                ],
            ],
            'series' => [
                [
                    'name' => $this->translator->trans($this->metric->getLabel()),
                    'type' => 'line',
                    'smooth' => false,
                    'data' => $values,
                    'lineStyle' => [
                        'width' => 1,
                    ],
                    'symbolSize' => 6,
                    'showSymbol' => true,
                    'areaStyle' => [
                        'opacity' => 0.24,
                    ],
                    'emphasis' => [
                        'focus' => 'series',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array{
     *     stepsTotal: int,
     *     stepsCount: int,
     *     sleepDurationTotal: int,
     *     sleepDurationCount: int,
     *     sleepScoreTotal: int,
     *     sleepScoreCount: int,
     *     hrvTotal: float,
     *     hrvCount: int
     * }
     */
    private function createEmptyBucket(): array
    {
        return [
            'stepsTotal' => 0,
            'stepsCount' => 0,
            'sleepDurationTotal' => 0,
            'sleepDurationCount' => 0,
            'sleepScoreTotal' => 0,
            'sleepScoreCount' => 0,
            'hrvTotal' => 0.0,
            'hrvCount' => 0,
        ];
    }

    /**
     * @param array{
     *     stepsTotal: int,
     *     stepsCount: int,
     *     sleepDurationTotal: int,
     *     sleepDurationCount: int,
     *     sleepScoreTotal: int,
     *     sleepScoreCount: int,
     *     hrvTotal: float,
     *     hrvCount: int
     * } $bucket
     * @param array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float} $record
     *
     * @return array{
     *     stepsTotal: int,
     *     stepsCount: int,
     *     sleepDurationTotal: int,
     *     sleepDurationCount: int,
     *     sleepScoreTotal: int,
     *     sleepScoreCount: int,
     *     hrvTotal: float,
     *     hrvCount: int
     * }
     */
    private function addRecordToBucket(array $bucket, array $record): array
    {
        if (null !== $record['stepsCount']) {
            $bucket['stepsTotal'] += $record['stepsCount'];
            ++$bucket['stepsCount'];
        }
        if (null !== $record['sleepDurationInSeconds']) {
            $bucket['sleepDurationTotal'] += $record['sleepDurationInSeconds'];
            ++$bucket['sleepDurationCount'];
        }
        if (null !== $record['sleepScore']) {
            $bucket['sleepScoreTotal'] += $record['sleepScore'];
            ++$bucket['sleepScoreCount'];
        }
        if (null !== $record['hrv']) {
            $bucket['hrvTotal'] += $record['hrv'];
            ++$bucket['hrvCount'];
        }

        return $bucket;
    }
}