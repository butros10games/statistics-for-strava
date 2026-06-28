<?php

declare(strict_types=1);

namespace App\Domain\Strava\Webhook;

use App\Infrastructure\Repository\DbalRepository;
use App\Infrastructure\Serialization\Json;
use Doctrine\DBAL\ArrayParameterType;

final readonly class DbalWebhookEventRepository extends DbalRepository implements WebhookEventRepository
{
    public function add(WebhookEvent $webhookEvent): void
    {
        $sql = 'INSERT INTO WebhookEvent (
                    eventId, objectId, objectType, aspectType, ownerAthleteId, appUserId, payload
                ) VALUES (
                    :eventId, :objectId, :objectType, :aspectType, :ownerAthleteId, :appUserId, :payload
                )
                ON CONFLICT(`eventId`) DO UPDATE SET
                    payload = excluded.payload;';

        $this->connection->executeStatement($sql, [
            'eventId' => $webhookEvent->getEventId(),
            'objectId' => $webhookEvent->getObjectId(),
            'objectType' => $webhookEvent->getObjectType(),
            'aspectType' => $webhookEvent->getAspectType()->value,
            'ownerAthleteId' => $webhookEvent->getOwnerAthleteId(),
            'appUserId' => $webhookEvent->getAppUserId(),
            'payload' => Json::encode($webhookEvent->getPayload()),
        ]);
    }

    public function grab(): array
    {
        $this->connection->beginTransaction();

        $queryBuilder = $this->connection
            ->createQueryBuilder()
            ->select('*')
            ->from('WebhookEvent')
            ->orderBy('objectId', 'ASC')
            ->addOrderBy('aspectType', 'ASC');

        $webhookEvents = array_map(
            fn (array $result): WebhookEvent => WebhookEvent::create(
                objectId: $result['objectId'],
                objectType: $result['objectType'],
                aspectType: WebhookAspectType::from($result['aspectType']),
                payload: Json::decode($result['payload']),
                ownerAthleteId: $result['ownerAthleteId'],
                appUserId: $result['appUserId'],
            ),
            $queryBuilder->executeQuery()->fetchAllAssociative()
        );

        if ([] === $webhookEvents) {
            $this->connection->commit();

            return [];
        }

        $this->connection->executeStatement('DELETE FROM WebhookEvent WHERE eventId IN (:eventIds)',
            [
                'eventIds' => array_map(fn (WebhookEvent $webhookEvent): string => $webhookEvent->getEventId(), $webhookEvents),
            ],
            [
                'eventIds' => ArrayParameterType::STRING,
            ]
        );

        $this->connection->commit();

        return $webhookEvents;
    }
}
