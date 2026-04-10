<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\Wellness;

use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class MonthlyWellnessStatsChart
{
    /**
     * @param list<array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}> $records
     */
    private function __construct(
        private array $records,
        private WellnessMetric $metric,
        private int $enableLastXYearsByDefault,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}> $records
     */
    public static function create(
        array $records,
        WellnessMetric $metric,
        TranslatorInterface $translator,
        int $enableLastXYearsByDefault,
    ): self {
        return new self(
            records: $records,
            metric: $metric,
            enableLastXYearsByDefault: $enableLastXYearsByDefault,
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

        $monthlyBuckets = [];
        $firstDate = null;
        $lastDate = null;

        foreach ($this->records as $record) {
            $date = new \DateTimeImmutable($record['day']);
            $monthKey = $date->format('Y-m');
            $monthlyBuckets[$monthKey] ??= $this->createEmptyBucket();
            $monthlyBuckets[$monthKey] = $this->addRecordToBucket($monthlyBuckets[$monthKey], $record);

            $firstDate = null === $firstDate || $date < $firstDate ? $date : $firstDate;
            $lastDate = null === $lastDate || $date > $lastDate ? $date : $lastDate;
        }

        if (!$firstDate instanceof \DateTimeImmutable || !$lastDate instanceof \DateTimeImmutable) {
            return [];
        }

        $firstYear = (int) $firstDate->format('Y');
        $lastYear = (int) $lastDate->format('Y');
        $years = range($lastYear, $firstYear);

        $selectedSeries = [];
        $series = [];
        foreach ($years as $index => $year) {
            $selectedSeries[(string) $year] = $index < $this->enableLastXYearsByDefault;

            $data = [];
            for ($month = 1; $month <= 12; ++$month) {
                $monthKey = sprintf('%04d-%02d', $year, $month);
                $data[] = isset($monthlyBuckets[$monthKey])
                    ? $this->metric->resolveValue($monthlyBuckets[$monthKey])
                    : null;
            }

            if ([] === array_filter($data, static fn (int|float|null $value): bool => null !== $value)) {
                continue;
            }

            $series[] = [
                'name' => (string) $year,
                'type' => 'line',
                'smooth' => true,
                'data' => $data,
            ];
        }

        if ([] === $series) {
            return [];
        }

        return [
            'backgroundColor' => null,
            'animation' => false,
            'grid' => [
                'top' => '50px',
                'left' => '2px',
                'right' => '10px',
                'bottom' => '2%',
                'containLabel' => true,
            ],
            'tooltip' => [
                'trigger' => 'axis',
            ],
            'legend' => [
                'selected' => $selectedSeries,
                'data' => array_map(static fn (array $serie): string => $serie['name'], $series),
            ],
            'xAxis' => [
                'type' => 'category',
                'boundaryGap' => false,
                'data' => [
                    $this->translator->trans('Jan'),
                    $this->translator->trans('Feb'),
                    $this->translator->trans('Mar'),
                    $this->translator->trans('Apr'),
                    $this->translator->trans('May'),
                    $this->translator->trans('Jun'),
                    $this->translator->trans('Jul'),
                    $this->translator->trans('Aug'),
                    $this->translator->trans('Sep'),
                    $this->translator->trans('Oct'),
                    $this->translator->trans('Nov'),
                    $this->translator->trans('Dec'),
                ],
                'axisPointer' => [
                    'type' => 'shadow',
                ],
            ],
            'yAxis' => [
                'type' => 'value',
                'axisLabel' => [
                    'formatter' => $this->metric->getAxisLabelFormatter(),
                ],
            ],
            'series' => $series,
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