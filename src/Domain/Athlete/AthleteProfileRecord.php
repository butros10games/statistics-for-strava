<?php

declare(strict_types=1);

namespace App\Domain\Athlete;

use App\Domain\Auth\AppUserId;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'AthleteProfile')]
final readonly class AthleteProfileRecord
{
    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(
        #[ORM\Id, ORM\Column(type: 'string', unique: true)]
        private AppUserId $appUserId,
        #[ORM\Column(type: 'json')]
        private array $payload,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function create(AppUserId $appUserId, array $payload): self
    {
        return new self(
            appUserId: $appUserId,
            payload: $payload,
        );
    }

    public function getAppUserId(): AppUserId
    {
        return $this->appUserId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }
}
