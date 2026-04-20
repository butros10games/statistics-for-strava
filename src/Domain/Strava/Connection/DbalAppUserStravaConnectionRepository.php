<?php

declare(strict_types=1);

namespace App\Domain\Strava\Connection;

use App\Domain\Auth\AppUserId;
use App\Infrastructure\Repository\DbalRepository;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class DbalAppUserStravaConnectionRepository extends DbalRepository implements AppUserStravaConnectionRepository
{
    public function save(AppUserStravaConnection $connection): void
    {
        $sql = 'INSERT INTO AppUserStravaConnection (
                    appUserId, stravaAthleteId, refreshToken, scopes, accessTokenExpiresAt,
                    tokenRefreshedAt, webhookCorrelationKey, createdAt, updatedAt
                ) VALUES (
                    :appUserId, :stravaAthleteId, :refreshToken, :scopes, :accessTokenExpiresAt,
                    :tokenRefreshedAt, :webhookCorrelationKey, :createdAt, :updatedAt
                )
                ON CONFLICT(`appUserId`) DO UPDATE SET
                    stravaAthleteId = excluded.stravaAthleteId,
                    refreshToken = excluded.refreshToken,
                    scopes = excluded.scopes,
                    accessTokenExpiresAt = excluded.accessTokenExpiresAt,
                    tokenRefreshedAt = excluded.tokenRefreshedAt,
                    webhookCorrelationKey = excluded.webhookCorrelationKey,
                    createdAt = excluded.createdAt,
                    updatedAt = excluded.updatedAt';

        $this->connection->executeStatement($sql, [
            'appUserId' => (string) $connection->getAppUserId(),
            'stravaAthleteId' => $connection->getStravaAthleteId(),
            'refreshToken' => $connection->getRefreshToken(),
            'scopes' => json_encode($connection->getScopes(), JSON_THROW_ON_ERROR),
            'accessTokenExpiresAt' => $connection->getAccessTokenExpiresAt(),
            'tokenRefreshedAt' => $connection->getTokenRefreshedAt(),
            'webhookCorrelationKey' => $connection->getWebhookCorrelationKey(),
            'createdAt' => $connection->getCreatedAt(),
            'updatedAt' => $connection->getUpdatedAt(),
        ]);
    }

    public function findByUserId(AppUserId $appUserId): ?AppUserStravaConnection
    {
        $result = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('AppUserStravaConnection')
            ->andWhere('appUserId = :appUserId')
            ->setParameter('appUserId', (string) $appUserId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return false === $result ? null : $this->hydrate($result);
    }

    public function findByAthleteId(string $stravaAthleteId): ?AppUserStravaConnection
    {
        $result = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('AppUserStravaConnection')
            ->andWhere('stravaAthleteId = :stravaAthleteId')
            ->setParameter('stravaAthleteId', trim($stravaAthleteId))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return false === $result ? null : $this->hydrate($result);
    }

    public function deleteByUserId(AppUserId $appUserId): void
    {
        $this->connection->delete('AppUserStravaConnection', [
            'appUserId' => (string) $appUserId,
        ]);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function hydrate(array $result): AppUserStravaConnection
    {
        return AppUserStravaConnection::connect(
            appUserId: AppUserId::fromString((string) $result['appUserId']),
            stravaAthleteId: (string) $result['stravaAthleteId'],
            refreshToken: (string) $result['refreshToken'],
            scopes: json_decode((string) $result['scopes'], true, flags: JSON_THROW_ON_ERROR),
            accessTokenExpiresAt: null === $result['accessTokenExpiresAt'] ? null : SerializableDateTime::fromString((string) $result['accessTokenExpiresAt']),
            updatedAt: SerializableDateTime::fromString((string) $result['updatedAt']),
            webhookCorrelationKey: $result['webhookCorrelationKey'],
            createdAt: SerializableDateTime::fromString((string) $result['createdAt']),
        );
    }
}
