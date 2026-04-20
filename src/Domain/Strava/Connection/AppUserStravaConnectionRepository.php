<?php

declare(strict_types=1);

namespace App\Domain\Strava\Connection;

use App\Domain\Auth\AppUserId;

interface AppUserStravaConnectionRepository
{
    public function save(AppUserStravaConnection $connection): void;

    public function findByUserId(AppUserId $appUserId): ?AppUserStravaConnection;

    public function findByAthleteId(string $stravaAthleteId): ?AppUserStravaConnection;

    public function deleteByUserId(AppUserId $appUserId): void;
}
