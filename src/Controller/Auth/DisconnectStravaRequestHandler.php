<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Domain\Strava\Connection\AppUserStravaConnectionRepository;
use App\Domain\Strava\StravaTokenRevoker;
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
        private StravaTokenRevoker $stravaTokenRevoker,
    ) {
    }

    #[Route(path: '/account/strava/disconnect', name: 'app_account_strava_disconnect', methods: ['POST'])]
    public function handle(): RedirectResponse
    {
        $appUserId = $this->currentAppUser->require()->getId();
        $stravaConnection = $this->stravaConnectionRepository->findByUserId($appUserId);

        if ($stravaConnection instanceof \App\Domain\Strava\Connection\AppUserStravaConnection) {
            $this->stravaTokenRevoker->revokeRefreshToken($stravaConnection->getRefreshToken());
        }

        $this->stravaConnectionRepository->deleteByUserId($appUserId);

        return new RedirectResponse('/account/settings', Response::HTTP_FOUND);
    }
}
