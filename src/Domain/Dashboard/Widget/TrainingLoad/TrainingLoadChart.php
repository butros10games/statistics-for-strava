<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

use App\Infrastructure\Serialization\Escape;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class TrainingLoadChart
{
    public const int NUMBER_OF_DAYS_TO_DISPLAY = 366;
    public const int ROLLING_WINDOW_TO_CALCULATE_METRICS_AGAINST = 42;

    private const string FORECAST_CTL_SERIES_NAME = '__forecast_ctl';
    private const string FORECAST_ATL_SERIES_NAME = '__forecast_atl';
    private const string FORECAST_TSB_SERIES_NAME = '__forecast_tsb';
    private const string FORECAST_LOAD_SERIES_NAME = '__forecast_load';

    private function __construct(
        private TrainingMetrics $trainingMetrics,
        private SerializableDateTime $now,
        private TranslatorInterface $translator,
        private ?TrainingLoadForecastProjection $plannedSessionForecastProjection = null,
    ) {
    }

    public static function create(
        TrainingMetrics $trainingMetrics,
        SerializableDateTime $now,
        TranslatorInterface $translator,
        ?TrainingLoadForecastProjection $plannedSessionForecastProjection = null,
    ): self {
        return new self(
            trainingMetrics: $trainingMetrics,
            now: $now,
            translator: $translator,
            plannedSessionForecastProjection: $plannedSessionForecastProjection,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $ctlLabel = Escape::forJsonEncode($this->translator->trans('CTL (Fitness)'));
        $atlLabel = Escape::forJsonEncode($this->translator->trans('ATL (Fatigue)'));
        $tsbLabel = Escape::forJsonEncode($this->translator->trans('TSB (Form)'));
        $dailyLoadLabel = Escape::forJsonEncode($this->translator->trans('Daily load'));
        $currentDayProjection = $this->plannedSessionForecastProjection?->getCurrentDayProjection();
        $forecastProjectionRows = $this->plannedSessionForecastProjection?->getProjection() ?? [];
        $forecastHorizon = count($forecastProjectionRows);
        $start = SerializableDateTime::fromString($this->now->format('Y-m-d 00:00:00'))
                ->modify('-'.(self::NUMBER_OF_DAYS_TO_DISPLAY - 1).' days');
        $period = new \DatePeriod(
            $start,
            new \DateInterval('P1D'),
            SerializableDateTime::fromString($this->now->format('Y-m-d 23:59:59'))
        );

        $formattedDates = [];
        foreach ($period as $date) {
            $formattedDates[] = SerializableDateTime::fromDateTimeImmutable($date)->translatedFormat('M d');
        }

        foreach ($forecastProjectionRows as $forecastRow) {
            $formattedDates[] = $forecastRow['day']->translatedFormat('M d');
        }

        $historicalDays = count($formattedDates) - $forecastHorizon;
        $tsbValues = $this->trainingMetrics->getTsbValuesForXLastDays(self::NUMBER_OF_DAYS_TO_DISPLAY);
        $ctlValues = $this->trainingMetrics->getCtlValuesForXLastDays(self::NUMBER_OF_DAYS_TO_DISPLAY);
        $atlValues = $this->trainingMetrics->getAtlValuesForXLastDays(self::NUMBER_OF_DAYS_TO_DISPLAY);
        $trimpValues = $this->trainingMetrics->getTrimpValuesForXLastDays(self::NUMBER_OF_DAYS_TO_DISPLAY);
        $forecastCtlValues = array_map(
            static fn (array $forecastRow): float => $forecastRow['ctl'],
            $forecastProjectionRows,
        );
        $forecastAtlValues = array_map(
            static fn (array $forecastRow): float => $forecastRow['atl'],
            $forecastProjectionRows,
        );
        $forecastTsbValues = array_map(
            static fn (array $forecastRow): float => $forecastRow['tsb']->getValue(),
            $forecastProjectionRows,
        );
        $forecastLoadValues = array_map(
            static fn (array $forecastRow): float => $forecastRow['projectedLoad'],
            $forecastProjectionRows,
        );

        $extendedTsbValues = array_merge($tsbValues, array_fill(0, $forecastHorizon, null));
        $extendedCtlValues = array_merge($ctlValues, array_fill(0, $forecastHorizon, null));
        $extendedAtlValues = array_merge($atlValues, array_fill(0, $forecastHorizon, null));
        $extendedTrimpValues = array_merge($trimpValues, array_fill(0, $forecastHorizon, null));

        $forecastStartIndex = max(0, $historicalDays - 1);
        $plannedForecastCtlSeries = $forecastHorizon > 0
            ? array_merge(
                array_fill(0, $forecastStartIndex, null),
                [(float) ($currentDayProjection['ctl'] ?? ($ctlValues[array_key_last($ctlValues)] ?? 0.0))],
                $forecastCtlValues,
            )
            : [];
        $plannedForecastAtlSeries = $forecastHorizon > 0
            ? array_merge(
                array_fill(0, $forecastStartIndex, null),
                [(float) ($currentDayProjection['atl'] ?? ($atlValues[array_key_last($atlValues)] ?? 0.0))],
                $forecastAtlValues,
            )
            : [];
        $plannedForecastTsbSeries = $forecastHorizon > 0
            ? array_merge(
                array_fill(0, $forecastStartIndex, null),
                [(float) (($currentDayProjection['tsb'] ?? null)?->getValue() ?? ($tsbValues[array_key_last($tsbValues)] ?? 0.0))],
                $forecastTsbValues,
            )
            : [];
        $plannedForecastLoadSeries = $forecastHorizon > 0
            ? array_merge(
                array_fill(0, max(0, $historicalDays - 1), null),
                [null === $currentDayProjection ? null : (float) $currentDayProjection['projectedLoad']],
                $forecastLoadValues,
            )
            : [];

        $allTsbValues = array_values(array_filter(array_merge($tsbValues, $forecastTsbValues), static fn (int|float|null $value): bool => null !== $value));
        $totalDisplayedDays = count($formattedDates);

        return [
            'tooltip' => [
                'trigger' => 'axis',
                'formatter' => 'callback:formatTrainingLoadTooltip',
                'axisPointer' => [
                    'link' => [['xAxisIndex' => 'all']],
                    'label' => ['backgroundColor' => '#6a7985'],
                ],
            ],
            'legend' => [
                'show' => true,
                'data' => [$ctlLabel, $atlLabel, $tsbLabel, $dailyLoadLabel],
            ],
            'toolbox' => [
                'show' => true,
                'feature' => [
                    'restore' => [
                        'show' => true,
                    ],
                ],
            ],
            'dataZoom' => [
                [
                    'type' => 'slider',
                    'startValue' => max(0, $totalDisplayedDays - (self::ROLLING_WINDOW_TO_CALCULATE_METRICS_AGAINST + $forecastHorizon)),
                    'endValue' => $totalDisplayedDays,
                    'minValueSpan' => self::ROLLING_WINDOW_TO_CALCULATE_METRICS_AGAINST,
                    'brushSelect' => false,
                    'zoomLock' => true,
                    'xAxisIndex' => 'all',
                ],
            ],
            'axisPointer' => [
                'link' => ['xAxisIndex' => 'all'],
            ],
            'grid' => [
                [
                    'left' => '65px',
                    'right' => '65px',
                    'top' => '40px',
                    'height' => '53%',
                    'containLabel' => false,
                ],
                [
                    'left' => '65px',
                    'right' => '65px',
                    'top' => '65%',
                    'height' => '20%',
                    'bottom' => '0px',
                    'containLabel' => false,
                ],
            ],
            'xAxis' => [
                [
                    'type' => 'category',
                    'gridIndex' => 0,
                    'data' => $formattedDates,
                    'boundaryGap' => true,
                    'axisLine' => ['onZero' => false],
                    'axisLabel' => ['show' => false],
                    'axisTick' => ['show' => false],
                ],
                [
                    'type' => 'category',
                    'gridIndex' => 1,
                    'data' => $formattedDates,
                    'boundaryGap' => true,
                    'position' => 'bottom',
                    'axisLabel' => ['show' => true],
                    'axisTick' => ['show' => true],
                ],
            ],
            'yAxis' => [
                [
                    'type' => 'value',
                    'name' => Escape::forJsonEncode($this->translator->trans('Daily load')),
                    'nameLocation' => 'middle',
                    'nameGap' => 35,
                    'gridIndex' => 1,
                    'position' => 'left',
                    'splitLine' => ['show' => true],
                    'axisLine' => ['show' => true, 'lineStyle' => ['color' => '#cccccc']],
                    'minInterval' => 1,
                ],
                [
                    'type' => 'value',
                    'name' => Escape::forJsonEncode($this->translator->trans('Load (CTL/ATL)')),
                    'nameLocation' => 'middle',
                    'nameGap' => 35,
                    'gridIndex' => 0,
                    'position' => 'left',
                    'alignTicks' => true,
                    'axisLine' => ['show' => true, 'lineStyle' => ['color' => '#cccccc']],
                    'axisLabel' => ['formatter' => 'callback:toInteger'],
                    'splitLine' => ['show' => true],
                    'minInterval' => 1,
                ],
                [
                    'type' => 'value',
                    'name' => Escape::forJsonEncode($this->translator->trans('Form (TSB)')),
                    'nameLocation' => 'middle',
                    'nameGap' => 35,
                    'gridIndex' => 0,
                    'position' => 'right',
                    'alignTicks' => true,
                    'max' => (int) ceil(max(25, ...$allTsbValues)),
                    'min' => (int) floor(min(-35, ...$allTsbValues)),
                    'minInterval' => 1,
                    'axisLine' => ['show' => true, 'lineStyle' => ['color' => '#cccccc']],
                    'axisLabel' => ['formatter' => 'callback:toInteger'],
                    'splitLine' => ['show' => false],
                ],
            ],
            'series' => [
                [
                    'name' => $ctlLabel,
                    'type' => 'line',
                    'data' => $extendedCtlValues,
                    'smooth' => true,
                    'symbol' => 'none',
                    'xAxisIndex' => 0,
                    'yAxisIndex' => 1,
                ],
                [
                    'name' => $atlLabel,
                    'type' => 'line',
                    'data' => $extendedAtlValues,
                    'smooth' => true,
                    'symbol' => 'none',
                    'xAxisIndex' => 0,
                    'yAxisIndex' => 1,
                ],
                [
                    'name' => $tsbLabel,
                    'type' => 'line',
                    'data' => $extendedTsbValues,
                    'smooth' => true,
                    'symbol' => 'none',
                    'xAxisIndex' => 0,
                    'yAxisIndex' => 2,
                    'markLine' => [
                        'silent' => true,
                        'lineStyle' => ['color' => '#333', 'type' => 'dashed'],
                        'label' => [
                            'position' => 'insideEndTop',
                        ],
                        'data' => [
                            [
                                'yAxis' => 15,
                                'label' => ['formatter' => Escape::forJsonEncode($this->translator->trans('Taper sweet-spot (+15)'))],
                            ],
                            [
                                'yAxis' => -10,
                                'label' => ['formatter' => Escape::forJsonEncode($this->translator->trans('Build zone (–10)'))],
                            ],
                            [
                                'yAxis' => -30,
                                'label' => ['formatter' => Escape::forJsonEncode($this->translator->trans('Over-fatigued (–30)'))],
                            ],
                        ],
                    ],
                ],
                [
                    'name' => $dailyLoadLabel,
                    'type' => 'bar',
                    'data' => $extendedTrimpValues,
                    'itemStyle' => ['color' => '#FC4C02'],
                    'barWidth' => '60%',
                    'xAxisIndex' => 1,
                    'yAxisIndex' => 0,
                    'emphasis' => ['itemStyle' => ['opacity' => 0.8]],
                ],
                ...$this->buildForecastSeries(
                    forecastHorizon: $forecastHorizon,
                    plannedForecastCtlSeries: $plannedForecastCtlSeries,
                    plannedForecastAtlSeries: $plannedForecastAtlSeries,
                    plannedForecastTsbSeries: $plannedForecastTsbSeries,
                    plannedForecastLoadSeries: $plannedForecastLoadSeries,
                ),
            ],
        ];
    }

    /**
     * @param list<float|null> $plannedForecastCtlSeries
     * @param list<float|null> $plannedForecastAtlSeries
     * @param list<float|null> $plannedForecastTsbSeries
     * @param list<float|null> $plannedForecastLoadSeries
     *
     * @return list<array<string, mixed>>
     */
    private function buildForecastSeries(
        int $forecastHorizon,
        array $plannedForecastCtlSeries,
        array $plannedForecastAtlSeries,
        array $plannedForecastTsbSeries,
        array $plannedForecastLoadSeries,
    ): array {
        if ($forecastHorizon <= 0) {
            return [];
        }

        return [
            [
                'name' => self::FORECAST_CTL_SERIES_NAME,
                'type' => 'line',
                'data' => $plannedForecastCtlSeries,
                'smooth' => true,
                'symbol' => 'none',
                'connectNulls' => false,
                'lineStyle' => ['type' => 'dashed', 'width' => 2, 'color' => '#5470c6'],
                'xAxisIndex' => 0,
                'yAxisIndex' => 1,
            ],
            [
                'name' => self::FORECAST_ATL_SERIES_NAME,
                'type' => 'line',
                'data' => $plannedForecastAtlSeries,
                'smooth' => true,
                'symbol' => 'none',
                'connectNulls' => false,
                'lineStyle' => ['type' => 'dashed', 'width' => 2, 'color' => '#91cc75'],
                'xAxisIndex' => 0,
                'yAxisIndex' => 1,
            ],
            [
                'name' => self::FORECAST_TSB_SERIES_NAME,
                'type' => 'line',
                'data' => $plannedForecastTsbSeries,
                'smooth' => true,
                'symbol' => 'none',
                'connectNulls' => false,
                'lineStyle' => ['type' => 'dashed', 'width' => 2, 'color' => '#9a60b4'],
                'xAxisIndex' => 0,
                'yAxisIndex' => 2,
            ],
            [
                'name' => self::FORECAST_LOAD_SERIES_NAME,
                'type' => 'bar',
                'data' => $plannedForecastLoadSeries,
                'itemStyle' => ['color' => 'rgba(252, 76, 2, 0.35)', 'borderColor' => '#FC4C02', 'borderWidth' => 1],
                'barWidth' => '60%',
                'xAxisIndex' => 1,
                'yAxisIndex' => 0,
                'emphasis' => ['itemStyle' => ['opacity' => 0.9]],
            ],
        ];
    }
}
