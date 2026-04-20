<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Build\BuildRacePlannerHtml\BuildRacePlannerHtml;
use App\Application\Build\BuildTrainingPlansHtml\BuildTrainingPlansHtml;
use App\Domain\TrainingPlanner\AdaptivePlanningContextBuilder;
use App\Domain\TrainingPlanner\PlanGenerator\RaceProfileTrainingRules;
use App\Domain\TrainingPlanner\PlanGenerator\TrainingPlanGenerator;
use App\Domain\TrainingPlanner\PlannedSessionRepository;
use App\Domain\TrainingPlanner\RaceEventRepository;
use App\Domain\TrainingPlanner\RacePlannerConfiguration;
use App\Domain\TrainingPlanner\RacePlannerExistingBlockSelector;
use App\Domain\TrainingPlanner\TrainingBlockRepository;
use App\Domain\TrainingPlanner\TrainingPlan;
use App\Domain\TrainingPlanner\TrainingPlanDiscipline;
use App\Domain\TrainingPlanner\TrainingPlanId;
use App\Domain\TrainingPlanner\TrainingPlanRepository;
use App\Domain\TrainingPlanner\TrainingPlanType;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class RacePlannerSetupPlanRequestHandler
{
    public function __construct(
        private RaceEventRepository $raceEventRepository,
        private TrainingBlockRepository $trainingBlockRepository,
        private PlannedSessionRepository $plannedSessionRepository,
        private RacePlannerExistingBlockSelector $existingBlockSelector,
        private RacePlannerConfiguration $racePlannerConfiguration,
        private TrainingPlanGenerator $trainingPlanGenerator,
        private AdaptivePlanningContextBuilder $adaptivePlanningContextBuilder,
        private TrainingPlanRepository $trainingPlanRepository,
        private CommandBus $commandBus,
        private Clock $clock,
    ) {
    }

    #[Route(path: '/race-planner/setup-plan', methods: ['POST'])]
    public function handle(Request $request): Response
    {
        $raceEventId = trim($request->request->getString('raceEventId'));
        $now = $this->clock->getCurrentDateTimeImmutable();

        if ('' === $raceEventId) {
            return new RedirectResponse($this->resolveRedirectTarget($request), Response::HTTP_FOUND);
        }

        $targetRace = $this->raceEventRepository->findById(\App\Domain\TrainingPlanner\RaceEventId::fromString($raceEventId));
        if (null === $targetRace) {
            return new RedirectResponse($this->resolveRedirectTarget($request), Response::HTTP_FOUND);
        }

        $existingTrainingPlan = $this->trainingPlanRepository->findByTargetRaceEventId($targetRace->getId());
        $planningContext = $this->buildPlanningContext($targetRace, $now, $existingTrainingPlan);

        $this->trainingPlanRepository->upsert(TrainingPlan::create(
            trainingPlanId: $existingTrainingPlan?->getId() ?? TrainingPlanId::random(),
            type: TrainingPlanType::RACE,
            startDay: $planningContext['effectivePlanStartDay'],
            endDay: $planningContext['proposal']->getPlanEndDay(),
            targetRaceEventId: $targetRace->getId(),
            title: $existingTrainingPlan?->getTitle() ?? $targetRace->getTitle() ?? $targetRace->getProfile()->value,
            notes: $existingTrainingPlan?->getNotes(),
            createdAt: $existingTrainingPlan?->getCreatedAt() ?? $now,
            updatedAt: $now,
            discipline: $existingTrainingPlan?->getDiscipline() ?? $this->inferDisciplineFromRace($targetRace),
            sportSchedule: $existingTrainingPlan?->getSportSchedule(),
            performanceMetrics: $existingTrainingPlan?->getPerformanceMetrics(),
            targetRaceProfile: $existingTrainingPlan?->getTargetRaceProfile() ?? $targetRace->getProfile(),
            trainingFocus: $existingTrainingPlan?->getTrainingFocus(),
        ));

        $this->commandBus->dispatch(new BuildTrainingPlansHtml($now));
        $this->commandBus->dispatch(new BuildRacePlannerHtml($now));

        return new RedirectResponse($this->resolveRedirectTarget($request), Response::HTTP_FOUND);
    }

    /**
     * @return array{proposal: \App\Domain\TrainingPlanner\PlanGenerator\TrainingPlanProposal, effectivePlanStartDay: SerializableDateTime}
     */
    private function buildPlanningContext(\App\Domain\TrainingPlanner\RaceEvent $targetRace, SerializableDateTime $now, ?TrainingPlan $existingTrainingPlan = null): array
    {
        $upcomingRaces = $this->raceEventRepository->findUpcoming($now, 20);
        if ([] === $upcomingRaces) {
            $upcomingRaces = [$targetRace];
        }

        $rules = RaceProfileTrainingRules::forProfile($existingTrainingPlan?->getTargetRaceProfile() ?? $targetRace->getProfile());
        $configuredPlanStartDay = $this->racePlannerConfiguration->findPlanStartDay();
        $planningEndDay = $this->resolvePlanningEndDay($targetRace, $rules);
        $searchRange = DateRange::fromDates(
            $targetRace->getDay()->modify(sprintf('-%d weeks', max(32, $rules->getMaximumPlanWeeks() + 6)))->setTime(0, 0),
            $planningEndDay,
        );
        $blocksInPlanningWindow = $this->trainingBlockRepository->findByDateRange($searchRange);
        $reusableExistingBlocks = $this->existingBlockSelector->selectReusableBlocks($targetRace, $blocksInPlanningWindow, $planningEndDay);
        $effectivePlanStartDay = $this->resolveEffectivePlanStartDay(
            $configuredPlanStartDay,
            $targetRace,
            $now,
            ($reusableExistingBlocks[0] ?? null)?->getStartDay(),
        );

        $dateRange = DateRange::fromDates($effectivePlanStartDay, $planningEndDay);
        $existingBlocks = [] !== $reusableExistingBlocks
            ? $reusableExistingBlocks
            : $this->trainingBlockRepository->findByDateRange($dateRange);
        $existingSessions = $this->plannedSessionRepository->findByDateRange($dateRange);
        $adaptivePlanningContext = $this->adaptivePlanningContextBuilder->build(
            referenceDate: $now,
            plannedSessions: $existingSessions,
            raceEvents: $upcomingRaces,
            trainingBlocks: $existingBlocks,
        );

        return [
            'proposal' => $this->trainingPlanGenerator->generate(
                targetRace: $targetRace,
                planStartDay: $effectivePlanStartDay,
                allRaceEvents: $upcomingRaces,
                existingBlocks: $existingBlocks,
                existingSessions: $existingSessions,
                referenceDate: $now,
                linkedTrainingPlan: $existingTrainingPlan,
                adaptivePlanningContext: $adaptivePlanningContext,
            ),
            'effectivePlanStartDay' => $effectivePlanStartDay,
        ];
    }

    private function inferDisciplineFromRace(\App\Domain\TrainingPlanner\RaceEvent $targetRace): ?TrainingPlanDiscipline
    {
        return match ($targetRace->getFamily()) {
            \App\Domain\TrainingPlanner\RaceEventFamily::TRIATHLON,
            \App\Domain\TrainingPlanner\RaceEventFamily::MULTISPORT,
            \App\Domain\TrainingPlanner\RaceEventFamily::SWIM => TrainingPlanDiscipline::TRIATHLON,
            \App\Domain\TrainingPlanner\RaceEventFamily::RIDE => TrainingPlanDiscipline::CYCLING,
            \App\Domain\TrainingPlanner\RaceEventFamily::RUN,
            \App\Domain\TrainingPlanner\RaceEventFamily::OTHER => TrainingPlanDiscipline::RUNNING,
        };
    }

    private function resolveEffectivePlanStartDay(
        ?SerializableDateTime $configuredPlanStartDay,
        \App\Domain\TrainingPlanner\RaceEvent $targetRace,
        SerializableDateTime $now,
        ?SerializableDateTime $existingBlockStartDay = null,
    ): SerializableDateTime {
        $planStartDay = ($existingBlockStartDay ?? $configuredPlanStartDay ?? $now)->setTime(0, 0);
        $targetRaceDay = $targetRace->getDay()->setTime(0, 0);

        return $planStartDay > $targetRaceDay ? $targetRaceDay : $planStartDay;
    }

    private function resolvePlanningEndDay(\App\Domain\TrainingPlanner\RaceEvent $targetRace, RaceProfileTrainingRules $rules): SerializableDateTime
    {
        $recoveryWeeks = $rules->getPostRaceRecoveryWeeks();
        if (0 === $recoveryWeeks) {
            return $targetRace->getDay()->setTime(23, 59, 59);
        }

        return $targetRace->getDay()->modify(sprintf('+%d weeks', $recoveryWeeks))->setTime(23, 59, 59);
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