<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\Wellness\FindDailyRecoveryCheckIns;

use App\Infrastructure\CQRS\Query\Query;
use App\Infrastructure\CQRS\Query\QueryHandler;
use App\Infrastructure\CQRS\Query\Response;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\DBAL\Connection;

final readonly class FindDailyRecoveryCheckInsQueryHandler implements QueryHandler
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function handle(Query $query): Response
    {
        assert($query instanceof FindDailyRecoveryCheckIns);

        $records = $this->connection->executeQuery(
            <<<SQL
                SELECT strftime('%Y-%m-%d', day) AS day,
                       fatigue,
                       soreness,
                       stress,
                       motivation,
                       sleepQuality
                FROM DailyRecoveryCheckIn
                WHERE strftime('%Y-%m-%d', day) BETWEEN :from AND :till
                ORDER BY day ASC
            SQL,
            [
                'from' => $query->getFrom()->format('Y-m-d'),
                'till' => $query->getTo()->format('Y-m-d'),
            ]
        )->fetchAllAssociative();

        $latestDay = [] === $records ? null : SerializableDateTime::fromString(end($records)['day'])->setTime(0, 0);

        return new FindDailyRecoveryCheckInsResponse(
            records: array_map(
                static fn (array $record): array => [
                    'day' => $record['day'],
                    'fatigue' => (int) $record['fatigue'],
                    'soreness' => (int) $record['soreness'],
                    'stress' => (int) $record['stress'],
                    'motivation' => (int) $record['motivation'],
                    'sleepQuality' => (int) $record['sleepQuality'],
                ],
                $records,
            ),
            latestDay: $latestDay,
        );
    }
}