<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

final readonly class ReadinessScore
{
    private ReadinessStatus $status;

    private function __construct(
        private int $value,
    ) {
        $this->status = ReadinessStatus::fromScore($this->value);
    }

    public static function of(int $value): self
    {
        return new self($value);
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function getStatus(): ReadinessStatus
    {
        return $this->status;
    }
}