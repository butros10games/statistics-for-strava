<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\MonthlyStats;

use App\Domain\Activity\ActivityTypeRepository;
use App\Domain\Calendar\FindMonthlyStats\FindMonthlyStats;
use App\Domain\Dashboard\InvalidDashboardLayout;
use App\Domain\Dashboard\StatsContext;
use App\Domain\Dashboard\Widget\Wellness\FindWellnessMetrics\FindWellnessMetrics;
use App\Domain\Dashboard\Widget\Wellness\FindWellnessMetrics\FindWellnessMetricsResponse;
use App\Domain\Dashboard\Widget\Wellness\MonthlyWellnessStatsChart;
use App\Domain\Dashboard\Widget\Wellness\WellnessMetric;
use App\Domain\Dashboard\Widget\Widget;
use App\Domain\Dashboard\Widget\WidgetConfiguration;
use App\Domain\Wellness\WellnessSource;
use App\Infrastructure\CQRS\Query\Bus\QueryBus;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\ValueObject\Measurement\UnitSystem;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final readonly class MonthlyStatsWidget implements Widget
{
    public function __construct(
        private ActivityTypeRepository $activityTypeRepository,
        private QueryBus $queryBus,
        private UnitSystem $unitSystem,
        private Environment $twig,
        private TranslatorInterface $translator,
    ) {
    }

    public function getDefaultConfiguration(): WidgetConfiguration
    {
        return WidgetConfiguration::empty()
            ->add('enableLastXYearsByDefault', 10)
            ->add('metricsDisplayOrder', array_map(fn (StatsContext $context) => $context->value, StatsContext::defaultSortingOrder()));
    }

    public function guardValidConfiguration(WidgetConfiguration $configuration): void
    {
        if (!$configuration->exists('enableLastXYearsByDefault')) {
            throw new InvalidDashboardLayout('Configuration item "enableLastXYearsByDefault" is required for MonthlyStatsWidget.');
        }
        if (!is_int($configuration->get('enableLastXYearsByDefault'))) {
            throw new InvalidDashboardLayout('Configuration item "enableLastXYearsByDefault" must be an integer.');
        }
        if (!$configuration->exists('metricsDisplayOrder')) {
            throw new InvalidDashboardLayout('Configuration item "metricsDisplayOrder" is required for MonthlyStatsWidget.');
        }
        if (!is_array($configuration->get('metricsDisplayOrder'))) {
            throw new InvalidDashboardLayout('Configuration item "metricsDisplayOrder" must be an array.');
        }
        if (3 !== count($configuration->get('metricsDisplayOrder'))) {
            throw new InvalidDashboardLayout('Configuration item "metricsDisplayOrder" must contain all 3 metrics.');
        }
        foreach ($configuration->get('metricsDisplayOrder') as $metricDisplayOrder) {
            if (!StatsContext::tryFrom($metricDisplayOrder)) {
                throw new InvalidDashboardLayout(sprintf('Configuration item "metricsDisplayOrder" contains invalid value "%s".', $metricDisplayOrder));
            }
        }
    }

    public function render(SerializableDateTime $now, WidgetConfiguration $configuration): string
    {
        $activityTypes = $this->activityTypeRepository->findAll();

        $monthlyStatChartsPerContext = [];
        $monthlyWellnessCharts = [];
        $monthlyWellnessMetricTabs = [];
        $monthlyStats = $this->queryBus->ask(new FindMonthlyStats());

        /** @var int $enableLastXYearsByDefault */
        $enableLastXYearsByDefault = $configuration->get('enableLastXYearsByDefault');
        foreach (StatsContext::cases() as $monthlyStatsContext) {
            foreach ($activityTypes as $activityType) {
                if (in_array($monthlyStatsContext, [StatsContext::DISTANCE, StatsContext::ELEVATION]) && !$activityType->supportsDistanceAndElevation()) {
                    continue;
                }

                $monthlyStatChartsPerContext[$monthlyStatsContext->value][$activityType->value] = Json::encode(
                    MonthlyStatsChart::create(
                        activityType: $activityType,
                        monthlyStats: $monthlyStats,
                        context: $monthlyStatsContext,
                        unitSystem: $this->unitSystem,
                        translator: $this->translator,
                        enableLastXYearsByDefault: $enableLastXYearsByDefault
                    )->build()
                );
            }
        }

        /** @var FindWellnessMetricsResponse $wellnessMetrics */
        $wellnessMetrics = $this->queryBus->ask(new FindWellnessMetrics(
            dateRange: DateRange::fromDates(
                from: SerializableDateTime::fromString($now->modify('-20 years')->format('Y-m-d 00:00:00')),
                till: SerializableDateTime::fromString($now->format('Y-m-d 23:59:59')),
            ),
            source: WellnessSource::GARMIN,
        ));

        foreach (WellnessMetric::cases() as $metric) {
            $chartData = MonthlyWellnessStatsChart::create(
                records: $wellnessMetrics->getRecords(),
                metric: $metric,
                translator: $this->translator,
                enableLastXYearsByDefault: $enableLastXYearsByDefault,
            )->build();

            if ([] === $chartData) {
                continue;
            }

            $monthlyWellnessCharts[$metric->value] = Json::encode($chartData);
            $monthlyWellnessMetricTabs[] = [
                'key' => $metric->value,
                'label' => $metric->getLabel(),
            ];
        }

        /** @var string[] $metricsDisplayOrder */
        $metricsDisplayOrder = $configuration->get('metricsDisplayOrder');

        return $this->twig->load('html/dashboard/widget/widget--monthly-stats.html.twig')->render([
            'monthlyStatsChartsPerContext' => $monthlyStatChartsPerContext,
            'monthlyWellnessCharts' => $monthlyWellnessCharts,
            'monthlyWellnessMetricTabs' => $monthlyWellnessMetricTabs,
            'metricsDisplayOrder' => array_map(
                StatsContext::from(...),
                $metricsDisplayOrder,
            ),
        ]);
    }
}
