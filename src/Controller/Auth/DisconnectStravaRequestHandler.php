<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Domain\Strava\Connection\AppUserStravaConnectionRepository;
use App\Infrastructure\User\CurrentAppUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class DisconnectStravaRequestHandler
{
    public function __construct(
        private CurrentAppUser $currentAppUser,
        private AppUserStravaConnectionRepository $stravaConnectionRepository,
    ) {
    }

    #[Route(path: '/account/strava/disconnect', name: 'app_account_strava_disconnect', methods: ['POST'])]
    public function handle(): Response
    {
        $this->stravaConnectionRepository->deleteByUserId($this->currentAppUser->require()->getId());

        return new RedirectResponse('/account/settings', Response::HTTP_FOUND);
    }
}
