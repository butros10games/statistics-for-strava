<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Build\BuildRacePlannerHtml\BuildRacePlannerHtml;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\Time\Clock\Clock;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class RacePlannerPageRequestHandler
{
    public function __construct(
        private CommandBus $commandBus,
        private FilesystemOperator $buildStorage,
        private Clock $clock,
    ) {
    }

    #[Route(path: '/race-planner.html', methods: ['GET'])]
    public function handleLanding(): Response
    {
        $this->commandBus->dispatch(new BuildRacePlannerHtml($this->clock->getCurrentDateTimeImmutable()));

        return new Response($this->buildStorage->read('race-planner.html'), Response::HTTP_OK);
    }

    #[Route(path: '/race-planner/plan-{trainingPlanId}.html', methods: ['GET'])]
    public function handlePreview(string $trainingPlanId): Response
    {
        $this->commandBus->dispatch(new BuildRacePlannerHtml($this->clock->getCurrentDateTimeImmutable()));
        $path = sprintf('race-planner/plan-%s.html', $trainingPlanId);

        if (!$this->buildStorage->fileExists($path)) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->buildStorage->read($path), Response::HTTP_OK);
    }
}
