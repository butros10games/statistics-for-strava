<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\WeeklyStats;

use App\Domain\Activity\ActivityType;
use App\Domain\Activity\EnrichedActivities;
use App\Domain\Calendar\Weeks;
use App\Domain\Dashboard\InvalidDashboardLayout;
use App\Domain\Dashboard\StatsContext;
use App\Domain\Dashboard\Widget\Wellness\FindWellnessMetrics\FindWellnessMetrics;
use App\Domain\Dashboard\Widget\Wellness\FindWellnessMetrics\FindWellnessMetricsResponse;
use App\Domain\Dashboard\Widget\Wellness\WeeklyWellnessStatsChart;
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

final readonly class WeeklyStatsWidget implements Widget
{
    public function __construct(
        private EnrichedActivities $enrichedActivities,
        private QueryBus $queryBus,
        private UnitSystem $unitSystem,
        private Environment $twig,
        private TranslatorInterface $translator,
    ) {
    }

    public function getDefaultConfiguration(): WidgetConfiguration
    {
        return WidgetConfiguration::empty()
            ->add('metricsDisplayOrder', array_map(fn (StatsContext $context) => $context->value, StatsContext::defaultSortingOrder()));
    }

    public function guardValidConfiguration(WidgetConfiguration $configuration): void
    {
        if (!$configuration->exists('metricsDisplayOrder')) {
            throw new InvalidDashboardLayout('Configuration item "metricsDisplayOrder" is required for WeeklyStatsWidget.');
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
        $weeklyDistanceTimeCharts = $weeksPerActivityType = [];
        $activitiesPerActivityType = $this->enrichedActivities->findGroupedByActivityType();
        $wellnessWeeklyCharts = [];
        $weeklyWellnessMetricTabs = [];

        /** @var string[] $metricsDisplayOrder */
        $metricsDisplayOrder = $configuration->get('metricsDisplayOrder');

        foreach ($activitiesPerActivityType as $activityType => $activities) {
            if ($activities->isEmpty()) {
                continue; // @codeCoverageIgnore
            }

            $activityType = ActivityType::from($activityType);
            $weeks = Weeks::create(
                startDate: $activities->getFirstActivityStartDate(),
                now: $now
            );

            $weeksPerActivityType[$activityType->value] = Json::encode([
                'weeks' => $weeks,
                'sportTypes' => $activityType->getSportTypes(),
            ]);

            if ($activityType->supportsWeeklyStats() && $chartData = WeeklyStatsChart::create(
                activities: $activitiesPerActivityType[$activityType->value],
                unitSystem: $this->unitSystem,
                activityType: $activityType,
                metricsDisplayOrder: array_map(
                    StatsContext::from(...),
                    $metricsDisplayOrder,
                ),
                now: $now,
                translator: $this->translator,
            )->build()) {
                $weeklyDistanceTimeCharts[$activityType->value] = Json::encode($chartData);
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
            $chartData = WeeklyWellnessStatsChart::create(
                records: $wellnessMetrics->getRecords(),
                metric: $metric,
                now: $now,
                translator: $this->translator,
            )->build();

            if ([] === $chartData) {
                continue;
            }

            $wellnessWeeklyCharts[$metric->value] = Json::encode($chartData);
            $weeklyWellnessMetricTabs[] = [
                'key' => $metric->value,
                'label' => $metric->getLabel(),
            ];
        }

        return $this->twig->load('html/dashboard/widget/widget--weekly-stats.html.twig')->render([
            'weeklyDistanceCharts' => $weeklyDistanceTimeCharts,
            'weeksPerActivityType' => $weeksPerActivityType,
            'weeklyWellnessCharts' => $wellnessWeeklyCharts,
            'weeklyWellnessMetricTabs' => $weeklyWellnessMetricTabs,
        ]);
    }
}
