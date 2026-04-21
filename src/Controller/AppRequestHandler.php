<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\AppUrl;
use App\Application\Build\BuildIndexHtml\IndexHtml;
use App\Application\React\BuildReactAppBootstrap;
use App\Domain\Athlete\AthleteRepository;
use App\Domain\Strava\Connection\AppUserStravaConnectionRepository;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[AsController]
final readonly class AppRequestHandler
{
    public function __construct(
        private CurrentAppUser $currentAppUser,
        private AthleteRepository $athleteRepository,
        private AppUserStravaConnectionRepository $stravaConnectionRepository,
        private IndexHtml $indexHtml,
        private BuildReactAppBootstrap $buildReactAppBootstrap,
        private AppUrl $appUrl,
        private Clock $clock,
        private Environment $twig,
    ) {
    }

    #[Route(path: '/{wildcard?}', requirements: ['wildcard' => '.*'], methods: ['GET'], priority: -10)]
    public function handle(): Response
    {
        if (null === $this->currentAppUser->get()) {
            return new RedirectResponse('/login', Response::HTTP_FOUND);
        }

        try {
            $athlete = $this->athleteRepository->find();
        } catch (EntityNotFound) {
            $appUser = $this->currentAppUser->require();

            return new Response($this->twig->render('auth/react-portal.html.twig', [
                'pageTitle' => 'Statistics for Strava · Finish account setup',
                'reactPortalBootstrap' => Json::encode([
                    'kind' => 'setup',
                    'basePath' => $this->appUrl->getBasePath() ?? '',
                    'appName' => 'Statistics for Strava',
                    'user' => [
                        'email' => $appUser->getEmail(),
                    ],
                    'strava' => [
                        'connected' => null !== $this->stravaConnectionRepository->findByUserId($appUser->getId()),
                    ],
                    'actions' => [
                        'accountSettingsPath' => 'account/settings',
                        'logoutPath' => 'logout',
                        'connectStravaPath' => 'strava-oauth',
                    ],
                ]),
            ]), Response::HTTP_OK);
        }

        $context = $this->indexHtml->getContext($this->clock->getCurrentDateTimeImmutable());

        return new Response($this->twig->render('html/react-app.html.twig', [
            ...$context,
            'pageTitle' => sprintf('Statistics for Strava | %s', $athlete->getName()),
            'reactAppBootstrap' => Json::encode($this->buildReactAppBootstrap->build($context, 'live')),
        ]), Response::HTTP_OK);
    }
}
