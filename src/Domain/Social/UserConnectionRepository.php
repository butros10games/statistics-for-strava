<?php

declare(strict_types=1);

namespace App\Domain\Social;

use App\Domain\Auth\AppUserId;

interface UserConnectionRepository
{
    public function save(UserConnection $userConnection): void;

    public function findAccepted(AppUserId $requesterUserId, AppUserId $targetUserId, UserConnectionType $type): ?UserConnection;

    public function areFriends(AppUserId $leftUserId, AppUserId $rightUserId): bool;

    public function isFollower(AppUserId $followerUserId, AppUserId $targetUserId): bool;
}
