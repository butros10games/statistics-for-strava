<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'AppUser')]
#[ORM\UniqueConstraint(name: 'AppUser_email', columns: ['email'])]
final readonly class AppUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    /**
     * @param list<string> $roles
     */
    private function __construct(
        #[ORM\Id, ORM\Column(type: 'string', unique: true)]
        private AppUserId $appUserId,
        #[ORM\Column(type: 'string')]
        private string $email,
        #[ORM\Column(type: 'string')]
        private string $passwordHash,
        #[ORM\Column(type: 'json')]
        private array $roles,
        #[ORM\Column(type: 'boolean')]
        private bool $emailVerified,
        #[ORM\Column(type: 'string', nullable: true)]
        private ?string $emailVerificationToken,
        #[ORM\Column(type: 'string', nullable: true)]
        private ?string $passwordResetToken,
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        private ?SerializableDateTime $passwordResetRequestedAt,
        #[ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $createdAt,
        #[ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $updatedAt,
    ) {
    }

    public static function register(
        AppUserId $appUserId,
        string $email,
        string $passwordHash,
        SerializableDateTime $createdAt,
    ): self {
        return new self(
            appUserId: $appUserId,
            email: self::normalizeEmail($email),
            passwordHash: $passwordHash,
            roles: ['ROLE_USER'],
            emailVerified: false,
            emailVerificationToken: bin2hex(random_bytes(16)),
            passwordResetToken: null,
            passwordResetRequestedAt: null,
            createdAt: $createdAt,
            updatedAt: $createdAt,
        );
    }

    /**
     * @param list<string> $roles
     */
    public static function restore(
        AppUserId $appUserId,
        string $email,
        string $passwordHash,
        array $roles,
        bool $emailVerified,
        ?string $emailVerificationToken,
        ?string $passwordResetToken,
        ?SerializableDateTime $passwordResetRequestedAt,
        SerializableDateTime $createdAt,
        SerializableDateTime $updatedAt,
    ): self {
        return new self(
            appUserId: $appUserId,
            email: self::normalizeEmail($email),
            passwordHash: $passwordHash,
            roles: self::normalizeRoles($roles),
            emailVerified: $emailVerified,
            emailVerificationToken: self::normalizeNullableToken($emailVerificationToken),
            passwordResetToken: self::normalizeNullableToken($passwordResetToken),
            passwordResetRequestedAt: $passwordResetRequestedAt,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }

    public function getId(): AppUserId
    {
        return $this->appUserId;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getDisplayName(): string
    {
        $localPart = explode('@', $this->email)[0] ?? $this->email;

        return ucfirst(str_replace(['.', '-', '_'], ' ', $localPart));
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return self::normalizeRoles($this->roles);
    }

    public function eraseCredentials(): void
    {
    }

    #[\Override]
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    #[\Override]
    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerified;
    }

    public function getEmailVerificationToken(): ?string
    {
        return $this->emailVerificationToken;
    }

    public function getPasswordResetToken(): ?string
    {
        return $this->passwordResetToken;
    }

    public function getPasswordResetRequestedAt(): ?SerializableDateTime
    {
        return $this->passwordResetRequestedAt;
    }

    public function getCreatedAt(): SerializableDateTime
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): SerializableDateTime
    {
        return $this->updatedAt;
    }

    public function withPasswordHash(string $passwordHash, SerializableDateTime $updatedAt): self
    {
        return self::restore(
            appUserId: $this->appUserId,
            email: $this->email,
            passwordHash: $passwordHash,
            roles: $this->roles,
            emailVerified: $this->emailVerified,
            emailVerificationToken: $this->emailVerificationToken,
            passwordResetToken: null,
            passwordResetRequestedAt: null,
            createdAt: $this->createdAt,
            updatedAt: $updatedAt,
        );
    }

    public function withPasswordResetToken(?string $passwordResetToken, ?SerializableDateTime $requestedAt, SerializableDateTime $updatedAt): self
    {
        return self::restore(
            appUserId: $this->appUserId,
            email: $this->email,
            passwordHash: $this->passwordHash,
            roles: $this->roles,
            emailVerified: $this->emailVerified,
            emailVerificationToken: $this->emailVerificationToken,
            passwordResetToken: $passwordResetToken,
            passwordResetRequestedAt: $requestedAt,
            createdAt: $this->createdAt,
            updatedAt: $updatedAt,
        );
    }

    public function markEmailVerified(SerializableDateTime $updatedAt): self
    {
        return self::restore(
            appUserId: $this->appUserId,
            email: $this->email,
            passwordHash: $this->passwordHash,
            roles: $this->roles,
            emailVerified: true,
            emailVerificationToken: null,
            passwordResetToken: $this->passwordResetToken,
            passwordResetRequestedAt: $this->passwordResetRequestedAt,
            createdAt: $this->createdAt,
            updatedAt: $updatedAt,
        );
    }

    /**
     * @param list<string> $roles
     *
     * @return list<string>
     */
    private static function normalizeRoles(array $roles): array
    {
        $roles[] = 'ROLE_USER';
        $roles = array_values(array_unique(array_filter(
            array_map(static fn (mixed $role): string => strtoupper(trim((string) $role)), $roles),
            static fn (string $role): bool => '' !== $role,
        )));
        sort($roles);

        return $roles;
    }

    private static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private static function normalizeNullableToken(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
