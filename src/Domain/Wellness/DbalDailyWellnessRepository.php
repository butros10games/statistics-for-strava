<?php

declare(strict_types=1);

namespace App\Domain\Wellness;

use App\Infrastructure\Repository\DbalRepository;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class DbalDailyWellnessRepository extends DbalRepository implements DailyWellnessRepository
{
    public function upsert(DailyWellness $dailyWellness): void
    {
        $sql = 'INSERT INTO DailyWellness (
                    day, source, stepsCount, sleepDurationInSeconds, sleepScore, hrv, payload, importedAt
                ) VALUES (
                    :day, :source, :stepsCount, :sleepDurationInSeconds, :sleepScore, :hrv, :payload, :importedAt
                )
                ON CONFLICT(`day`, `source`) DO UPDATE SET
                    stepsCount = excluded.stepsCount,
                    sleepDurationInSeconds = excluded.sleepDurationInSeconds,
                    sleepScore = excluded.sleepScore,
                    hrv = excluded.hrv,
                    payload = excluded.payload,
                    importedAt = excluded.importedAt';

        $this->connection->executeStatement($sql, [
            'day' => $dailyWellness->getDay(),
            'source' => $dailyWellness->getSource()->value,
            'stepsCount' => $dailyWellness->getStepsCount(),
            'sleepDurationInSeconds' => $dailyWellness->getSleepDurationInSeconds(),
            'sleepScore' => $dailyWellness->getSleepScore(),
            'hrv' => $dailyWellness->getHrv(),
            'payload' => Json::encode($dailyWellness->getPayload()),
            'importedAt' => $dailyWellness->getImportedAt(),
        ]);
    }

    public function findByDateRange(DateRange $dateRange, ?WellnessSource $source = null): array
    {
        $queryBuilder = $this->connection->createQueryBuilder();
        $queryBuilder->select('*')
            ->from('DailyWellness')
            ->andWhere('day >= :from')
            ->andWhere('day <= :till')
            ->setParameter('from', $dateRange->getFrom())
            ->setParameter('till', $dateRange->getTill())
            ->orderBy('day', 'ASC');

        if ($source instanceof WellnessSource) {
            $queryBuilder->andWhere('source = :source')
                ->setParameter('source', $source->value);
        }

        return array_map($this->hydrate(...), $queryBuilder->executeQuery()->fetchAllAssociative());
    }

    public function findMostRecentDayForSource(WellnessSource $source): ?SerializableDateTime
    {
        $result = $this->connection->createQueryBuilder()
            ->select('day')
            ->from('DailyWellness')
            ->andWhere('source = :source')
            ->setParameter('source', $source->value)
            ->orderBy('day', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return false === $result ? null : SerializableDateTime::fromString($result);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function hydrate(array $result): DailyWellness
    {
        return DailyWellness::create(
            day: SerializableDateTime::fromString($result['day']),
            source: WellnessSource::from($result['source']),
            stepsCount: null === $result['stepsCount'] ? null : (int) $result['stepsCount'],
            sleepDurationInSeconds: null === $result['sleepDurationInSeconds'] ? null : (int) $result['sleepDurationInSeconds'],
            sleepScore: null === $result['sleepScore'] ? null : (int) $result['sleepScore'],
            hrv: null === $result['hrv'] ? null : (float) $result['hrv'],
            payload: Json::decode($result['payload']),
            importedAt: SerializableDateTime::fromString($result['importedAt']),
        );
    }
}