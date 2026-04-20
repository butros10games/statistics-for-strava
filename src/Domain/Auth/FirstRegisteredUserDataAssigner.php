<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Strava\Connection\AppUserStravaConnection;
use App\Domain\Strava\Connection\AppUserStravaConnectionRepository;
use App\Domain\Strava\StravaRefreshToken;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\KeyValue\Key;
use App\Infrastructure\KeyValue\KeyValueStore;
use App\Infrastructure\Serialization\Json;
use Doctrine\DBAL\Connection;

final readonly class FirstRegisteredUserDataAssigner
{
    public function __construct(
        private AppUserRepository $appUserRepository,
        private AppUserStravaConnectionRepository $stravaConnectionRepository,
        private KeyValueStore $keyValueStore,
        private Connection $connection,
        private StravaRefreshToken $fallbackRefreshToken,
    ) {
    }

    public function assign(AppUser $appUser): void
    {
        if (1 !== $this->appUserRepository->count()) {
            return;
        }

        $legacyAthletePayload = $this->findLegacyAthletePayload();

        $this->connection->transactional(function () use ($appUser, $legacyAthletePayload): void {
            $this->claimLegacyPlannerData($appUser->getId());

            if (null === $legacyAthletePayload) {
                return;
            }

            $this->claimLegacyAthleteProfile($appUser->getId(), $legacyAthletePayload);
            $this->claimLegacyStravaConnection($appUser, $legacyAthletePayload);
        });
    }

    private function claimLegacyPlannerData(AppUserId $appUserId): void
    {
        foreach (['TrainingPlan', 'TrainingBlock', 'PlannedSession', 'RaceEvent'] as $table) {
            $this->connection->executeStatement(
                sprintf('UPDATE %s SET ownerUserId = :ownerUserId WHERE ownerUserId IS NULL', $table),
                ['ownerUserId' => (string) $appUserId],
            );
        }
    }

    private function claimLegacyAthleteProfile(AppUserId $appUserId, string $payload): void
    {
        $this->connection->executeStatement(
            'INSERT INTO AthleteProfile (appUserId, payload)
             VALUES (:appUserId, :payload)
             ON CONFLICT(`appUserId`) DO UPDATE SET payload = excluded.payload',
            [
                'appUserId' => (string) $appUserId,
                'payload' => $payload,
            ],
        );
    }

    private function claimLegacyStravaConnection(AppUser $appUser, string $payload): void
    {
        if (null !== $this->stravaConnectionRepository->findByUserId($appUser->getId())) {
            return;
        }

        $decodedPayload = Json::decode($payload);
        $stravaAthleteId = trim((string) ($decodedPayload['id'] ?? ''));
        if ('' === $stravaAthleteId) {
            return;
        }

        $this->stravaConnectionRepository->save(AppUserStravaConnection::connect(
            appUserId: $appUser->getId(),
            stravaAthleteId: $stravaAthleteId,
            refreshToken: (string) $this->fallbackRefreshToken,
            scopes: [],
            accessTokenExpiresAt: null,
            updatedAt: $appUser->getUpdatedAt(),
            webhookCorrelationKey: sprintf('strava-athlete-%s', $stravaAthleteId),
            createdAt: $appUser->getCreatedAt(),
        ));
    }

    private function findLegacyAthletePayload(): ?string
    {
        try {
            return (string) $this->keyValueStore->find(Key::ATHLETE);
        } catch (EntityNotFound) {
            return null;
        }
    }
}
