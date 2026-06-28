<?php

declare(strict_types=1);

namespace App\Domain\Strava;

use App\Domain\Strava\Connection\AppUserStravaConnectionRepository;
use App\Infrastructure\User\CurrentAppUser;

final readonly class StravaRefreshTokenResolver
{
    public function __construct(
        private CurrentAppUser $currentAppUser,
        private AppUserStravaConnectionRepository $stravaConnectionRepository,
        private StravaRefreshToken $fallbackRefreshToken,
    ) {
    }

    public function resolve(): StravaRefreshToken
    {
        $appUserId = $this->currentAppUser->getId();
        if ($appUserId instanceof \App\Domain\Auth\AppUserId) {
            $connection = $this->stravaConnectionRepository->findByUserId($appUserId);
            if ($connection instanceof Connection\AppUserStravaConnection) {
                return StravaRefreshToken::fromString($connection->getRefreshToken());
            }
        }

        return $this->fallbackRefreshToken;
    }

    public function cacheKey(): string
    {
        return (string) ($this->currentAppUser->getId() ?? 'global');
    }
}
