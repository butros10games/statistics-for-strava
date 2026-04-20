<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserRepository;
use App\Infrastructure\Time\Clock\Clock;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherAwareInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final readonly class AppUserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(
        private AppUserRepository $appUserRepository,
        private Clock $clock,
    ) {
    }

    #[\Override]
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->appUserRepository->findByEmail($identifier);

        if ($user instanceof AppUser) {
            return $user;
        }

        throw new UserNotFoundException(sprintf('User "%s" was not found.', $identifier));
    }

    #[\Override]
    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof AppUser) {
            throw new UnsupportedUserException(sprintf('Unsupported user class "%s".', $user::class));
        }

        $refreshedUser = $this->appUserRepository->findById($user->getId());

        if ($refreshedUser instanceof AppUser) {
            return $refreshedUser;
        }

        throw new UserNotFoundException(sprintf('User "%s" was not found.', $user->getUserIdentifier()));
    }

    #[\Override]
    public function supportsClass(string $class): bool
    {
        return AppUser::class === $class || is_subclass_of($class, AppUser::class);
    }

    #[\Override]
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof AppUser) {
            throw new UnsupportedUserException(sprintf('Unsupported user class "%s".', $user::class));
        }

        $this->appUserRepository->save($user->withPasswordHash(
            passwordHash: $newHashedPassword,
            updatedAt: $this->clock->getCurrentDateTimeImmutable(),
        ));
    }
}
