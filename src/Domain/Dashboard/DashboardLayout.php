<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

final readonly class DashboardLayout implements \IteratorAggregate
{
    private function __construct(
        /** @var list<array{widget: string, width: int, enabled: bool}> */
        private array $config,
    ) {
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->config);
    }

    /**
     * @return list<array{widget: string, width: int, enabled: bool}>
     */
    public static function default(): array
    {
        return [
            // ── Training & Recovery ──
            ['widget' => 'trainingLoad', 'width' => 100, 'enabled' => true, 'section' => 'Training & Recovery'],
            ['widget' => 'wellness', 'width' => 50, 'enabled' => false, 'section' => 'Training & Recovery'],
            ['widget' => 'trainingGoals', 'width' => 50, 'enabled' => false, 'section' => 'Training & Recovery', 'config' => ['goals' => []]],

            // ── Recent Activity ──
            ['widget' => 'mostRecentActivities', 'width' => 66, 'enabled' => true, 'section' => 'Recent Activity', 'config' => ['numberOfActivitiesToDisplay' => 5]],
            ['widget' => 'streaks', 'width' => 33, 'enabled' => true, 'section' => 'Recent Activity', 'config' => ['subtitle' => null, 'sportTypesToInclude' => []]],
            ['widget' => 'weeklyStats', 'width' => 100, 'enabled' => true, 'section' => 'Recent Activity', 'config' => ['metricsDisplayOrder' => ['distance', 'movingTime', 'elevation']]],
            ['widget' => 'activityGrid', 'width' => 100, 'enabled' => true, 'section' => 'Recent Activity'],

            // ── Performance ──
            ['widget' => 'athleteProfile', 'width' => 33, 'enabled' => true, 'section' => 'Performance'],
            ['widget' => 'peakPowerOutputs', 'width' => 66, 'enabled' => true, 'section' => 'Performance'],
            ['widget' => 'heartRateZones', 'width' => 50, 'enabled' => true, 'section' => 'Performance'],
            ['widget' => 'eddington', 'width' => 50, 'enabled' => true, 'section' => 'Performance'],
            ['widget' => 'ftpHistory', 'width' => 100, 'enabled' => true, 'section' => 'Performance'],

            // ── Analytics ──
            ['widget' => 'monthlyStats', 'width' => 66, 'enabled' => true, 'section' => 'Analytics', 'config' => [
                'enableLastXYearsByDefault' => 10, 'metricsDisplayOrder' => ['distance', 'movingTime', 'elevation'],
            ]],
            ['widget' => 'mostRecentMilestones', 'width' => 33, 'enabled' => true, 'section' => 'Analytics', 'config' => ['numberOfMilestonesToDisplay' => 5]],
            ['widget' => 'weekdayStats', 'width' => 50, 'enabled' => true, 'section' => 'Analytics'],
            ['widget' => 'dayTimeStats', 'width' => 50, 'enabled' => true, 'section' => 'Analytics'],
            ['widget' => 'distanceBreakdown', 'width' => 50, 'enabled' => true, 'section' => 'Analytics'],
            ['widget' => 'yearlyStats', 'width' => 100, 'enabled' => true, 'section' => 'Analytics', 'config' => ['enableLastXYearsByDefault' => 10, 'metricsDisplayOrder' => ['distance', 'movingTime', 'elevation']]],
            ['widget' => 'athleteWeightHistory', 'width' => 50, 'enabled' => true, 'section' => 'Analytics'],

            // ── Gear & Challenges ──
            ['widget' => 'gearStats', 'width' => 50, 'enabled' => true, 'section' => 'Gear & Challenges', 'config' => ['includeRetiredGear' => true]],
            ['widget' => 'mostRecentChallengesCompleted', 'width' => 50, 'enabled' => true, 'section' => 'Gear & Challenges', 'config' => ['numberOfChallengesToDisplay' => 5]],
            ['widget' => 'challengeConsistency', 'width' => 50, 'enabled' => false, 'section' => 'Gear & Challenges', 'config' => ['challenges' => []]],
            ['widget' => 'zwiftStats', 'width' => 50, 'enabled' => false, 'section' => 'Gear & Challenges'],
            ['widget' => 'introText', 'width' => 33, 'enabled' => false],
        ];
    }

    /**
     * @param array<int, mixed> $config |null
     */
    public static function fromArray(
        ?array $config,
    ): self {
        if (null === $config || [] === $config) {
            $config = self::default();
        }

        foreach ($config as $widget) {
            foreach (['widget', 'width', 'enabled'] as $requiredKey) {
                if (array_key_exists($requiredKey, $widget)) {
                    continue;
                }
                throw new InvalidDashboardLayout(sprintf('"%s" property is required for each dashboard widget', $requiredKey));
            }

            if (!is_bool($widget['enabled'])) {
                throw new InvalidDashboardLayout('"enabled" property must be a boolean');
            }

            if (!is_int($widget['width'])) {
                throw new InvalidDashboardLayout('"width" property must be a valid integer');
            }

            if (!in_array($widget['width'], [33, 50, 66, 100])) {
                throw new InvalidDashboardLayout(sprintf('"width" property must be one of [33, 50, 66, 100], found %s', $widget['width']));
            }

            if (array_key_exists('config', $widget)) {
                if (!is_array($widget['config'])) {
                    throw new InvalidDashboardLayout('"config" property must be an array');
                }
                foreach ($widget['config'] as $key => $value) {
                    if (is_null($value)) {
                        continue;
                    }
                    if (!is_int($value) && !is_string($value) && !is_float($value) && !is_bool($value) && !is_array($value)) {
                        throw new InvalidDashboardLayout(sprintf('Invalid type for config item "%s" in widget "%s". Expected int, string, float, bool or array.', $key, $widget['widget']));
                    }
                }
            }
        }

        return new self($config); // @phpstan-ignore argument.type
    }
}
