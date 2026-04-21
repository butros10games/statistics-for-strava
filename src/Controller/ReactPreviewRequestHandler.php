<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Build\BuildIndexHtml\IndexHtml;
use App\Application\React\BuildReactAppBootstrap;
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
        private BuildReactAppBootstrap $buildReactAppBootstrap,
        private Clock $clock,
        private Environment $twig,
    ) {
    }

    #[Route(path: '/react-preview/{reactRoute?}', requirements: ['reactRoute' => '.*'], methods: ['GET'], priority: 5)]
    public function handle(): Response
    {
        $context = $this->indexHtml->getContext($this->clock->getCurrentDateTimeImmutable());

        return new Response($this->twig->render('html/react-app.html.twig', [
            ...$context,
            'pageTitle' => 'Statistics for Strava · React preview',
            'reactAppBootstrap' => Json::encode($this->buildReactAppBootstrap->build($context, 'preview')),
        ]), Response::HTTP_OK);
    }
}
