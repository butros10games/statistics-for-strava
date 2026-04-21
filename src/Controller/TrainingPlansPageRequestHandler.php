<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class TrainingPlansPageRequestHandler
{
    #[Route(path: '/training-plans.html', methods: ['GET'])]
    public function handle(): Response
    {
        return new RedirectResponse('/training-plans', Response::HTTP_FOUND);
    }
}
