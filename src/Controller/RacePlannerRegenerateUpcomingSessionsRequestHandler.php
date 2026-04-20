<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Build\BuildDashboardHtml\BuildDashboardHtml;
use App\Application\Build\BuildMonthlyStatsHtml\BuildMonthlyStatsHtml;
use App\Application\Build\BuildRacePlannerHtml\BuildRacePlannerHtml;
use App\Domain\TrainingPlanner\RaceEventId;
use App\Domain\TrainingPlanner\RaceEventRepository;
use App\Domain\TrainingPlanner\RacePlannerUpcomingSessionRegenerator;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class RacePlannerRegenerateUpcomingSessionsRequestHandler
{
    public function __construct(
        private RaceEventRepository $raceEventRepository,
        private RacePlannerUpcomingSessionRegenerator $racePlannerUpcomingSessionRegenerator,
        private CommandBus $commandBus,
        private Clock $clock,
    ) {
    }

    #[Route(path: '/race-planner/regenerate-upcoming-sessions', methods: ['POST'])]
    public function handle(Request $request): Response
    {
        $raceEventId = trim($request->request->getString('raceEventId'));
        if ('' === $raceEventId) {
            return $this->createRedirectResponse($request);
        }

        $targetRace = $this->raceEventRepository->findById(RaceEventId::fromString($raceEventId));
        if (null === $targetRace) {
            return $this->createRedirectResponse($request);
        }

        $now = $this->clock->getCurrentDateTimeImmutable();
        $regenerationSummary = $this->racePlannerUpcomingSessionRegenerator->regenerate($targetRace, $now);
        if ($regenerationSummary->hasChanges()) {
            $this->rebuildPlannerViews($now);
        }

        return $this->createRedirectResponse($request);
    }

    private function rebuildPlannerViews(?SerializableDateTime $now = null): void
    {
        $now ??= $this->clock->getCurrentDateTimeImmutable();

        $this->commandBus->dispatch(new BuildDashboardHtml());
        $this->commandBus->dispatch(new BuildMonthlyStatsHtml($now));
        $this->commandBus->dispatch(new BuildRacePlannerHtml($now));
    }

    private function createRedirectResponse(Request $request): RedirectResponse
    {
        return new RedirectResponse($this->resolveRedirectTarget($request), Response::HTTP_FOUND);
    }

    private function resolveRedirectTarget(Request $request): string
    {
        $requestedRedirectTarget = $this->sanitizeRedirectTarget(
            $request->request->getString('redirectTo', $request->query->getString('redirectTo'))
        );
        if (null !== $requestedRedirectTarget) {
            return $requestedRedirectTarget;
        }

        $referer = $request->headers->get('referer');

        return $this->sanitizeRedirectTarget(is_string($referer) ? $referer : null) ?? '/race-planner';
    }

    private function sanitizeRedirectTarget(?string $redirectTarget): ?string
    {
        $redirectTarget = null === $redirectTarget ? null : trim($redirectTarget);
        if (null === $redirectTarget || '' === $redirectTarget || str_starts_with($redirectTarget, '//')) {
            return null;
        }

        if (str_starts_with($redirectTarget, '/')) {
            return $redirectTarget;
        }

        $parsedRedirectTarget = parse_url($redirectTarget);
        if (!is_array($parsedRedirectTarget)) {
            return null;
        }

        $path = $parsedRedirectTarget['path'] ?? null;
        if (!is_string($path) || '' === $path || !str_starts_with($path, '/')) {
            return null;
        }

        $query = isset($parsedRedirectTarget['query']) && is_string($parsedRedirectTarget['query'])
            ? '?'.$parsedRedirectTarget['query']
            : '';
        $fragment = isset($parsedRedirectTarget['fragment']) && is_string($parsedRedirectTarget['fragment'])
            ? '#'.$parsedRedirectTarget['fragment']
            : '';

        return $path.$query.$fragment;
    }
}