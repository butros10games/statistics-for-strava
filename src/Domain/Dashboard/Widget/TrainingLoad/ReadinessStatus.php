<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

use App\Infrastructure\Localisation\TranslatableWithDescription;
use Symfony\Contracts\Translation\TranslatorInterface;

enum ReadinessStatus implements TranslatableWithDescription
{
    case READY_TO_GO;
    case STABLE;
    case CAUTION;
    case NEEDS_RECOVERY;

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 75 => self::READY_TO_GO,
            $score >= 60 => self::STABLE,
            $score >= 45 => self::CAUTION,
            default => self::NEEDS_RECOVERY,
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return match ($this) {
            self::READY_TO_GO => $translator->trans('Ready to go', locale: $locale),
            self::STABLE => $translator->trans('Stable', locale: $locale),
            self::CAUTION => $translator->trans('Use caution', locale: $locale),
            self::NEEDS_RECOVERY => $translator->trans('Needs recovery', locale: $locale),
        };
    }

    public function transDescription(TranslatorInterface $translator, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return match ($this) {
            self::READY_TO_GO => $translator->trans('Sleep, HRV and load balance are pointing in a positive direction for quality training.', locale: $locale),
            self::STABLE => $translator->trans('Recovery signals look acceptable, but not fully exceptional. A normal training day is likely fine.', locale: $locale),
            self::CAUTION => $translator->trans('One or more wellness signals are below baseline. Consider reducing intensity or volume.', locale: $locale),
            self::NEEDS_RECOVERY => $translator->trans('Recovery signals are clearly suppressed. Prioritise sleep, recovery, and a lighter training day.', locale: $locale),
        };
    }

    public function getRange(): string
    {
        return match ($this) {
            self::READY_TO_GO => '75 to 100',
            self::STABLE => '60 to 74',
            self::CAUTION => '45 to 59',
            self::NEEDS_RECOVERY => '0 to 44',
        };
    }

    public function getTextColor(): string
    {
        return match ($this) {
            self::READY_TO_GO => 'text-green-600',
            self::STABLE => 'text-blue-600',
            self::CAUTION => 'text-yellow-600',
            self::NEEDS_RECOVERY => 'text-red-600',
        };
    }
}