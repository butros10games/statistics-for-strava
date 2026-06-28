<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

final readonly class DailyTrainingGuidance
{
    private const string TONE_GOOD = 'good';
    private const string TONE_NEUTRAL = 'neutral';
    private const string TONE_CAUTION = 'caution';
    private const string TONE_DANGER = 'danger';

    /**
     * @param list<array{label: string, value: string, tone: string}> $signals
     */
    private function __construct(
        private string $title,
        private string $summary,
        private string $tone,
        private array $signals,
    ) {
    }

    /**
     * @param list<RecoveryTrendWarningType> $recoveryTrendWarnings
     */
    public static function fromSignals(
        ?ReadinessScore $readinessScore,
        TrainingMetrics $trainingMetrics,
        int $restDaysInLast7Days,
        array $recoveryTrendWarnings,
    ): self {
        $currentTsb = $trainingMetrics->getCurrentTsb();
        $currentAcRatio = $trainingMetrics->getCurrentAcRatio();
        $currentMonotony = $trainingMetrics->getCurrentMonotony();
        $hasLoadWarning = array_any(
            $recoveryTrendWarnings,
            static fn (RecoveryTrendWarningType $warning): bool => RecoveryTrendWarningType::RECOVERY_REBOUND !== $warning,
        );

        $signals = self::buildSignals(
            readinessScore: $readinessScore,
            currentTsb: $currentTsb,
            currentAcRatio: $currentAcRatio,
            restDaysInLast7Days: $restDaysInLast7Days,
            recoveryTrendWarnings: $recoveryTrendWarnings,
        );

        if (!$readinessScore instanceof ReadinessScore) {
            return new self(
                title: 'Complete today\'s check-in',
                summary: 'Add a quick recovery check-in so load, sleep, HRV, and how you feel can become one usable recommendation.',
                tone: self::TONE_NEUTRAL,
                signals: $signals,
            );
        }

        if (
            ReadinessStatus::NEEDS_RECOVERY === $readinessScore->getStatus()
            || TSBStatus::OVER_FATIGUED === $currentTsb?->getStatus()
            || AcRatioStatus::HIGH_RISK === $currentAcRatio?->getStatus()
            || $hasLoadWarning
        ) {
            return new self(
                title: 'Keep today easy',
                summary: 'Recovery and load signals are asking for restraint. Prioritize rest, mobility, or an easy aerobic session before adding intensity.',
                tone: self::TONE_DANGER,
                signals: $signals,
            );
        }

        if (
            TSBStatus::POSSIBLE_DETRAINING === $currentTsb?->getStatus()
            || AcRatioStatus::LOW_TRAINING_LOAD === $currentAcRatio?->getStatus()
        ) {
            return new self(
                title: 'Rebuild momentum',
                summary: 'You look fresh because recent load is low. Choose a controlled aerobic session before chasing a hard workout.',
                tone: self::TONE_CAUTION,
                signals: $signals,
            );
        }

        if (
            0 === $restDaysInLast7Days
            && null !== $currentMonotony
            && $currentMonotony > 1.5
        ) {
            return new self(
                title: 'Add a recovery buffer',
                summary: 'The last week has been repetitive without a rest day. Keep the next session short or change the stimulus.',
                tone: self::TONE_CAUTION,
                signals: $signals,
            );
        }

        if (
            ReadinessStatus::READY_TO_GO === $readinessScore->getStatus()
            && in_array($currentTsb?->getStatus(), [TSBStatus::PEAK_FRESH, TSBStatus::SLIGHTLY_FRESH], true)
            && AcRatioStatus::LOW_RISK === $currentAcRatio?->getStatus()
        ) {
            return new self(
                title: 'Good day for quality',
                summary: 'Readiness, form, and load balance support a focused session. Keep the work aligned with your plan.',
                tone: self::TONE_GOOD,
                signals: $signals,
            );
        }

        return new self(
            title: 'Build steadily',
            summary: 'Signals look workable for normal training. Stay close to the plan and avoid adding unplanned intensity.',
            tone: self::TONE_NEUTRAL,
            signals: $signals,
        );
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function getTone(): string
    {
        return $this->tone;
    }

    /**
     * @return list<array{label: string, value: string, tone: string}>
     */
    public function getSignals(): array
    {
        return $this->signals;
    }

    /**
     * @param list<RecoveryTrendWarningType> $recoveryTrendWarnings
     *
     * @return list<array{label: string, value: string, tone: string}>
     */
    private static function buildSignals(
        ?ReadinessScore $readinessScore,
        ?TSB $currentTsb,
        ?AcRatio $currentAcRatio,
        int $restDaysInLast7Days,
        array $recoveryTrendWarnings,
    ): array {
        return [
            [
                'label' => 'Readiness',
                'value' => $readinessScore instanceof ReadinessScore ? $readinessScore->getValue().'/100' : 'Missing',
                'tone' => self::toneForReadiness($readinessScore),
            ],
            [
                'label' => 'Form',
                'value' => self::labelForTsb($currentTsb),
                'tone' => self::toneForTsb($currentTsb),
            ],
            [
                'label' => 'Load risk',
                'value' => self::labelForAcRatio($currentAcRatio),
                'tone' => self::toneForAcRatio($currentAcRatio),
            ],
            [
                'label' => 'Rest',
                'value' => sprintf('%d/7 days', $restDaysInLast7Days),
                'tone' => 0 === $restDaysInLast7Days ? self::TONE_CAUTION : self::TONE_NEUTRAL,
            ],
            [
                'label' => 'Trend',
                'value' => [] === $recoveryTrendWarnings ? 'No warnings' : sprintf('%d signal%s', count($recoveryTrendWarnings), 1 === count($recoveryTrendWarnings) ? '' : 's'),
                'tone' => self::toneForWarnings($recoveryTrendWarnings),
            ],
        ];
    }

    private static function toneForReadiness(?ReadinessScore $readinessScore): string
    {
        return match ($readinessScore?->getStatus()) {
            ReadinessStatus::READY_TO_GO => self::TONE_GOOD,
            ReadinessStatus::STABLE => self::TONE_NEUTRAL,
            ReadinessStatus::CAUTION => self::TONE_CAUTION,
            ReadinessStatus::NEEDS_RECOVERY => self::TONE_DANGER,
            default => self::TONE_NEUTRAL,
        };
    }

    private static function toneForTsb(?TSB $currentTsb): string
    {
        return match ($currentTsb?->getStatus()) {
            TSBStatus::PEAK_FRESH, TSBStatus::SLIGHTLY_FRESH, TSBStatus::NEUTRAL => self::TONE_GOOD,
            TSBStatus::POSSIBLE_DETRAINING, TSBStatus::ACCUMULATED_FATIGUE => self::TONE_CAUTION,
            TSBStatus::OVER_FATIGUED => self::TONE_DANGER,
            default => self::TONE_NEUTRAL,
        };
    }

    private static function labelForTsb(?TSB $currentTsb): string
    {
        return match ($currentTsb?->getStatus()) {
            TSBStatus::POSSIBLE_DETRAINING => 'Detraining risk',
            TSBStatus::PEAK_FRESH => 'Peak fresh',
            TSBStatus::SLIGHTLY_FRESH => 'Slightly fresh',
            TSBStatus::NEUTRAL => 'Neutral',
            TSBStatus::ACCUMULATED_FATIGUE => 'Fatigue',
            TSBStatus::OVER_FATIGUED => 'Over-fatigued',
            default => 'n/a',
        };
    }

    private static function toneForAcRatio(?AcRatio $currentAcRatio): string
    {
        return match ($currentAcRatio?->getStatus()) {
            AcRatioStatus::LOW_RISK => self::TONE_GOOD,
            AcRatioStatus::LOW_TRAINING_LOAD => self::TONE_CAUTION,
            AcRatioStatus::HIGH_RISK => self::TONE_DANGER,
            default => self::TONE_NEUTRAL,
        };
    }

    private static function labelForAcRatio(?AcRatio $currentAcRatio): string
    {
        return match ($currentAcRatio?->getStatus()) {
            AcRatioStatus::LOW_RISK => 'Low risk',
            AcRatioStatus::LOW_TRAINING_LOAD => 'Low load',
            AcRatioStatus::HIGH_RISK => 'High risk',
            default => 'n/a',
        };
    }

    /**
     * @param list<RecoveryTrendWarningType> $recoveryTrendWarnings
     */
    private static function toneForWarnings(array $recoveryTrendWarnings): string
    {
        if ([] === $recoveryTrendWarnings) {
            return self::TONE_GOOD;
        }

        if ([RecoveryTrendWarningType::RECOVERY_REBOUND] === $recoveryTrendWarnings) {
            return self::TONE_GOOD;
        }

        return self::TONE_CAUTION;
    }
}
