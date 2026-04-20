<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Application\Build\BuildIndexHtml\IndexHtml;
use App\Application\Router;
use App\Domain\Athlete\AthleteRepository;
use App\Domain\Strava\Connection\AppUserStravaConnectionRepository;
use App\Domain\Wellness\DbalDailyWellnessRepository;
use App\Domain\Wellness\WellnessImportConfig;
use App\Domain\Wellness\WellnessSource;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\Time\Clock\Clock;
use Symfony\Component\HttpFoundation\Request;
use App\Infrastructure\User\CurrentAppUser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;
use Ramsey\Uuid\Uuid;

#[AsController]
final readonly class AccountSettingsRequestHandler
{
    private const string FRAGMENT_REQUEST_HEADER = 'X-Fragment-Request';

    public function __construct(
        private CurrentAppUser $currentAppUser,
        private AthleteRepository $athleteRepository,
        private AppUserStravaConnectionRepository $stravaConnectionRepository,
        private DbalDailyWellnessRepository $dailyWellnessRepository,
        private WellnessImportConfig $wellnessImportConfig,
        private IndexHtml $indexHtml,
        private Clock $clock,
        private Environment $twig,
    ) {
    }

    #[Route(path: '/account/settings', name: 'app_account_settings', methods: ['GET'])]
    public function handle(Request $request): Response
    {
        if ('1' === $request->headers->get(self::FRAGMENT_REQUEST_HEADER)) {
            return new Response($this->twig->render('account/settings.html.twig', $this->buildContext()));
        }

        try {
            $athlete = $this->athleteRepository->find();
        } catch (EntityNotFound) {
            return new Response($this->twig->render('account/settings-page.html.twig', $this->buildContext()));
        }

        $context = $this->indexHtml->getContext($this->clock->getCurrentDateTimeImmutable());

        return new Response($this->twig->render('html/index.html.twig', [
            'router' => Router::SINGLE_PAGE,
            'easterEggPageUrl' => Uuid::uuid5(Uuid::NAMESPACE_DNS, $athlete->getAthleteId()),
            ...$context,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(): array
    {
        $appUser = $this->currentAppUser->require();
        $stravaConnection = $this->stravaConnectionRepository->findByUserId($appUser->getId());
        $garminLastImportedDay = $this->dailyWellnessRepository->findMostRecentDayForSource(WellnessSource::GARMIN);
        $garminConnectionMode = $this->detectGarminConnectionMode();
        $garminConfigured = null !== $garminConnectionMode;
        $garminEnabled = $this->wellnessImportConfig->isEnabled();

        return [
            'appUser' => $appUser,
            'stravaConnection' => $stravaConnection,
            'garminEnabled' => $garminEnabled,
            'garminConfigured' => $garminConfigured,
            'garminConnectionMode' => $garminConnectionMode,
            'garminCanSync' => $garminEnabled && $garminConfigured,
            'garminBridgeSourcePath' => $this->wellnessImportConfig->getBridgeSourcePath(),
            'garminLastImportedDay' => $garminLastImportedDay,
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
}
