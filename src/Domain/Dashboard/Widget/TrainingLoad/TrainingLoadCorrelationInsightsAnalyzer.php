<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

final class TrainingLoadCorrelationInsightsAnalyzer
{
    private const int MIN_SAMPLE_SIZE = 6;
    private const float MIN_ABSOLUTE_CORRELATION = 0.3;

    /**
     * @return list<TrainingLoadCorrelationInsight>
     */
    public function analyze(TrainingLoadAnalyticsContext $analyticsContext, int $window = 90): array
    {
        $rows = $analyticsContext->getLastRows($window);
        if (count($rows) < self::MIN_SAMPLE_SIZE + 1) {
            return [];
        }

        $insights = array_values(array_filter([
            $this->buildInsight(
                rows: $rows,
                key: 'loadToNextDayHrv',
                title: 'Higher-load days tend to pull next-day HRV down',
                summary: 'Across your recent training, bigger load days were often followed by lower next-day HRV.',
                xExtractor: static fn (array $current, array $next): ?float => (float) $current['load'],
                yExtractor: static fn (array $current, array $next): ?float => isset($next['wellness']['hrv']) ? (float) $next['wellness']['hrv'] : null,
                expectedSign: -1,
            ),
            $this->buildInsight(
                rows: $rows,
                key: 'loadToNextDaySleepScore',
                title: 'Load spikes often show up in next-day sleep scores',
                summary: 'Your higher-load days tended to be followed by lower next-day sleep scores.',
                xExtractor: static fn (array $current, array $next): ?float => (float) $current['load'],
                yExtractor: static fn (array $current, array $next): ?float => isset($next['wellness']['sleepScore']) ? (float) $next['wellness']['sleepScore'] : null,
                expectedSign: -1,
            ),
            $this->buildInsight(
                rows: $rows,
                key: 'monotonyToNextDayFatigue',
                title: 'Monotony tracks with next-day fatigue',
                summary: 'When your recent training became more repetitive, your next-day fatigue check-ins tended to rise.',
                xExtractor: static fn (array $current, array $next): ?float => null === $current['monotony'] ? null : (float) $current['monotony'],
                yExtractor: static fn (array $current, array $next): ?float => isset($next['recoveryCheckIn']['fatigue']) ? (float) $next['recoveryCheckIn']['fatigue'] : null,
                expectedSign: 1,
            ),
        ]));

        usort(
            $insights,
            static fn (TrainingLoadCorrelationInsight $left, TrainingLoadCorrelationInsight $right): int => abs($right->getCorrelation()) <=> abs($left->getCorrelation()),
        );

        return array_slice($insights, 0, 3);
    }

    /**
     * @param list<array{
     *     day: string,
     *     load: int,
     *     atl: float,
     *     ctl: float,
     *     tsb: float,
     *     acRatio: float,
     *     monotony: ?float,
     *     strain: ?int,
     *     wellness: array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}|null,
     *     recoveryCheckIn: array{day: string, fatigue: int, soreness: int, stress: int, motivation: int, sleepQuality: int}|null
     * }> $rows
     */
    private function buildInsight(array $rows, string $key, string $title, string $summary, callable $xExtractor, callable $yExtractor, int $expectedSign): ?TrainingLoadCorrelationInsight
    {
        $pairs = [];
        $numberOfRows = count($rows);

        for ($index = 0; $index < $numberOfRows - 1; ++$index) {
            $current = $rows[$index];
            $next = $rows[$index + 1];

            $x = $xExtractor($current, $next);
            $y = $yExtractor($current, $next);
            if (null === $x || null === $y) {
                continue;
            }

            $pairs[] = [$x, $y];
        }

        if (count($pairs) < self::MIN_SAMPLE_SIZE) {
            return null;
        }

        $correlation = $this->pearsonCorrelation($pairs);
        if (null === $correlation || abs($correlation) < self::MIN_ABSOLUTE_CORRELATION) {
            return null;
        }

        if (($expectedSign < 0 && $correlation > 0) || ($expectedSign > 0 && $correlation < 0)) {
            return null;
        }

        return new TrainingLoadCorrelationInsight(
            key: $key,
            title: $title,
            summary: $summary,
            correlation: round($correlation, 2),
            sampleSize: count($pairs),
        );
    }

    /**
     * @param list<array{0: float, 1: float}> $pairs
     */
    private function pearsonCorrelation(array $pairs): ?float
    {
        $xValues = array_column($pairs, 0);
        $yValues = array_column($pairs, 1);

        $sampleSize = count($pairs);
        if ($sampleSize < 2) {
            return null;
        }

        $meanX = array_sum($xValues) / $sampleSize;
        $meanY = array_sum($yValues) / $sampleSize;

        $numerator = 0.0;
        $sumSquaresX = 0.0;
        $sumSquaresY = 0.0;

        for ($index = 0; $index < $sampleSize; ++$index) {
            $deltaX = $xValues[$index] - $meanX;
            $deltaY = $yValues[$index] - $meanY;

            $numerator += $deltaX * $deltaY;
            $sumSquaresX += $deltaX ** 2;
            $sumSquaresY += $deltaY ** 2;
        }

        if (0.0 === $sumSquaresX || 0.0 === $sumSquaresY) {
            return null;
        }

        return $numerator / sqrt($sumSquaresX * $sumSquaresY);
    }
}