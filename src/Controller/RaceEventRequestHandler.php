<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Build\BuildMonthlyStatsHtml\BuildMonthlyStatsHtml;
use App\Application\Build\BuildRacePlannerHtml\BuildRacePlannerHtml;
use App\Application\Build\BuildTrainingPlansHtml\BuildTrainingPlansHtml;
use App\Domain\TrainingPlanner\DbalRaceEventRepository;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventFamily;
use App\Domain\TrainingPlanner\RaceEventId;
use App\Domain\TrainingPlanner\RaceEventProfile;
use App\Domain\TrainingPlanner\RaceEventPriority;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[AsController]
final readonly class RaceEventRequestHandler
{
    public function __construct(
        private DbalRaceEventRepository $repository,
        private CommandBus $commandBus,
        private Clock $clock,
        private Environment $twig,
    ) {
    }

    #[Route(path: '/race-event', methods: ['GET', 'POST'])]
    public function handle(Request $request): Response
    {
        if ($request->isMethod('GET')) {
            return $this->renderModal($request);
        }

        $raceEventId = $request->request->getString('raceEventId');
        $existing = '' === $raceEventId ? null : $this->repository->findById(RaceEventId::fromString($raceEventId));
        $now = $this->clock->getCurrentDateTimeImmutable();
        $profile = RaceEventProfile::from($request->request->getString(
            'profile',
            $request->request->getString('type', RaceEventProfile::SPRINT_TRIATHLON->value),
        ));
        $family = RaceEventFamily::tryFrom($request->request->getString('family', $profile->getFamily()->value)) ?? $profile->getFamily();

        $raceEvent = RaceEvent::createWithClassification(
            raceEventId: $existing?->getId() ?? RaceEventId::random(),
            ownerUserId: $existing?->getOwnerUserId(),
            day: SerializableDateTime::fromString($request->request->getString('day', $now->format('Y-m-d'))),
            family: $family,
            profile: $profile,
            title: $this->nullableString($request->request->getString('title')),
            location: $this->nullableString($request->request->getString('location')),
            notes: $this->nullableString($request->request->getString('notes')),
            priority: RaceEventPriority::from($request->request->getString('priority', RaceEventPriority::B->value)),
            targetFinishTimeInSeconds: $this->parseTargetFinishTimeInSeconds(
                $request->request->getString('targetFinishTimeHours'),
                $request->request->getString('targetFinishTimeMinutes'),
            ),
            createdAt: $existing?->getCreatedAt() ?? $now,
            updatedAt: $now,
        );

        $this->repository->upsert($raceEvent);
        $this->rebuildPlannerViews($now);

        return $this->createRedirectResponse($request);
    }

    #[Route(path: '/race-event/delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $raceEventId = $request->request->getString('raceEventId');
        if ('' !== $raceEventId) {
            $this->repository->delete(RaceEventId::fromString($raceEventId));
            $this->rebuildPlannerViews($this->clock->getCurrentDateTimeImmutable());
        }

        return $this->createRedirectResponse($request);
    }

    private function renderModal(Request $request): Response
    {
        $today = $this->clock->getCurrentDateTimeImmutable()->setTime(0, 0);
        $raceEventId = $request->query->getString('raceEventId');
        $raceEvent = '' === $raceEventId ? null : $this->repository->findById(RaceEventId::fromString($raceEventId));

        return new Response($this->twig->render('html/dashboard/race-event.html.twig', [
            'raceEvent' => $raceEvent,
            'raceEventDefaultDay' => null === $raceEvent
                ? $request->query->getString('day', $today->format('Y-m-d'))
                : $raceEvent->getDay()->format('Y-m-d'),
            'raceEventFamilyOptions' => RaceEventFamily::cases(),
            'raceEventProfileOptionGroups' => $this->buildRaceEventProfileOptionGroups(),
            'raceEventPriorityOptions' => RaceEventPriority::cases(),
            'targetFinishTimeHours' => $this->extractTargetFinishHours($raceEvent?->getTargetFinishTimeInSeconds()),
            'targetFinishTimeMinutes' => $this->extractTargetFinishMinutes($raceEvent?->getTargetFinishTimeInSeconds()),
            'redirectTo' => $this->resolveRedirectTarget($request),
        ]));
    }

    private function parseTargetFinishTimeInSeconds(string $hours, string $minutes): ?int
    {
        $hours = trim($hours);
        $minutes = trim($minutes);

        if ('' === $hours && '' === $minutes) {
            return null;
        }

        $parsedHours = '' === $hours ? 0 : max(0, (int) $hours);
        $parsedMinutes = '' === $minutes ? 0 : max(0, (int) $minutes);

        return ($parsedHours * 3600) + ($parsedMinutes * 60);
    }

    private function extractTargetFinishHours(?int $seconds): ?int
    {
        if (null === $seconds || $seconds <= 0) {
            return null;
        }

        return intdiv($seconds, 3600);
    }

    private function extractTargetFinishMinutes(?int $seconds): ?int
    {
        if (null === $seconds || $seconds <= 0) {
            return null;
        }

        return intdiv($seconds % 3600, 60);
    }

    private function rebuildPlannerViews(?SerializableDateTime $now = null): void
    {
        $now ??= $this->clock->getCurrentDateTimeImmutable();

        $this->commandBus->dispatch(new BuildMonthlyStatsHtml($now));
        $this->commandBus->dispatch(new BuildRacePlannerHtml($now));
        $this->commandBus->dispatch(new BuildTrainingPlansHtml($now));
    }

    private function nullableString(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
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

        return $this->sanitizeRedirectTarget(is_string($referer) ? $referer : null) ?? '/monthly-stats';
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

    /**
     * @return list<array{family: RaceEventFamily, label: string, options: list<RaceEventProfile>}>
     */
    private function buildRaceEventProfileOptionGroups(): array
    {
        $groupedOptions = [];

        foreach (RaceEventProfile::cases() as $raceEventProfile) {
            $family = $raceEventProfile->getFamily();
            $groupedOptions[$family->value] ??= [
                'family' => $family,
                'label' => $family->getLabel(),
                'options' => [],
            ];
            $groupedOptions[$family->value]['options'][] = $raceEventProfile;
        }

        return array_values($groupedOptions);
    }
}
