<?php

declare(strict_types=1);

namespace App\Domain\Wellness;

use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'DailyRecoveryCheckIn')]
#[ORM\Index(name: 'DailyRecoveryCheckIn_day', columns: ['day'])]
final readonly class DailyRecoveryCheckIn
{
    private function __construct(
        #[ORM\Id, ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $day,
        #[ORM\Column(type: 'smallint')]
        private int $fatigue,
        #[ORM\Column(type: 'smallint')]
        private int $soreness,
        #[ORM\Column(type: 'smallint')]
        private int $stress,
        #[ORM\Column(type: 'smallint')]
        private int $motivation,
        #[ORM\Column(type: 'smallint')]
        private int $sleepQuality,
        #[ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $recordedAt,
    ) {
    }

    public static function create(
        SerializableDateTime $day,
        int $fatigue,
        int $soreness,
        int $stress,
        int $motivation,
        int $sleepQuality,
        SerializableDateTime $recordedAt,
    ): self {
        return new self(
            day: $day->setTime(0, 0),
            fatigue: self::normalizeScore($fatigue),
            soreness: self::normalizeScore($soreness),
            stress: self::normalizeScore($stress),
            motivation: self::normalizeScore($motivation),
            sleepQuality: self::normalizeScore($sleepQuality),
            recordedAt: $recordedAt,
        );
    }

    public function getDay(): SerializableDateTime
    {
        return $this->day;
    }

    public function getFatigue(): int
    {
        return $this->fatigue;
    }

    public function getSoreness(): int
    {
        return $this->soreness;
    }

    public function getStress(): int
    {
        return $this->stress;
    }

    public function getMotivation(): int
    {
        return $this->motivation;
    }

    public function getSleepQuality(): int
    {
        return $this->sleepQuality;
    }

    public function getRecordedAt(): SerializableDateTime
    {
        return $this->recordedAt;
    }

    private static function normalizeScore(int $value): int
    {
        return max(1, min($value, 5));
    }
}