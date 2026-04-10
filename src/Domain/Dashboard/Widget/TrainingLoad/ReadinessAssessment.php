<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

final readonly class ReadinessAssessment
{
    /**
     * @param list<ReadinessFactor> $factors
     */
    private function __construct(
        private ReadinessScore $score,
        private array $factors,
    ) {
    }

    /**
     * @param list<ReadinessFactor> $factors
     */
    public static function fromFactors(int $score, array $factors): self
    {
        return new self(
            score: ReadinessScore::of($score),
            factors: $factors,
        );
    }

    public function getScore(): ReadinessScore
    {
        return $this->score;
    }

    /**
     * @return list<ReadinessFactor>
     */
    public function getFactors(): array
    {
        return $this->factors;
    }

    public function withScore(ReadinessScore $score): self
    {
        return new self(
            score: $score,
            factors: $this->factors,
        );
    }

    public function withFactor(ReadinessFactor $factor): self
    {
        $factors = $this->factors;
        $factors[] = $factor;

        return new self(
            score: $this->score,
            factors: $factors,
        );
    }

    /**
     * @return list<ReadinessFactor>
     */
    public function getTopPositiveFactors(int $limit = 2): array
    {
        $factors = array_values(array_filter(
            $this->factors,
            static fn (ReadinessFactor $factor): bool => $factor->isPositive(),
        ));

        usort(
            $factors,
            static fn (ReadinessFactor $left, ReadinessFactor $right): int => $right->getValue() <=> $left->getValue(),
        );

        return array_slice($factors, 0, $limit);
    }

    /**
     * @return list<ReadinessFactor>
     */
    public function getTopNegativeFactors(int $limit = 2): array
    {
        $factors = array_values(array_filter(
            $this->factors,
            static fn (ReadinessFactor $factor): bool => $factor->isNegative(),
        ));

        usort(
            $factors,
            static fn (ReadinessFactor $left, ReadinessFactor $right): int => $left->getValue() <=> $right->getValue(),
        );

        return array_slice($factors, 0, $limit);
    }

    /**
     * @param list<string> $keys
     */
    public function sumFactors(array $keys): float
    {
        $sum = 0.0;

        foreach ($this->factors as $factor) {
            if (in_array($factor->getKey(), $keys, true)) {
                $sum += $factor->getValue();
            }
        }

        return $sum;
    }
}
