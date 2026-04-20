<?php

declare(strict_types=1);

namespace App\Infrastructure\User;

use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class CurrentAppUser
{
    public function __construct(
        private Security $security,
    ) {
    }

    public function get(): ?AppUser
    {
        $user = $this->security->getUser();

        return $user instanceof AppUser ? $user : null;
    }

    public function require(): AppUser
    {
        $user = $this->get();
        if ($user instanceof AppUser) {
            return $user;
        }

        throw new \RuntimeException('No authenticated app user is available.');
    }

    public function getId(): ?AppUserId
    {
        return $this->get()?->getId();
    }
}
