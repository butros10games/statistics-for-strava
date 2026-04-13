<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'RaceEvent')]
#[ORM\Index(name: 'RaceEvent_day', columns: ['day'])]
final readonly class RaceEvent
{
    private function __construct(
        #[ORM\Id, ORM\Column(type: 'string', unique: true)]
        private RaceEventId $raceEventId,
        #[ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $day,
        #[ORM\Column(type: 'string')]
        private RaceEventType $type,
        #[ORM\Column(type: 'string')]
        private RaceEventFamily $family,
        #[ORM\Column(type: 'string')]
        private RaceEventProfile $profile,
        #[ORM\Column(type: 'string', nullable: true)]
        private ?string $title,
        #[ORM\Column(type: 'string', nullable: true)]
        private ?string $location,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $notes,
        #[ORM\Column(type: 'string')]
        private RaceEventPriority $priority,
        #[ORM\Column(type: 'integer', nullable: true)]
        private ?int $targetFinishTimeInSeconds,
        #[ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $createdAt,
        #[ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $updatedAt,
    ) {
    }

    public static function create(
        RaceEventId $raceEventId,
        SerializableDateTime $day,
        RaceEventType $type,
        ?string $title,
        ?string $location,
        ?string $notes,
        RaceEventPriority $priority,
        ?int $targetFinishTimeInSeconds,
        SerializableDateTime $createdAt,
        SerializableDateTime $updatedAt,
    ): self {
        return self::createWithClassification(
            raceEventId: $raceEventId,
            day: $day,
            family: $type->toFamily(),
            profile: $type->toProfile(),
            title: $title,
            location: $location,
            notes: $notes,
            priority: $priority,
            targetFinishTimeInSeconds: $targetFinishTimeInSeconds,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }

    public static function createWithClassification(
        RaceEventId $raceEventId,
        SerializableDateTime $day,
        RaceEventFamily $family,
        RaceEventProfile $profile,
        ?string $title,
        ?string $location,
        ?string $notes,
        RaceEventPriority $priority,
        ?int $targetFinishTimeInSeconds,
        SerializableDateTime $createdAt,
        SerializableDateTime $updatedAt,
    ): self {
        $normalizedFamily = $profile->getFamily();

        return new self(
            raceEventId: $raceEventId,
            day: $day->setTime(0, 0),
            type: RaceEventType::fromProfile($profile),
            family: $normalizedFamily === $family ? $family : $normalizedFamily,
            profile: $profile,
            title: self::normalizeNullableString($title),
            location: self::normalizeNullableString($location),
            notes: self::normalizeNullableString($notes),
            priority: $priority,
            targetFinishTimeInSeconds: null === $targetFinishTimeInSeconds ? null : max(0, $targetFinishTimeInSeconds),
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }

    public function getId(): RaceEventId
    {
        return $this->raceEventId;
    }

    public function getDay(): SerializableDateTime
    {
        return $this->day;
    }

    public function getType(): RaceEventType
    {
        return $this->type;
    }

    public function getFamily(): RaceEventFamily
    {
        return $this->family;
    }

    public function getProfile(): RaceEventProfile
    {
        return $this->profile;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getPriority(): RaceEventPriority
    {
        return $this->priority;
    }

    public function getTargetFinishTimeInSeconds(): ?int
    {
        return $this->targetFinishTimeInSeconds;
    }

    public function getCreatedAt(): SerializableDateTime
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): SerializableDateTime
    {
        return $this->updatedAt;
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
