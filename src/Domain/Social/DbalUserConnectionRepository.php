<?php

declare(strict_types=1);

namespace App\Domain\Social;

use App\Domain\Auth\AppUserId;
use App\Infrastructure\Repository\DbalRepository;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class DbalUserConnectionRepository extends DbalRepository implements UserConnectionRepository
{
    public function save(UserConnection $userConnection): void
    {
        $sql = 'INSERT INTO UserConnection (
                    userConnectionId, requesterUserId, targetUserId, type, status, createdAt, updatedAt
                ) VALUES (
                    :userConnectionId, :requesterUserId, :targetUserId, :type, :status, :createdAt, :updatedAt
                )
                ON CONFLICT(`userConnectionId`) DO UPDATE SET
                    requesterUserId = excluded.requesterUserId,
                    targetUserId = excluded.targetUserId,
                    type = excluded.type,
                    status = excluded.status,
                    createdAt = excluded.createdAt,
                    updatedAt = excluded.updatedAt';

        $this->connection->executeStatement($sql, [
            'userConnectionId' => (string) $userConnection->getId(),
            'requesterUserId' => (string) $userConnection->getRequesterUserId(),
            'targetUserId' => (string) $userConnection->getTargetUserId(),
            'type' => $userConnection->getType()->value,
            'status' => $userConnection->getStatus()->value,
            'createdAt' => $userConnection->getCreatedAt(),
            'updatedAt' => $userConnection->getUpdatedAt(),
        ]);
    }

    public function findAccepted(AppUserId $requesterUserId, AppUserId $targetUserId, UserConnectionType $type): ?UserConnection
    {
        $result = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('UserConnection')
            ->andWhere('requesterUserId = :requesterUserId')
            ->andWhere('targetUserId = :targetUserId')
            ->andWhere('type = :type')
            ->andWhere('status = :status')
            ->setParameter('requesterUserId', (string) $requesterUserId)
            ->setParameter('targetUserId', (string) $targetUserId)
            ->setParameter('type', $type->value)
            ->setParameter('status', UserConnectionStatus::ACCEPTED->value)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return false === $result ? null : $this->hydrate($result);
    }

    public function areFriends(AppUserId $leftUserId, AppUserId $rightUserId): bool
    {
        return $this->hasAcceptedConnection($leftUserId, $rightUserId, UserConnectionType::FRIEND)
            || $this->hasAcceptedConnection($rightUserId, $leftUserId, UserConnectionType::FRIEND);
    }

    public function isFollower(AppUserId $followerUserId, AppUserId $targetUserId): bool
    {
        return $this->hasAcceptedConnection($followerUserId, $targetUserId, UserConnectionType::FOLLOW);
    }

    private function hasAcceptedConnection(AppUserId $requesterUserId, AppUserId $targetUserId, UserConnectionType $type): bool
    {
        return false !== $this->connection->createQueryBuilder()
            ->select('userConnectionId')
            ->from('UserConnection')
            ->andWhere('requesterUserId = :requesterUserId')
            ->andWhere('targetUserId = :targetUserId')
            ->andWhere('type = :type')
            ->andWhere('status = :status')
            ->setParameter('requesterUserId', (string) $requesterUserId)
            ->setParameter('targetUserId', (string) $targetUserId)
            ->setParameter('type', $type->value)
            ->setParameter('status', UserConnectionStatus::ACCEPTED->value)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @param array<string, mixed> $result
     */
    private function hydrate(array $result): UserConnection
    {
        return UserConnection::create(
            userConnectionId: UserConnectionId::fromString((string) $result['userConnectionId']),
            requesterUserId: AppUserId::fromString((string) $result['requesterUserId']),
            targetUserId: AppUserId::fromString((string) $result['targetUserId']),
            type: UserConnectionType::from((string) $result['type']),
            status: UserConnectionStatus::from((string) $result['status']),
            createdAt: SerializableDateTime::fromString((string) $result['createdAt']),
            updatedAt: SerializableDateTime::fromString((string) $result['updatedAt']),
        );
    }
}
