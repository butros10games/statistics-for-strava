<?php

declare(strict_types=1);

namespace App\Domain\Auth;

interface AppUserRepository
{
    public function save(AppUser $appUser): void;

    public function findById(AppUserId $appUserId): ?AppUser;

    public function findByEmail(string $email): ?AppUser;

    public function findByPasswordResetToken(string $passwordResetToken): ?AppUser;

    public function findByEmailVerificationToken(string $emailVerificationToken): ?AppUser;

    public function emailExists(string $email): bool;

    public function count(): int;
}
