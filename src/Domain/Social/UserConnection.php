<?php

declare(strict_types=1);

namespace App\Domain\Social;

use App\Domain\Auth\AppUserId;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'UserConnection')]
final readonly class UserConnection
{
    private function __construct(
        #[ORM\Id, ORM\Column(type: 'string', unique: true)]
        private UserConnectionId $userConnectionId,
        #[ORM\Column(type: 'string')]
        private AppUserId $requesterUserId,
        #[ORM\Column(type: 'string')]
        private AppUserId $targetUserId,
        #[ORM\Column(type: 'string')]
        private UserConnectionType $type,
        #[ORM\Column(type: 'string')]
        private UserConnectionStatus $status,
        #[ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $createdAt,
        #[ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $updatedAt,
    ) {
    }

    public static function create(
        UserConnectionId $userConnectionId,
        AppUserId $requesterUserId,
        AppUserId $targetUserId,
        UserConnectionType $type,
        UserConnectionStatus $status,
        SerializableDateTime $createdAt,
        SerializableDateTime $updatedAt,
    ): self {
        return new self(
            userConnectionId: $userConnectionId,
            requesterUserId: $requesterUserId,
            targetUserId: $targetUserId,
            type: $type,
            status: $status,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }

    public function getId(): UserConnectionId
    {
        return $this->userConnectionId;
    }

    public function getRequesterUserId(): AppUserId
    {
        return $this->requesterUserId;
    }

    public function getTargetUserId(): AppUserId
    {
        return $this->targetUserId;
    }

    public function getType(): UserConnectionType
    {
        return $this->type;
    }

    public function getStatus(): UserConnectionStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): SerializableDateTime
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): SerializableDateTime
    {
        return $this->updatedAt;
    }

    public function accept(SerializableDateTime $updatedAt): self
    {
        return self::create(
            userConnectionId: $this->userConnectionId,
            requesterUserId: $this->requesterUserId,
            targetUserId: $this->targetUserId,
            type: $this->type,
            status: UserConnectionStatus::ACCEPTED,
            createdAt: $this->createdAt,
            updatedAt: $updatedAt,
        );
    }
}
