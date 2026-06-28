<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner\Prediction;

final readonly class RunningPlanConfidenceFactor
{
    private string $key;
    private string $label;
    private int $score;
    private string $reason;
    private float $weight;

    public function __construct(
        string $key,
        string $label,
        int $score,
        string $reason,
        float $weight = 1.0,
    ) {
        $this->key = trim($key);
        $this->label = trim($label);
        $this->score = max(0, min(100, $score));
        $this->reason = trim($reason);
        $this->weight = max(0.0, $weight);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getWeight(): float
    {
        return $this->weight;
    }

    public function getWeightedScore(): float
    {
        return $this->score * $this->weight;
    }
}
