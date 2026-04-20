<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\AppUrl;
use App\Application\Build\BuildIndexHtml\IndexHtml;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\Time\Clock\Clock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[AsController]
final readonly class ReactPreviewRequestHandler
{
    public function __construct(
        private IndexHtml $indexHtml,
        private AppUrl $appUrl,
        private Clock $clock,
        private Environment $twig,
    ) {
    }

    #[Route(path: '/react-preview/{reactRoute?}', requirements: ['reactRoute' => '.*'], methods: ['GET'], priority: 5)]
    public function handle(): Response
    {
        $context = $this->indexHtml->getContext($this->clock->getCurrentDateTimeImmutable());
        $athlete = $context['athlete'];

        return new Response($this->twig->render('html/react-preview.html.twig', [
            ...$context,
            'reactPreviewBootstrap' => Json::encode([
                'appName' => 'Statistics for Strava',
                'subtitle' => null !== $context['subTitle'] ? (string) $context['subTitle'] : null,
                'athlete' => [
                    'name' => $athlete->getName(),
                    'initial' => strtoupper($athlete->getFirstLetterOfFirstName()),
                ],
                'profilePictureUrl' => null !== $context['profilePictureUrl'] ? (string) $context['profilePictureUrl'] : null,
                'counts' => [
                    'activities' => $context['totalActivityCount'],
                    'challenges' => $context['completedChallenges'],
                    'photos' => $context['totalPhotoCount'],
                    'hasGear' => $context['hasGear'],
                    'hasBestEfforts' => $context['hasBestEfforts'],
                ],
                'basePath' => $this->appUrl->getBasePath() ?? '',
            ]),
        ]), Response::HTTP_OK);
    }
}
