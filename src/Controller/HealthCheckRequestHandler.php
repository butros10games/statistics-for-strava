<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class HealthCheckRequestHandler
{
    #[Route(path: '/healthz', methods: ['GET'], priority: 100)]
    public function handle(): JsonResponse
    {
        $response = new JsonResponse(['status' => 'ok'], Response::HTTP_OK);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
