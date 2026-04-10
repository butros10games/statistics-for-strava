<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\Wellness\FindWellnessMetrics;

use App\Infrastructure\CQRS\Query\Query;
use App\Infrastructure\CQRS\Query\QueryHandler;
use App\Infrastructure\CQRS\Query\Response;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\DBAL\Connection;

final readonly class FindWellnessMetricsQueryHandler implements QueryHandler
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function handle(Query $query): Response
    {
        assert($query instanceof FindWellnessMetrics);

        $records = $this->connection->executeQuery(
            <<<SQL
                SELECT strftime('%Y-%m-%d', day) AS day,
                       stepsCount,
                       sleepDurationInSeconds,
                       sleepScore,
                       hrv
                FROM DailyWellness
                WHERE strftime('%Y-%m-%d', day) BETWEEN :from AND :till
                  AND source = :source
                ORDER BY day ASC
            SQL,
            [
                'from' => $query->getFrom()->format('Y-m-d'),
                'till' => $query->getTo()->format('Y-m-d'),
                'source' => $query->getSource()->value,
            ]
        )->fetchAllAssociative();

        $latestDay = [] === $records ? null : SerializableDateTime::fromString(end($records)['day'])->setTime(0, 0);

        return new FindWellnessMetricsResponse(
            records: array_map(
                static fn (array $record): array => [
                    'day' => $record['day'],
                    'stepsCount' => null === $record['stepsCount'] ? null : (int) $record['stepsCount'],
                    'sleepDurationInSeconds' => null === $record['sleepDurationInSeconds'] ? null : (int) $record['sleepDurationInSeconds'],
                    'sleepScore' => null === $record['sleepScore'] ? null : (int) $record['sleepScore'],
                    'hrv' => null === $record['hrv'] ? null : (float) $record['hrv'],
                ],
                $records,
            ),
            latestDay: $latestDay,
        );
    }
}