<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class RacePlannerPageRequestHandler
{
    #[Route(path: '/race-planner.html', methods: ['GET'])]
    public function handleLanding(): Response
    {
        return new RedirectResponse('/race-planner', Response::HTTP_FOUND);
    }

    #[Route(path: '/race-planner/plan-{trainingPlanId}.html', methods: ['GET'])]
    public function handlePreview(string $trainingPlanId): Response
    {
        return new RedirectResponse(sprintf('/race-planner/plan/%s', $trainingPlanId), Response::HTTP_FOUND);
    }
}
