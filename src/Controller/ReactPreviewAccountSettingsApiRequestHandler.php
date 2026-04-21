<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Strava\Connection\AppUserStravaConnection;
use App\Domain\Strava\Connection\AppUserStravaConnectionRepository;
use App\Domain\Wellness\DailyWellnessRepository;
use App\Domain\Wellness\WellnessImportConfig;
use App\Domain\Wellness\WellnessSource;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class ReactPreviewAccountSettingsApiRequestHandler
{
    public function __construct(
        private CurrentAppUser $currentAppUser,
        private AppUserStravaConnectionRepository $stravaConnectionRepository,
        private DailyWellnessRepository $dailyWellnessRepository,
        private WellnessImportConfig $wellnessImportConfig,
        private Clock $clock,
    ) {
    }

    #[Route(path: '/react-preview/api/account-settings', methods: ['GET'], priority: 7)]
    public function handle(): JsonResponse
    {
        $this->currentAppUser->require();

        return new JsonResponse($this->buildPayload());
    }

    #[Route(path: '/react-preview/api/account-settings/strava-disconnect', methods: ['POST'], priority: 7)]
    public function disconnectStrava(): JsonResponse
    {
        $appUser = $this->currentAppUser->require();
        $this->stravaConnectionRepository->deleteByUserId($appUser->getId());

        return new JsonResponse($this->buildPayload(disconnectedStrava: true));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(bool $disconnectedStrava = false): array
    {
        $appUser = $this->currentAppUser->require();
        $stravaConnection = $this->stravaConnectionRepository->findByUserId($appUser->getId());
        $garminConnectionMode = $this->detectGarminConnectionMode();
        $garminConfigured = null !== $garminConnectionMode;
        $garminEnabled = $this->wellnessImportConfig->isEnabled();
        $garminLastImportedDay = $this->dailyWellnessRepository->findMostRecentDayForSource(WellnessSource::GARMIN);

        return [
            'requestedAt' => $this->clock->getCurrentDateTimeImmutable()->format(DATE_ATOM),
            'legacyPath' => 'account/settings',
            'disconnectedStrava' => $disconnectedStrava,
            'summary' => [
                'connectedServices' => (null !== $stravaConnection ? 1 : 0) + ($garminConfigured ? 1 : 0),
                'manualSyncProviders' => count(array_filter([
                    null !== $stravaConnection,
                    $garminEnabled && $garminConfigured,
                ])),
                'garminLastImportedDay' => $garminLastImportedDay?->format('Y-m-d'),
            ],
            'account' => [
                'email' => $appUser->getEmail(),
                'emailVerified' => $appUser->isEmailVerified(),
                'emailVerificationStatusLabel' => $appUser->isEmailVerified() ? 'Verified' : 'Pending',
                'verifyEmailPath' => null === $appUser->getEmailVerificationToken()
                    ? null
                    : sprintf('verify-email/%s', $appUser->getEmailVerificationToken()),
            ],
            'strava' => $this->serializeStravaConnection($stravaConnection),
            'garmin' => [
                'enabled' => $garminEnabled,
                'configured' => $garminConfigured,
                'canSync' => $garminEnabled && $garminConfigured,
                'connectionMode' => $garminConnectionMode,
                'connectionModeLabel' => $this->resolveGarminConnectionModeLabel($garminConnectionMode),
                'bridgeSourcePath' => $this->wellnessImportConfig->getBridgeSourcePath(),
                'lastImportedDay' => $garminLastImportedDay?->format('Y-m-d'),
            ],
            'actions' => [
                'backToAppPath' => 'dashboard',
                'logoutPath' => 'logout',
                'connectStravaPath' => 'strava-oauth',
                'disconnectStravaPath' => 'react-preview/api/account-settings/strava-disconnect',
                'syncStravaPath' => 'account/sync/strava',
                'syncGarminPath' => 'account/sync/garmin',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeStravaConnection(?AppUserStravaConnection $stravaConnection): array
    {
        return [
            'connected' => $stravaConnection instanceof AppUserStravaConnection,
            'statusLabel' => $stravaConnection instanceof AppUserStravaConnection ? 'Connected' : 'Not connected',
            'athleteId' => $stravaConnection?->getStravaAthleteId(),
            'scopes' => $stravaConnection?->getScopes() ?? [],
            'scopeLabel' => $stravaConnection instanceof AppUserStravaConnection ? implode(', ', $stravaConnection->getScopes()) : null,
            'canSync' => $stravaConnection instanceof AppUserStravaConnection,
            'tokenRefreshedAt' => $stravaConnection?->getTokenRefreshedAt()?->format(DATE_ATOM),
        ];
    }

    private function detectGarminConnectionMode(): ?string
    {
        $email = trim((string) getenv('GARMIN_EMAIL'));
        $password = trim((string) getenv('GARMIN_PASSWORD'));
        if ('' !== $email && '' !== $password) {
            return 'credentials';
        }

        if ('' !== trim((string) getenv('GARMIN_JWT_WEB'))) {
            return 'browser-token';
        }

        if ('' !== trim((string) getenv('GARMIN_DI_TOKEN'))) {
            return 'device-token';
        }

        return null;
    }

    private function resolveGarminConnectionModeLabel(?string $mode): string
    {
        return match ($mode) {
            'credentials' => 'Email + password',
            'browser-token' => 'Browser token',
            'device-token' => 'Device token',
            default => 'Not configured',
        };
    }
}
