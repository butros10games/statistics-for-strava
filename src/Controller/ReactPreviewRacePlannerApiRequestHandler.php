<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\ReactPreview\RacePlannerPreviewPayloadBuilder;
use App\Domain\TrainingPlanner\TrainingPlanId;
use App\Domain\TrainingPlanner\TrainingPlanRepository;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class ReactPreviewRacePlannerApiRequestHandler
{
    public function __construct(
        private CurrentAppUser $currentAppUser,
        private Clock $clock,
        private TrainingPlanRepository $trainingPlanRepository,
        private RacePlannerPreviewPayloadBuilder $payloadBuilder,
        private RacePlannerSetupPlanRequestHandler $setupPlanRequestHandler,
        private RacePlannerRegenerateUpcomingSessionsRequestHandler $regenerateUpcomingSessionsRequestHandler,
        private RacePlannerStartDateRequestHandler $startDateRequestHandler,
        private RacePlannerSaveRecoveryRequestHandler $saveRecoveryRequestHandler,
    ) {
    }

    #[Route(path: '/react-preview/api/race-planner', methods: ['GET'], priority: 6)]
    public function handle(): JsonResponse
    {
        $this->currentAppUser->require();

        return new JsonResponse($this->payloadBuilder->buildLandingPayload($this->clock->getCurrentDateTimeImmutable()));
    }

    #[Route(path: '/react-preview/api/race-planner/plan/{trainingPlanId}', methods: ['GET'], priority: 6)]
    public function handlePlanPreview(string $trainingPlanId): JsonResponse
    {
        $this->currentAppUser->require();

        try {
            $plan = $this->trainingPlanRepository->findById(TrainingPlanId::fromString($trainingPlanId));
        } catch (\Throwable) {
            $plan = null;
        }

        if (null === $plan) {
            return new JsonResponse([
                'message' => 'Training plan preview not found.',
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->payloadBuilder->buildPlanPreviewPayload($plan, $this->clock->getCurrentDateTimeImmutable()));
    }

    #[Route(path: '/react-preview/api/race-planner/setup-plan', methods: ['POST'], priority: 6)]
    public function setupPlan(Request $request): JsonResponse
    {
        $this->currentAppUser->require();

        $this->setupPlanRequestHandler->handle($this->createDelegateRequest($request, '/react-preview/race-planner'));

        return new JsonResponse(['ok' => true]);
    }

    #[Route(path: '/react-preview/api/race-planner/regenerate-upcoming-sessions', methods: ['POST'], priority: 6)]
    public function regenerateUpcomingSessions(Request $request): JsonResponse
    {
        $this->currentAppUser->require();

        $this->regenerateUpcomingSessionsRequestHandler->handle($this->createDelegateRequest($request, '/react-preview/race-planner'));

        return new JsonResponse(['ok' => true]);
    }

    #[Route(path: '/react-preview/api/race-planner/start-date', methods: ['POST'], priority: 6)]
    public function updateStartDate(Request $request): JsonResponse
    {
        $this->currentAppUser->require();

        $this->startDateRequestHandler->handle($this->createDelegateRequest($request, '/react-preview/race-planner'));

        return new JsonResponse(['ok' => true]);
    }

    #[Route(path: '/react-preview/api/race-planner/save-recovery', methods: ['POST'], priority: 6)]
    public function saveRecovery(Request $request): JsonResponse
    {
        $this->currentAppUser->require();

        $this->saveRecoveryRequestHandler->handle($this->createDelegateRequest($request, '/react-preview/race-planner'));

        return new JsonResponse(['ok' => true]);
    }

    private function createDelegateRequest(Request $request, string $redirectTo): Request
    {
        $payload = $request->toArray();

        return new Request(
            request: [
                ...$payload,
                'redirectTo' => $redirectTo,
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        );
    }
}
