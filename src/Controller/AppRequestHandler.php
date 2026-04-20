<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Build\BuildIndexHtml\IndexHtml;
use App\Application\Router;
use App\Domain\Athlete\AthleteRepository;
use App\Domain\Strava\Connection\AppUserStravaConnectionRepository;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use Ramsey\Uuid\Uuid;
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

            return new Response($this->twig->render('html/setup.html.twig', [
                'appUser' => $appUser,
                'stravaConnection' => $this->stravaConnectionRepository->findByUserId($appUser->getId()),
            ]), Response::HTTP_OK);
        }

        $context = $this->indexHtml->getContext($this->clock->getCurrentDateTimeImmutable());

        return new Response($this->twig->render('html/index.html.twig', [
            'router' => Router::SINGLE_PAGE,
            'easterEggPageUrl' => Uuid::uuid5(Uuid::NAMESPACE_DNS, $athlete->getAthleteId()),
            ...$context,
        ]), Response::HTTP_OK);
    }
}
