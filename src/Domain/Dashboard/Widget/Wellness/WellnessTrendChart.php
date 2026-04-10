<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\Wellness;

use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class WellnessTrendChart
{
    /**
     * @param list<string> $labels
     * @param list<int|float|null> $values
     */
    private function __construct(
        private string $title,
        private array $labels,
        private array $values,
        private string $color,
        private string $unit,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<string> $labels
     * @param list<int|float|null> $values
     */
    public static function create(
        string $title,
        array $labels,
        array $values,
        string $color,
        string $unit,
        TranslatorInterface $translator,
    ): self {
        return new self(
            title: $title,
            labels: $labels,
            values: $values,
            color: $color,
            unit: $unit,
            translator: $translator,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $numericValues = array_values(array_filter($this->values, static fn (int|float|null $value): bool => null !== $value));
        $minValue = [] === $numericValues ? 0 : min($numericValues);

        return [
            'animation' => true,
            'backgroundColor' => null,
            'tooltip' => [
                'trigger' => 'axis',
            ],
            'grid' => [
                'top' => 8,
                'left' => 8,
                'right' => 8,
                'bottom' => 20,
                'containLabel' => true,
            ],
            'xAxis' => [
                [
                    'type' => 'category',
                    'boundaryGap' => false,
                    'data' => $this->labels,
                    'axisTick' => ['show' => false],
                    'axisLine' => ['show' => false],
                    'axisLabel' => [
                        'fontSize' => 10,
                        'color' => '#6B7280',
                    ],
                ],
            ],
            'yAxis' => [
                [
                    'type' => 'value',
                    'min' => is_numeric($minValue) ? floor(((float) $minValue) * 0.9) : 0,
                    'splitLine' => ['show' => false],
                    'axisTick' => ['show' => false],
                    'axisLine' => ['show' => false],
                    'axisLabel' => ['show' => false],
                ],
            ],
            'series' => [
                [
                    'name' => $this->translator->trans($this->title),
                    'type' => 'line',
                    'smooth' => true,
                    'showSymbol' => false,
                    'data' => $this->values,
                    'lineStyle' => [
                        'width' => 2,
                        'color' => $this->color,
                    ],
                    'areaStyle' => [
                        'opacity' => 0.12,
                        'color' => $this->color,
                    ],
                    'itemStyle' => [
                        'color' => $this->color,
                    ],
                ],
            ],
        ];
    }
}