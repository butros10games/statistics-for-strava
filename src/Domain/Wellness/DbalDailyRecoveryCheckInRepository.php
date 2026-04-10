<?php

declare(strict_types=1);

namespace App\Domain\Wellness;

use App\Infrastructure\Repository\DbalRepository;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class DbalDailyRecoveryCheckInRepository extends DbalRepository implements DailyRecoveryCheckInRepository
{
    public function upsert(DailyRecoveryCheckIn $dailyRecoveryCheckIn): void
    {
        $sql = 'INSERT INTO DailyRecoveryCheckIn (
                    day, fatigue, soreness, stress, motivation, sleepQuality, recordedAt
                ) VALUES (
                    :day, :fatigue, :soreness, :stress, :motivation, :sleepQuality, :recordedAt
                )
                ON CONFLICT(`day`) DO UPDATE SET
                    fatigue = excluded.fatigue,
                    soreness = excluded.soreness,
                    stress = excluded.stress,
                    motivation = excluded.motivation,
                    sleepQuality = excluded.sleepQuality,
                    recordedAt = excluded.recordedAt';

        $this->connection->executeStatement($sql, [
            'day' => $dailyRecoveryCheckIn->getDay(),
            'fatigue' => $dailyRecoveryCheckIn->getFatigue(),
            'soreness' => $dailyRecoveryCheckIn->getSoreness(),
            'stress' => $dailyRecoveryCheckIn->getStress(),
            'motivation' => $dailyRecoveryCheckIn->getMotivation(),
            'sleepQuality' => $dailyRecoveryCheckIn->getSleepQuality(),
            'recordedAt' => $dailyRecoveryCheckIn->getRecordedAt(),
        ]);
    }

    public function findByDateRange(DateRange $dateRange): array
    {
        return array_map(
            $this->hydrate(...),
            $this->connection->createQueryBuilder()
                ->select('*')
                ->from('DailyRecoveryCheckIn')
                ->andWhere('day >= :from')
                ->andWhere('day <= :till')
                ->setParameter('from', $dateRange->getFrom())
                ->setParameter('till', $dateRange->getTill())
                ->orderBy('day', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative(),
        );
    }

    public function findLatest(): ?DailyRecoveryCheckIn
    {
        $result = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('DailyRecoveryCheckIn')
            ->orderBy('day', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return false === $result ? null : $this->hydrate($result);
    }

    public function findByDay(SerializableDateTime $day): ?DailyRecoveryCheckIn
    {
        $result = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('DailyRecoveryCheckIn')
            ->andWhere('day = :day')
            ->setParameter('day', $day->setTime(0, 0))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return false === $result ? null : $this->hydrate($result);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function hydrate(array $result): DailyRecoveryCheckIn
    {
        return DailyRecoveryCheckIn::create(
            day: SerializableDateTime::fromString($result['day']),
            fatigue: (int) $result['fatigue'],
            soreness: (int) $result['soreness'],
            stress: (int) $result['stress'],
            motivation: (int) $result['motivation'],
            sleepQuality: (int) $result['sleepQuality'],
            recordedAt: SerializableDateTime::fromString($result['recordedAt']),
        );
    }
}