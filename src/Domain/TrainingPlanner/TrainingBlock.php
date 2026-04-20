<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Auth\AppUserId;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'TrainingBlock')]
#[ORM\Index(name: 'TrainingBlock_startDay', columns: ['startDay'])]
#[ORM\Index(name: 'TrainingBlock_endDay', columns: ['endDay'])]
final readonly class TrainingBlock
{
    private function __construct(
        #[ORM\Id, ORM\Column(type: 'string', unique: true)]
        private TrainingBlockId $trainingBlockId,
        #[ORM\Column(type: 'string', nullable: true)]
        private ?AppUserId $ownerUserId,
        #[ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $startDay,
        #[ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $endDay,
        #[ORM\Column(type: 'string', nullable: true)]
        private ?RaceEventId $targetRaceEventId,
        #[ORM\Column(type: 'string')]
        private TrainingBlockPhase $phase,
        #[ORM\Column(type: 'string', nullable: true)]
        private ?string $title,
        #[ORM\Column(type: 'string', nullable: true)]
        private ?string $focus,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $notes,
        #[ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $createdAt,
        #[ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $updatedAt,
    ) {
    }

    public static function create(
        TrainingBlockId $trainingBlockId,
        SerializableDateTime $startDay,
        SerializableDateTime $endDay,
        ?RaceEventId $targetRaceEventId,
        TrainingBlockPhase $phase,
        ?string $title,
        ?string $focus,
        ?string $notes,
        SerializableDateTime $createdAt,
        SerializableDateTime $updatedAt,
        ?AppUserId $ownerUserId = null,
    ): self {
        $normalizedStartDay = $startDay->setTime(0, 0);
        $normalizedEndDay = $endDay->setTime(0, 0);
        if ($normalizedEndDay < $normalizedStartDay) {
            $normalizedEndDay = $normalizedStartDay;
        }

        return new self(
            trainingBlockId: $trainingBlockId,
            ownerUserId: $ownerUserId,
            startDay: $normalizedStartDay,
            endDay: $normalizedEndDay,
            targetRaceEventId: $targetRaceEventId,
            phase: $phase,
            title: self::normalizeNullableString($title),
            focus: self::normalizeNullableString($focus),
            notes: self::normalizeNullableString($notes),
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }

    public function getId(): TrainingBlockId
    {
        return $this->trainingBlockId;
    }

    public function getOwnerUserId(): ?AppUserId
    {
        return $this->ownerUserId;
    }

    public function getStartDay(): SerializableDateTime
    {
        return $this->startDay;
    }

    public function getEndDay(): SerializableDateTime
    {
        return $this->endDay;
    }

    public function getTargetRaceEventId(): ?RaceEventId
    {
        return $this->targetRaceEventId;
    }

    public function getPhase(): TrainingBlockPhase
    {
        return $this->phase;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getFocus(): ?string
    {
        return $this->focus;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getCreatedAt(): SerializableDateTime
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): SerializableDateTime
    {
        return $this->updatedAt;
    }

    public function containsDay(SerializableDateTime $day): bool
    {
        $day = $day->setTime(0, 0);

        return $day >= $this->startDay && $day <= $this->endDay;
    }

    public function getDurationInDays(): int
    {
        return ((int) $this->startDay->diff($this->endDay)->format('%a')) + 1;
    }

    private static function normalizeNullableString(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $normalized = trim($value);

        return '' === $normalized ? null : $normalized;
    }
}
