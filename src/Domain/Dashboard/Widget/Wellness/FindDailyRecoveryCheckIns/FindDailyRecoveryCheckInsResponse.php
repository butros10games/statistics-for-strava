<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\Wellness\FindDailyRecoveryCheckIns;

use App\Infrastructure\CQRS\Query\Response;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class FindDailyRecoveryCheckInsResponse implements Response
{
    /**
     * @param list<array{day: string, fatigue: int, soreness: int, stress: int, motivation: int, sleepQuality: int}> $records
     */
    public function __construct(
        private array $records,
        private ?SerializableDateTime $latestDay,
    ) {
    }

    /**
     * @return list<array{day: string, fatigue: int, soreness: int, stress: int, motivation: int, sleepQuality: int}>
     */
    public function getRecords(): array
    {
        return $this->records;
    }

    public function getLatestDay(): ?SerializableDateTime
    {
        return $this->latestDay;
    }

    public function isEmpty(): bool
    {
        return [] === $this->records;
    }

    /**
     * @return array{day: string, fatigue: int, soreness: int, stress: int, motivation: int, sleepQuality: int}|null
     */
    public function getLatestRecord(): ?array
    {
        if ($this->isEmpty()) {
            return null;
        }

        $lastRecordIndex = array_key_last($this->records);

        return null === $lastRecordIndex ? null : $this->records[$lastRecordIndex];
    }
}