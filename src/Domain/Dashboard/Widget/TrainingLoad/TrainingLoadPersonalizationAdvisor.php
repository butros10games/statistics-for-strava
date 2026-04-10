<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

use App\Domain\Activity\ActivityType;

final class TrainingLoadPersonalizationAdvisor
{
    /**
     * @param list<TrainingLoadCorrelationInsight> $correlationInsights
     * @param list<ActivityTypeRecoveryFingerprint> $activityTypeRecoveryFingerprints
     * @param list<array{day: string, activityType: ActivityType, load: int, nextDayHrv: ?float, nextDaySleepScore: ?int, nextDayFatigue: ?int}> $recentActivityDaySamples
     */
    public function build(TrainingLoadAnalyticsContext $analyticsContext, array $correlationInsights, array $activityTypeRecoveryFingerprints, array $recentActivityDaySamples): TrainingLoadPersonalization
    {
        $readinessAdjustment = 0;
        $forecastLoadFactor = 1.0;
        $messages = [];

        $fingerprintsByType = [];
        foreach ($activityTypeRecoveryFingerprints as $fingerprint) {
            $fingerprintsByType[$fingerprint->getActivityType()->value] = $fingerprint;
        }

        $recentTypeLoads = [];
        foreach ($recentActivityDaySamples as $sample) {
            $recentTypeLoads[$sample['activityType']->value] = ($recentTypeLoads[$sample['activityType']->value] ?? 0) + $sample['load'];
        }

        if ([] !== $recentTypeLoads) {
            arsort($recentTypeLoads);
            $primaryRecentType = ActivityType::from((string) array_key_first($recentTypeLoads));
            $primaryFingerprint = $fingerprintsByType[$primaryRecentType->value] ?? null;

            if ($primaryFingerprint instanceof ActivityTypeRecoveryFingerprint && $primaryFingerprint->getSampleSize() >= 4) {
                match ($primaryFingerprint->getProfile()) {
                    ActivityTypeRecoveryFingerprintProfile::NEEDS_BUFFER => [
                        $readinessAdjustment -= 4,
                        $forecastLoadFactor += 0.08,
                        $messages[] = sprintf('%s usually asks for a bigger recovery buffer for you.', $primaryFingerprint->getActivityType()->trans(new class implements \Symfony\Contracts\Translation\TranslatorInterface {
                            public function setLocale(string $locale): void {}
                            public function getLocale(): string { return 'en'; }
                            public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string { return (string) $id; }
                        })),
                    ],
                    ActivityTypeRecoveryFingerprintProfile::BOUNCES_BACK => [
                        $readinessAdjustment += 3,
                        $forecastLoadFactor -= 0.05,
                        $messages[] = sprintf('%s tends to land well for you the next day.', $primaryFingerprint->getActivityType()->trans(new class implements \Symfony\Contracts\Translation\TranslatorInterface {
                            public function setLocale(string $locale): void {}
                            public function getLocale(): string { return 'en'; }
                            public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string { return (string) $id; }
                        })),
                    ],
                    default => null,
                };
            }
        }

        $last7AverageLoad = $this->averageNumericField($analyticsContext->getLastRows(7), 'load');
        $previous7AverageLoad = $this->averageNumericField($analyticsContext->getRowsBeforeLast(7, 7), 'load');
        $latestRow = $analyticsContext->getLatestRow();
        $currentMonotony = $latestRow['monotony'] ?? null;

        foreach ($correlationInsights as $insight) {
            if (abs($insight->getCorrelation()) < 0.45) {
                continue;
            }

            if (in_array($insight->getKey(), ['loadToNextDayHrv', 'loadToNextDaySleepScore'], true) && $last7AverageLoad >= $previous7AverageLoad) {
                $readinessAdjustment -= 2;
                $forecastLoadFactor += 0.04;
                $messages[] = 'Your recent load is tightly linked to next-day recovery signals.';

                continue;
            }

            if ('monotonyToNextDayFatigue' === $insight->getKey() && null !== $currentMonotony && $currentMonotony >= 1.6) {
                $readinessAdjustment -= 1;
                $forecastLoadFactor += 0.02;
                $messages[] = 'Recent monotony has been showing up in your next-day fatigue.';
            }
        }

        $readinessAdjustment = (int) max(-6, min(6, $readinessAdjustment));
        $forecastLoadFactor = max(0.92, min(1.15, round($forecastLoadFactor, 2)));

        if (0 === $readinessAdjustment && abs($forecastLoadFactor - 1.0) < 0.01) {
            return TrainingLoadPersonalization::neutral();
        }

        return TrainingLoadPersonalization::fromAdjustment(
            readinessAdjustment: $readinessAdjustment,
            forecastLoadFactor: $forecastLoadFactor,
            headline: 'Personalized recovery lens',
            summary: implode(' ', array_unique($messages)),
        );
    }

    /**
     * @param list<array{day: string, load: int}> $rows
     */
    private function averageNumericField(array $rows, string $field): float
    {
        if ([] === $rows) {
            return 0.0;
        }

        return array_sum(array_map(static fn (array $row): int|float => $row[$field], $rows)) / count($rows);
    }
}