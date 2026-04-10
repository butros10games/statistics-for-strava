<?php

declare(strict_types=1);

namespace App\Domain\Wellness;

use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'DailyWellness')]
#[ORM\Index(name: 'DailyWellness_day', columns: ['day'])]
final readonly class DailyWellness implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(
        #[ORM\Id, ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $day,
        #[ORM\Id, ORM\Column(type: 'string')]
        private WellnessSource $source,
        #[ORM\Column(type: 'integer', nullable: true)]
        private ?int $stepsCount,
        #[ORM\Column(type: 'integer', nullable: true)]
        private ?int $sleepDurationInSeconds,
        #[ORM\Column(type: 'integer', nullable: true)]
        private ?int $sleepScore,
        #[ORM\Column(type: 'float', nullable: true)]
        private ?float $hrv,
        #[ORM\Column(type: 'json')]
        private array $payload,
        #[ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $importedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function create(
        SerializableDateTime $day,
        WellnessSource $source,
        ?int $stepsCount,
        ?int $sleepDurationInSeconds,
        ?int $sleepScore,
        ?float $hrv,
        array $payload,
        SerializableDateTime $importedAt,
    ): self {
        return new self(
            day: $day->setTime(0, 0),
            source: $source,
            stepsCount: $stepsCount,
            sleepDurationInSeconds: $sleepDurationInSeconds,
            sleepScore: $sleepScore,
            hrv: $hrv,
            payload: $payload,
            importedAt: $importedAt,
        );
    }

    public function getDay(): SerializableDateTime
    {
        return $this->day;
    }

    public function getSource(): WellnessSource
    {
        return $this->source;
    }

    public function getStepsCount(): ?int
    {
        return $this->stepsCount;
    }

    public function getSleepDurationInSeconds(): ?int
    {
        return $this->sleepDurationInSeconds;
    }

    public function getSleepScore(): ?int
    {
        return $this->sleepScore;
    }

    public function getHrv(): ?float
    {
        return $this->hrv;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getImportedAt(): SerializableDateTime
    {
        return $this->importedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'day' => $this->day->format('Y-m-d'),
            'source' => $this->source->value,
            'stepsCount' => $this->stepsCount,
            'sleepDurationInSeconds' => $this->sleepDurationInSeconds,
            'sleepScore' => $this->sleepScore,
            'hrv' => $this->hrv,
            'payload' => $this->payload,
            'importedAt' => $this->importedAt->format('Y-m-d H:i:s'),
        ];
    }
}