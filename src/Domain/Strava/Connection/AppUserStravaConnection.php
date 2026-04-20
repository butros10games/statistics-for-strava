<?php

declare(strict_types=1);

namespace App\Domain\Strava\Connection;

use App\Domain\Auth\AppUserId;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'AppUserStravaConnection')]
final readonly class AppUserStravaConnection
{
    /**
     * @param list<string> $scopes
     */
    private function __construct(
        #[ORM\Id, ORM\Column(type: 'string', unique: true)]
        private AppUserId $appUserId,
        #[ORM\Column(type: 'string')]
        private string $stravaAthleteId,
        #[ORM\Column(type: 'string')]
        private string $refreshToken,
        #[ORM\Column(type: 'json')]
        private array $scopes,
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        private ?SerializableDateTime $accessTokenExpiresAt,
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        private ?SerializableDateTime $tokenRefreshedAt,
        #[ORM\Column(type: 'string', nullable: true)]
        private ?string $webhookCorrelationKey,
        #[ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $createdAt,
        #[ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $updatedAt,
    ) {
    }

    /**
     * @param list<string> $scopes
     */
    public static function connect(
        AppUserId $appUserId,
        string $stravaAthleteId,
        string $refreshToken,
        array $scopes,
        ?SerializableDateTime $accessTokenExpiresAt,
        SerializableDateTime $updatedAt,
        ?string $webhookCorrelationKey = null,
        ?SerializableDateTime $createdAt = null,
    ): self {
        return new self(
            appUserId: $appUserId,
            stravaAthleteId: trim($stravaAthleteId),
            refreshToken: trim($refreshToken),
            scopes: array_values(array_unique(array_filter(array_map('trim', $scopes), static fn (string $scope): bool => '' !== $scope))),
            accessTokenExpiresAt: $accessTokenExpiresAt,
            tokenRefreshedAt: $updatedAt,
            webhookCorrelationKey: null === $webhookCorrelationKey ? null : trim($webhookCorrelationKey),
            createdAt: $createdAt ?? $updatedAt,
            updatedAt: $updatedAt,
        );
    }

    public function getAppUserId(): AppUserId
    {
        return $this->appUserId;
    }

    public function getStravaAthleteId(): string
    {
        return $this->stravaAthleteId;
    }

    public function getRefreshToken(): string
    {
        return $this->refreshToken;
    }

    /**
     * @return list<string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function getAccessTokenExpiresAt(): ?SerializableDateTime
    {
        return $this->accessTokenExpiresAt;
    }

    public function getTokenRefreshedAt(): ?SerializableDateTime
    {
        return $this->tokenRefreshedAt;
    }

    public function getWebhookCorrelationKey(): ?string
    {
        return $this->webhookCorrelationKey;
    }

    public function getCreatedAt(): SerializableDateTime
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): SerializableDateTime
    {
        return $this->updatedAt;
    }
}
