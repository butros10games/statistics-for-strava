<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

final readonly class ReadinessFactor
{
    public const string KEY_BASELINE = 'baseline';
    public const string KEY_HRV = 'hrv';
    public const string KEY_SLEEP_DURATION = 'sleepDuration';
    public const string KEY_SLEEP_SCORE = 'sleepScore';
    public const string KEY_STEPS = 'steps';
    public const string KEY_TSB = 'tsb';
    public const string KEY_AC_RATIO = 'acRatio';
    public const string KEY_MONOTONY = 'monotony';
    public const string KEY_RECOVERY_CHECK_IN = 'recoveryCheckIn';
    public const string KEY_PERSONALIZATION = 'personalization';

    private function __construct(
        private string $key,
        private string $label,
        private float $value,
        private bool $highlightable,
    ) {
    }

    public static function create(string $key, string $label, float $value, bool $highlightable = true): self
    {
        return new self(
            key: $key,
            label: $label,
            value: $value,
            highlightable: $highlightable,
        );
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getValue(): float
    {
        return $this->value;
    }

    public function isHighlightable(): bool
    {
        return $this->highlightable;
    }

    public function isPositive(float $threshold = 0.5): bool
    {
        return $this->highlightable && $this->value >= $threshold;
    }

    public function isNegative(float $threshold = 0.5): bool
    {
        return $this->highlightable && $this->value <= -$threshold;
    }
}
