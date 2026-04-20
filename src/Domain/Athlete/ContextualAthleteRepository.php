<?php

declare(strict_types=1);

namespace App\Domain\Athlete;

use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\KeyValue\Key;
use App\Infrastructure\KeyValue\KeyValue;
use App\Infrastructure\KeyValue\KeyValueStore;
use App\Infrastructure\KeyValue\Value;
use App\Infrastructure\Repository\DbalRepository;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\User\CurrentAppUser;

final readonly class ContextualAthleteRepository extends DbalRepository implements AthleteRepository
{
    public function __construct(
        \Doctrine\DBAL\Connection $connection,
        private CurrentAppUser $currentAppUser,
        private KeyValueStore $keyValueStore,
        private MaxHeartRate\MaxHeartRateFormula $maxHeartRateFormula,
        private RestingHeartRate\RestingHeartRateFormula $restingHeartRateFormula,
    ) {
        parent::__construct($connection);
    }

    public function save(Athlete $athlete): void
    {
        $currentUser = $this->currentAppUser->get();

        if (null === $currentUser) {
            $this->keyValueStore->save(KeyValue::fromState(
                key: Key::ATHLETE,
                value: Value::fromString(Json::encode($athlete)),
            ));

            return;
        }

        $sql = 'INSERT INTO AthleteProfile (appUserId, payload)
                VALUES (:appUserId, :payload)
                ON CONFLICT(`appUserId`) DO UPDATE SET payload = excluded.payload';

        $this->connection->executeStatement($sql, [
            'appUserId' => (string) $currentUser->getId(),
            'payload' => Json::encode($athlete),
        ]);
    }

    public function find(): Athlete
    {
        $currentUser = $this->currentAppUser->get();

        if ($currentUser instanceof \App\Domain\Auth\AppUser) {
            $result = $this->connection->createQueryBuilder()
                ->select('payload')
                ->from('AthleteProfile')
                ->andWhere('appUserId = :appUserId')
                ->setParameter('appUserId', (string) $currentUser->getId())
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();

            if (false !== $result) {
                return $this->hydrateAthlete(Json::decode((string) $result));
            }
        }

        try {
            return $this->hydrateAthlete(Json::decode((string) $this->keyValueStore->find(Key::ATHLETE)));
        } catch (EntityNotFound) {
            throw new EntityNotFound('No athlete profile is available for the current user.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hydrateAthlete(array $payload): Athlete
    {
        $athlete = Athlete::create($payload);
        $athlete
            ->setMaxHeartRateFormula($this->maxHeartRateFormula)
            ->setRestingHeartRateFormula($this->restingHeartRateFormula);

        return $athlete;
    }
}
