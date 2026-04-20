<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Build\BuildTrainingPlansHtml\BuildTrainingPlansHtml;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\Time\Clock\Clock;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class TrainingPlansPageRequestHandler
{
    public function __construct(
        private CommandBus $commandBus,
        private FilesystemOperator $buildStorage,
        private Clock $clock,
    ) {
    }

    #[Route(path: '/training-plans.html', methods: ['GET'])]
    public function handle(): Response
    {
        $this->commandBus->dispatch(new BuildTrainingPlansHtml($this->clock->getCurrentDateTimeImmutable()));

        return new Response($this->buildStorage->read('training-plans.html'), Response::HTTP_OK);
    }
}
