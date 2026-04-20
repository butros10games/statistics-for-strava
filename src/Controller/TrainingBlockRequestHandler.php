<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Build\BuildMonthlyStatsHtml\BuildMonthlyStatsHtml;
use App\Application\Build\BuildRacePlannerHtml\BuildRacePlannerHtml;
use App\Domain\TrainingPlanner\DbalRaceEventRepository;
use App\Domain\TrainingPlanner\DbalTrainingBlockRepository;
use App\Domain\TrainingPlanner\TrainingBlock;
use App\Domain\TrainingPlanner\TrainingBlockId;
use App\Domain\TrainingPlanner\TrainingBlockPhase;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[AsController]
final readonly class TrainingBlockRequestHandler
{
    public function __construct(
        private DbalTrainingBlockRepository $repository,
        private DbalRaceEventRepository $raceEventRepository,
        private CommandBus $commandBus,
        private Clock $clock,
        private Environment $twig,
    ) {
    }

    #[Route(path: '/training-block', methods: ['GET', 'POST'])]
    public function handle(Request $request): Response
    {
        if ($request->isMethod('GET')) {
            return $this->renderModal($request);
        }

        $trainingBlockId = $request->request->getString('trainingBlockId');
        $existing = '' === $trainingBlockId ? null : $this->repository->findById(TrainingBlockId::fromString($trainingBlockId));
        $now = $this->clock->getCurrentDateTimeImmutable();
        $startDay = SerializableDateTime::fromString($request->request->getString('startDay', $now->format('Y-m-d')));
        $endDay = SerializableDateTime::fromString($request->request->getString('endDay', $startDay->format('Y-m-d')));

        $trainingBlock = TrainingBlock::create(
            trainingBlockId: $existing?->getId() ?? TrainingBlockId::random(),
            startDay: $startDay,
            endDay: $endDay,
            targetRaceEventId: $this->nullableRaceEventId($request->request->getString('targetRaceEventId')),
            phase: TrainingBlockPhase::from($request->request->getString('phase', TrainingBlockPhase::BASE->value)),
            title: $this->nullableString($request->request->getString('title')),
            focus: $this->nullableString($request->request->getString('focus')),
            notes: $this->nullableString($request->request->getString('notes')),
            createdAt: $existing?->getCreatedAt() ?? $now,
            updatedAt: $now,
        );

        $this->repository->upsert($trainingBlock);
        $this->rebuildPlannerViews($now);

        return $this->createRedirectResponse($request);
    }

    #[Route(path: '/training-block/delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $trainingBlockId = $request->request->getString('trainingBlockId');
        if ('' !== $trainingBlockId) {
            $this->repository->delete(TrainingBlockId::fromString($trainingBlockId));
            $this->rebuildPlannerViews($this->clock->getCurrentDateTimeImmutable());
        }

        return $this->createRedirectResponse($request);
    }

    private function renderModal(Request $request): Response
    {
        $today = $this->clock->getCurrentDateTimeImmutable()->setTime(0, 0);
        $trainingBlockId = $request->query->getString('trainingBlockId');
        $trainingBlock = '' === $trainingBlockId ? null : $this->repository->findById(TrainingBlockId::fromString($trainingBlockId));
        $defaultDay = $request->query->getString('day', $today->format('Y-m-d'));

        return new Response($this->twig->render('html/dashboard/training-block.html.twig', [
            'trainingBlock' => $trainingBlock,
            'trainingBlockDefaultStartDay' => null === $trainingBlock ? $defaultDay : $trainingBlock->getStartDay()->format('Y-m-d'),
            'trainingBlockDefaultEndDay' => null === $trainingBlock ? $defaultDay : $trainingBlock->getEndDay()->format('Y-m-d'),
            'trainingBlockPhaseOptions' => TrainingBlockPhase::cases(),
            'trainingBlockRaceEventOptions' => $this->loadRaceEventOptions(),
            'redirectTo' => $this->resolveRedirectTarget($request),
        ]));
    }

    private function loadRaceEventOptions(): array
    {
        $earliestRaceEvent = $this->raceEventRepository->findEarliest();
        $latestRaceEvent = $this->raceEventRepository->findLatest();
        if (null === $earliestRaceEvent || null === $latestRaceEvent) {
            return [];
        }

        return $this->raceEventRepository->findByDateRange(DateRange::fromDates(
            $earliestRaceEvent->getDay()->setTime(0, 0),
            $latestRaceEvent->getDay()->setTime(23, 59, 59),
        ));
    }

    private function rebuildPlannerViews(?SerializableDateTime $now = null): void
    {
        $now ??= $this->clock->getCurrentDateTimeImmutable();

        $this->commandBus->dispatch(new BuildMonthlyStatsHtml($now));
        $this->commandBus->dispatch(new BuildRacePlannerHtml($now));
    }

    private function nullableString(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    private function nullableRaceEventId(?string $value): ?\App\Domain\TrainingPlanner\RaceEventId
    {
        $value = null === $value ? null : trim($value);
        if (null === $value || '' === $value) {
            return null;
        }

        return \App\Domain\TrainingPlanner\RaceEventId::fromString($value);
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
}
