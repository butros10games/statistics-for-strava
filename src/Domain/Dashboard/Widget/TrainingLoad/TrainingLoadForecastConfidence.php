<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum TrainingLoadForecastConfidence: string implements TranslatableInterface
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';

    public static function fromScore(float $score): self
    {
        return match (true) {
            $score >= 68.0 => self::HIGH,
            $score >= 48.0 => self::MEDIUM,
            default => self::LOW,
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return match ($this) {
            self::HIGH => $translator->trans('Higher confidence', locale: $locale),
            self::MEDIUM => $translator->trans('Moderate confidence', locale: $locale),
            self::LOW => $translator->trans('Lower confidence', locale: $locale),
        };
    }

    public function transDescription(TranslatorInterface $translator, ?string $locale = null): string
    {
        return match ($this) {
            self::HIGH => $translator->trans('Recent load has been stable enough that this projection should be a stronger directional guide.', locale: $locale),
            self::MEDIUM => $translator->trans('Use this projection as a directional guide rather than a precise prediction.', locale: $locale),
            self::LOW => $translator->trans('Recent load has been volatile or sparse, so treat this projection as a rough scenario.', locale: $locale),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::HIGH => 'Recent load has been stable enough that this projection should be a stronger directional guide.',
            self::MEDIUM => 'Use this projection as a directional guide rather than a precise prediction.',
            self::LOW => 'Recent load has been volatile or sparse, so treat this projection as a rough scenario.',
        };
    }

    public function getPillColors(): string
    {
        return match ($this) {
            self::HIGH => 'bg-blue-100 text-blue-800',
            self::MEDIUM => 'bg-gray-100 text-gray-700',
            self::LOW => 'bg-amber-100 text-amber-800',
        };
    }
}