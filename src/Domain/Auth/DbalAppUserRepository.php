<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Infrastructure\Repository\DbalRepository;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class DbalAppUserRepository extends DbalRepository implements AppUserRepository
{
    public function save(AppUser $appUser): void
    {
        $sql = 'INSERT INTO AppUser (
                    appUserId, email, passwordHash, roles, emailVerified, emailVerificationToken,
                    passwordResetToken, passwordResetRequestedAt, createdAt, updatedAt
                ) VALUES (
                    :appUserId, :email, :passwordHash, :roles, :emailVerified, :emailVerificationToken,
                    :passwordResetToken, :passwordResetRequestedAt, :createdAt, :updatedAt
                )
                ON CONFLICT(`appUserId`) DO UPDATE SET
                    email = excluded.email,
                    passwordHash = excluded.passwordHash,
                    roles = excluded.roles,
                    emailVerified = excluded.emailVerified,
                    emailVerificationToken = excluded.emailVerificationToken,
                    passwordResetToken = excluded.passwordResetToken,
                    passwordResetRequestedAt = excluded.passwordResetRequestedAt,
                    createdAt = excluded.createdAt,
                    updatedAt = excluded.updatedAt';

        $this->connection->executeStatement($sql, [
            'appUserId' => (string) $appUser->getId(),
            'email' => $appUser->getEmail(),
            'passwordHash' => $appUser->getPassword(),
            'roles' => json_encode($appUser->getRoles(), JSON_THROW_ON_ERROR),
            'emailVerified' => (int) $appUser->isEmailVerified(),
            'emailVerificationToken' => $appUser->getEmailVerificationToken(),
            'passwordResetToken' => $appUser->getPasswordResetToken(),
            'passwordResetRequestedAt' => $appUser->getPasswordResetRequestedAt(),
            'createdAt' => $appUser->getCreatedAt(),
            'updatedAt' => $appUser->getUpdatedAt(),
        ]);
    }

    public function findById(AppUserId $appUserId): ?AppUser
    {
        $result = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('AppUser')
            ->andWhere('appUserId = :appUserId')
            ->setParameter('appUserId', (string) $appUserId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return false === $result ? null : $this->hydrate($result);
    }

    public function findByEmail(string $email): ?AppUser
    {
        $result = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('AppUser')
            ->andWhere('email = :email')
            ->setParameter('email', strtolower(trim($email)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return false === $result ? null : $this->hydrate($result);
    }

    public function findByPasswordResetToken(string $passwordResetToken): ?AppUser
    {
        $result = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('AppUser')
            ->andWhere('passwordResetToken = :passwordResetToken')
            ->setParameter('passwordResetToken', trim($passwordResetToken))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return false === $result ? null : $this->hydrate($result);
    }

    public function findByEmailVerificationToken(string $emailVerificationToken): ?AppUser
    {
        $result = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('AppUser')
            ->andWhere('emailVerificationToken = :emailVerificationToken')
            ->setParameter('emailVerificationToken', trim($emailVerificationToken))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return false === $result ? null : $this->hydrate($result);
    }

    public function emailExists(string $email): bool
    {
        return false !== $this->connection->createQueryBuilder()
            ->select('appUserId')
            ->from('AppUser')
            ->andWhere('email = :email')
            ->setParameter('email', strtolower(trim($email)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
    }

    public function count(): int
    {
        return (int) $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('AppUser')
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @param array<string, mixed> $result
     */
    private function hydrate(array $result): AppUser
    {
        return AppUser::restore(
            appUserId: AppUserId::fromString((string) $result['appUserId']),
            email: (string) $result['email'],
            passwordHash: (string) $result['passwordHash'],
            roles: json_decode((string) $result['roles'], true, flags: JSON_THROW_ON_ERROR),
            emailVerified: (bool) $result['emailVerified'],
            emailVerificationToken: $result['emailVerificationToken'],
            passwordResetToken: $result['passwordResetToken'],
            passwordResetRequestedAt: null === $result['passwordResetRequestedAt'] ? null : SerializableDateTime::fromString((string) $result['passwordResetRequestedAt']),
            createdAt: SerializableDateTime::fromString((string) $result['createdAt']),
            updatedAt: SerializableDateTime::fromString((string) $result['updatedAt']),
        );
    }
}
