<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Build\BuildRacePlannerHtml\BuildRacePlannerHtml;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventPriority;
use App\Domain\TrainingPlanner\RaceEventRepository;
use App\Domain\TrainingPlanner\RacePlannerConfiguration;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class RacePlannerStartDateRequestHandler
{
    public function __construct(
        private RacePlannerConfiguration $racePlannerConfiguration,
        private RaceEventRepository $raceEventRepository,
        private CommandBus $commandBus,
        private Clock $clock,
    ) {
    }

    #[Route(path: '/race-planner/start-date', methods: ['POST'])]
    public function handle(Request $request): Response
    {
        $now = $this->clock->getCurrentDateTimeImmutable();
        $requestedPlanStartDay = trim($request->request->getString('planStartDay'));

        if ($request->request->has('resetPlanStartDay') || '' === $requestedPlanStartDay) {
            $this->racePlannerConfiguration->clearPlanStartDay();
        } else {
            $normalizedPlanStartDay = $this->normalizeRequestedPlanStartDay($requestedPlanStartDay, $now);

            if ($normalizedPlanStartDay instanceof SerializableDateTime) {
                $this->racePlannerConfiguration->savePlanStartDay($normalizedPlanStartDay);
            }
        }

        $this->commandBus->dispatch(new BuildRacePlannerHtml($now));

        return new RedirectResponse($this->resolveRedirectTarget($request), Response::HTTP_FOUND);
    }

    private function normalizeRequestedPlanStartDay(string $requestedPlanStartDay, SerializableDateTime $now): ?SerializableDateTime
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedPlanStartDay)) {
            return null;
        }

        $planStartDay = SerializableDateTime::fromString($requestedPlanStartDay)->setTime(0, 0);
        $upcomingRaces = $this->raceEventRepository->findUpcoming($now, 20);
        if ([] === $upcomingRaces) {
            return $planStartDay;
        }

        $targetRaceDay = $this->findTargetARace($upcomingRaces)->getDay()->setTime(0, 0);

        return $planStartDay > $targetRaceDay ? $targetRaceDay : $planStartDay;
    }

    /**
     * @param list<RaceEvent> $upcomingRaces
     */
    private function findTargetARace(array $upcomingRaces): RaceEvent
    {
        foreach ($upcomingRaces as $race) {
            if (RaceEventPriority::A === $race->getPriority()) {
                return $race;
            }
        }

        return $upcomingRaces[0];
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