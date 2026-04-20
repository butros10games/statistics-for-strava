<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Domain\Auth\AppUserRepository;
use App\Infrastructure\Time\Clock\Clock;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class VerifyEmailRequestHandler
{
    public function __construct(
        private AppUserRepository $appUserRepository,
        private Clock $clock,
    ) {
    }

    #[Route(path: '/verify-email/{token}', name: 'app_verify_email', methods: ['GET'])]
    public function handle(string $token): Response
    {
        $user = $this->appUserRepository->findByEmailVerificationToken($token);
        if (null !== $user) {
            $this->appUserRepository->save($user->markEmailVerified($this->clock->getCurrentDateTimeImmutable()));
        }

        return new RedirectResponse('/account/settings', Response::HTTP_FOUND);
    }
}
