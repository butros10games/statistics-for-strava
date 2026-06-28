<?php

declare(strict_types=1);

namespace App\Domain\Strava\Webhook;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(name: 'WebhookEvent_object_aspect', columns: ['objectType', 'objectId', 'aspectType'])]
#[ORM\Index(name: 'WebhookEvent_appUserId', columns: ['appUserId'])]
final readonly class WebhookEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(
        #[ORM\Id, ORM\Column(type: 'string', unique: true)]
        private string $eventId,
        #[ORM\Column(type: 'string')]
        private string $objectId,
        #[ORM\Column(type: 'string')]
        private string $objectType,
        #[ORM\Column(type: 'string')]
        private WebhookAspectType $aspectType,
        #[ORM\Column(type: 'string', nullable: true)]
        private ?string $ownerAthleteId,
        #[ORM\Column(type: 'string', nullable: true)]
        private ?string $appUserId,
        #[ORM\Column(type: 'json')]
        private array $payload,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function create(
        string $objectId,
        string $objectType,
        WebhookAspectType $aspectType,
        array $payload,
        ?string $ownerAthleteId = null,
        ?string $appUserId = null,
    ): self {
        return new self(
            eventId: self::buildEventId(
                objectId: $objectId,
                objectType: $objectType,
                aspectType: $aspectType,
                ownerAthleteId: $ownerAthleteId,
                appUserId: $appUserId,
            ),
            objectId: $objectId,
            objectType: $objectType,
            aspectType: $aspectType,
            ownerAthleteId: $ownerAthleteId,
            appUserId: $appUserId,
            payload: $payload,
        );
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getObjectId(): string
    {
        return $this->objectId;
    }

    public function getObjectType(): string
    {
        return $this->objectType;
    }

    public function getAspectType(): WebhookAspectType
    {
        return $this->aspectType;
    }

    public function getOwnerAthleteId(): ?string
    {
        return $this->ownerAthleteId;
    }

    public function getAppUserId(): ?string
    {
        return $this->appUserId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    private static function buildEventId(
        string $objectId,
        string $objectType,
        WebhookAspectType $aspectType,
        ?string $ownerAthleteId,
        ?string $appUserId,
    ): string {
        return implode(':', [
            trim($objectType),
            trim($objectId),
            $aspectType->value,
            null === $appUserId ? 'global' : trim($appUserId),
            null === $ownerAthleteId ? 'unknown-owner' : trim($ownerAthleteId),
        ]);
    }
}
