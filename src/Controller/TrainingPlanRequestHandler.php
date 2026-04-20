<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Build\BuildDashboardHtml\BuildDashboardHtml;
use App\Application\Build\BuildMonthlyStatsHtml\BuildMonthlyStatsHtml;
use App\Application\Build\BuildRacePlannerHtml\BuildRacePlannerHtml;
use App\Application\Build\BuildTrainingPlansHtml\BuildTrainingPlansHtml;
use App\Domain\Performance\PerformanceAnchor\PerformanceAnchorHistory;
use App\Domain\Performance\PerformanceAnchor\PerformanceAnchorType;
use App\Domain\TrainingPlanner\DbalRaceEventRepository;
use App\Domain\TrainingPlanner\DbalTrainingPlanRepository;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionRepository;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventId;
use App\Domain\TrainingPlanner\RaceEventFamily;
use App\Domain\TrainingPlanner\RacePlannerUpcomingSessionRegenerator;
use App\Domain\TrainingPlanner\RaceEventProfile;
use App\Domain\TrainingPlanner\RunningWorkoutTargetMode;
use App\Domain\TrainingPlanner\TrainingBlockStyle;
use App\Domain\TrainingPlanner\TrainingFocus;
use App\Domain\TrainingPlanner\TrainingPlan;
use App\Domain\TrainingPlanner\TrainingPlanDiscipline;
use App\Domain\TrainingPlanner\TrainingPlanId;
use App\Domain\TrainingPlanner\TrainingPlanType;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[AsController]
final readonly class TrainingPlanRequestHandler
{
    public function __construct(
        private DbalTrainingPlanRepository $repository,
        private DbalRaceEventRepository $raceEventRepository,
        private PlannedSessionRepository $plannedSessionRepository,
        private RacePlannerUpcomingSessionRegenerator $racePlannerUpcomingSessionRegenerator,
        private CommandBus $commandBus,
        private Clock $clock,
        private Environment $twig,
        private PerformanceAnchorHistory $performanceAnchorHistory,
        private Connection $connection,
    ) {
    }

    #[Route(path: '/training-plan', methods: ['GET', 'POST'])]
    public function handle(Request $request): Response
    {
        if ($request->isMethod('GET')) {
            return $this->renderModal($request);
        }

        $trainingPlanId = $request->request->getString('trainingPlanId');
        $existing = '' === $trainingPlanId ? null : $this->repository->findById(TrainingPlanId::fromString($trainingPlanId));
        $now = $this->clock->getCurrentDateTimeImmutable();
        $type = TrainingPlanType::from($request->request->getString('type', TrainingPlanType::TRAINING->value));
        $discipline = $this->parseDiscipline($request->request->getString('discipline'));

        $trainingPlan = TrainingPlan::create(
            trainingPlanId: $existing?->getId() ?? TrainingPlanId::random(),
            type: $type,
            startDay: SerializableDateTime::fromString($request->request->getString('startDay', $now->format('Y-m-d'))),
            endDay: SerializableDateTime::fromString($request->request->getString('endDay', $now->format('Y-m-d'))),
            targetRaceEventId: TrainingPlanType::RACE === $type ? $this->nullableRaceEventId($request->request->getString('targetRaceEventId')) : null,
            title: $this->nullableString($request->request->getString('title')),
            notes: $this->nullableString($request->request->getString('notes')),
            discipline: $discipline,
            sportSchedule: $this->parseSportSchedule($request),
            performanceMetrics: $this->parsePerformanceMetrics($request),
            targetRaceProfile: $this->parseRaceProfile($request->request->getString('targetRaceProfile')),
            trainingFocus: TrainingPlanType::TRAINING === $type && TrainingPlanDiscipline::TRIATHLON === $discipline ? $this->parseTrainingFocus($request->request->getString('trainingFocus')) : null,
            trainingBlockStyle: TrainingPlanType::TRAINING === $type ? $this->parseTrainingBlockStyle($request->request->getString('trainingBlockStyle')) : null,
            runningWorkoutTargetMode: TrainingPlanType::TRAINING === $type && TrainingPlanDiscipline::RUNNING === $discipline
                ? $this->parseRunningWorkoutTargetMode($request->request->getString('runningWorkoutTargetMode'))
                : null,
            runHillSessionsEnabled: TrainingPlanType::TRAINING === $type
                && TrainingPlanDiscipline::RUNNING === $discipline
                && $request->request->getBoolean('runHillSessionsEnabled'),
            createdAt: $existing?->getCreatedAt() ?? $now,
            updatedAt: $now,
        );

        $this->repository->upsert($trainingPlan);
        $plannerDataChanged = $this->synchronizeLinkedRacePlan($existing, $trainingPlan, $now);
        $this->rebuildViews($now, $plannerDataChanged);

        return $this->createRedirectResponse($request);
    }

    #[Route(path: '/training-plan/delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $trainingPlanId = $request->request->getString('trainingPlanId');
        if ('' !== $trainingPlanId) {
            $trainingPlan = $this->repository->findById(TrainingPlanId::fromString($trainingPlanId));
            $now = $this->clock->getCurrentDateTimeImmutable();
            $plannerDataChanged = $trainingPlan instanceof TrainingPlan && null !== $trainingPlan->getTargetRaceEventId()
                ? $this->deleteReplaceableUpcomingSessions($trainingPlan, $now)
                : false;

            $this->repository->delete(TrainingPlanId::fromString($trainingPlanId));
            $this->rebuildViews($now, $plannerDataChanged);
        }

        return $this->createRedirectResponse($request);
    }

    private function renderModal(Request $request): Response
    {
        $today = $this->clock->getCurrentDateTimeImmutable()->setTime(0, 0);
        $trainingPlanId = $request->query->getString('trainingPlanId');
        $trainingPlan = '' === $trainingPlanId ? null : $this->repository->findById(TrainingPlanId::fromString($trainingPlanId));
        $afterTrainingPlanId = $request->query->getString('afterTrainingPlanId');
        $afterTrainingPlan = '' === $afterTrainingPlanId ? null : $this->repository->findById(TrainingPlanId::fromString($afterTrainingPlanId));
        $raceEventOptions = $this->loadRaceEventOptions();
        $selectedRaceEvent = $this->findRaceEventById(
            $raceEventOptions,
            $this->nullableRaceEventId($request->query->getString('targetRaceEventId')),
        );
        $defaultStartDay = $this->resolveDefaultStartDay($trainingPlan, $afterTrainingPlan, $selectedRaceEvent, $today);
        $linkedRaceEventIds = $this->buildLinkedRaceEventIds($raceEventOptions, $trainingPlan?->getId());
        $suggestedRaceEvent = $trainingPlan?->getTargetRaceEventId()
            ? $this->findRaceEventById($raceEventOptions, $trainingPlan->getTargetRaceEventId())
            : $selectedRaceEvent;

        if (null === $suggestedRaceEvent && null === $trainingPlan) {
            $suggestedRaceEvent = $this->findSuggestedRaceEvent(
                $raceEventOptions,
                $linkedRaceEventIds,
                $defaultStartDay,
            );
        }

        $defaultEndDay = $this->resolveDefaultEndDay($trainingPlan, $defaultStartDay, $suggestedRaceEvent);

        return new Response($this->twig->render('html/dashboard/training-plan.html.twig', [
            'trainingPlan' => $trainingPlan,
            'afterTrainingPlan' => $afterTrainingPlan,
            'trainingPlanTypeOptions' => TrainingPlanType::cases(),
            'trainingPlanDefaultType' => $trainingPlan?->getType() ?? (null === $suggestedRaceEvent ? TrainingPlanType::TRAINING : TrainingPlanType::RACE),
            'trainingPlanDefaultTitle' => $trainingPlan?->getTitle() ?? $suggestedRaceEvent?->getTitle(),
            'trainingPlanDefaultStartDay' => $trainingPlan?->getStartDay()->format('Y-m-d') ?? $defaultStartDay->format('Y-m-d'),
            'trainingPlanDefaultEndDay' => $trainingPlan?->getEndDay()->format('Y-m-d') ?? $defaultEndDay->format('Y-m-d'),
            'trainingPlanDefaultTargetRaceEventId' => $trainingPlan?->getTargetRaceEventId()?->__toString() ?? $suggestedRaceEvent?->getId()?->__toString(),
            'trainingPlanSuggestedRaceEvent' => $suggestedRaceEvent,
            'trainingPlanRaceEventOptions' => $raceEventOptions,
            'trainingPlanDefaultDiscipline' => $trainingPlan?->getDiscipline() ?? $afterTrainingPlan?->getDiscipline(),
            'trainingPlanDefaultSportSchedule' => $trainingPlan?->getSportSchedule() ?? [],
            'trainingPlanDefaultPerformanceMetrics' => $this->resolveDefaultPerformanceMetrics($trainingPlan, $today),
            'trainingPlanDisciplineOptions' => TrainingPlanDiscipline::cases(),
            'trainingPlanRaceProfileOptionGroups' => $this->buildRaceProfileOptionGroups(),
            'trainingPlanDefaultTargetRaceProfile' => $trainingPlan?->getTargetRaceProfile() ?? $suggestedRaceEvent?->getProfile(),
            'trainingPlanDefaultTrainingFocus' => $trainingPlan?->getTrainingFocus(),
            'trainingPlanDefaultTrainingBlockStyle' => $trainingPlan?->getTrainingBlockStyle() ?? TrainingBlockStyle::BALANCED,
            'trainingPlanDefaultRunningWorkoutTargetMode' => $trainingPlan?->getRunningWorkoutTargetMode() ?? RunningWorkoutTargetMode::TIME,
            'trainingPlanDefaultRunHillSessionsEnabled' => $trainingPlan?->isRunHillSessionsEnabled() ?? false,
            'trainingPlanTrainingFocusOptions' => TrainingFocus::cases(),
            'trainingPlanTrainingBlockStyleOptions' => TrainingBlockStyle::cases(),
            'trainingPlanRunningWorkoutTargetModeOptions' => RunningWorkoutTargetMode::cases(),
            'redirectTo' => $this->resolveRedirectTarget($request),
        ]));
    }

    /**
     * @return list<RaceEvent>
     */
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

    /**
     * @param list<RaceEvent> $raceEvents
     */
    private function findRaceEventById(array $raceEvents, ?RaceEventId $raceEventId): ?RaceEvent
    {
        if (null === $raceEventId) {
            return null;
        }

        foreach ($raceEvents as $raceEvent) {
            if ((string) $raceEvent->getId() === (string) $raceEventId) {
                return $raceEvent;
            }
        }

        return null;
    }

    /**
     * @param list<RaceEvent> $raceEvents
     *
     * @return array<string, true>
     */
    private function buildLinkedRaceEventIds(array $raceEvents, ?TrainingPlanId $excludedTrainingPlanId = null): array
    {
        $linkedRaceEventIds = [];

        foreach ($this->repository->findAll() as $trainingPlan) {
            if (
                null !== $excludedTrainingPlanId
                && (string) $trainingPlan->getId() === (string) $excludedTrainingPlanId
            ) {
                continue;
            }

            if (null !== $trainingPlan->getTargetRaceEventId()) {
                $linkedRaceEventIds[(string) $trainingPlan->getTargetRaceEventId()] = true;
            }

            foreach ($raceEvents as $raceEvent) {
                if (!$trainingPlan->containsDay($raceEvent->getDay())) {
                    continue;
                }

                $linkedRaceEventIds[(string) $raceEvent->getId()] = true;
            }
        }

        return $linkedRaceEventIds;
    }

    /**
     * @param list<RaceEvent> $raceEvents
     * @param array<string, true> $linkedRaceEventIds
     */
    private function findSuggestedRaceEvent(array $raceEvents, array $linkedRaceEventIds, SerializableDateTime $fromDay): ?RaceEvent
    {
        foreach ($raceEvents as $raceEvent) {
            if ($raceEvent->getDay() < $fromDay) {
                continue;
            }

            if (isset($linkedRaceEventIds[(string) $raceEvent->getId()])) {
                continue;
            }

            return $raceEvent;
        }

        return null;
    }

    private function resolveDefaultStartDay(
        ?TrainingPlan $trainingPlan,
        ?TrainingPlan $afterTrainingPlan,
        ?RaceEvent $selectedRaceEvent,
        SerializableDateTime $today,
    ): SerializableDateTime {
        if (null !== $trainingPlan) {
            return $trainingPlan->getStartDay();
        }

        if (null !== $afterTrainingPlan) {
            return $afterTrainingPlan->getEndDay()->modify('+1 day')->setTime(0, 0);
        }

        if (null !== $selectedRaceEvent) {
            $suggestedPlanStartDay = $selectedRaceEvent->getDay()->modify('-12 weeks')->setTime(0, 0);

            return $suggestedPlanStartDay > $today ? $suggestedPlanStartDay : $today;
        }

        return $today;
    }

    private function resolveDefaultEndDay(
        ?TrainingPlan $trainingPlan,
        SerializableDateTime $defaultStartDay,
        ?RaceEvent $suggestedRaceEvent,
    ): SerializableDateTime {
        if (null !== $trainingPlan) {
            return $trainingPlan->getEndDay();
        }

        if (null !== $suggestedRaceEvent && $suggestedRaceEvent->getDay() >= $defaultStartDay) {
            return $suggestedRaceEvent->getDay()->setTime(0, 0);
        }

        return $defaultStartDay->modify('+83 days')->setTime(0, 0);
    }

    private function rebuildViews(?SerializableDateTime $now = null, bool $plannerDataChanged = false): void
    {
        $now ??= $this->clock->getCurrentDateTimeImmutable();

        $this->commandBus->dispatch(new BuildTrainingPlansHtml($now));

        if ($plannerDataChanged) {
            $this->commandBus->dispatch(new BuildDashboardHtml());
            $this->commandBus->dispatch(new BuildMonthlyStatsHtml($now));
        }

        $this->commandBus->dispatch(new BuildRacePlannerHtml($now));
    }

    private function synchronizeLinkedRacePlan(
        ?TrainingPlan $existingTrainingPlan,
        TrainingPlan $savedTrainingPlan,
        SerializableDateTime $now,
    ): bool {
        $plannerDataChanged = false;

        if (null !== $existingTrainingPlan?->getTargetRaceEventId()) {
            $plannerDataChanged = $this->deleteReplaceableUpcomingSessions($existingTrainingPlan, $now);
        }

        if (null === $savedTrainingPlan->getTargetRaceEventId()) {
            return $plannerDataChanged;
        }

        $targetRace = $this->raceEventRepository->findById($savedTrainingPlan->getTargetRaceEventId());
        if (!$targetRace instanceof RaceEvent) {
            return $plannerDataChanged;
        }

        return $this->racePlannerUpcomingSessionRegenerator->regenerate($targetRace, $now)->hasChanges() || $plannerDataChanged;
    }

    private function deleteReplaceableUpcomingSessions(TrainingPlan $trainingPlan, SerializableDateTime $now): bool
    {
        $replaceableSessions = array_values(array_filter(
            $this->plannedSessionRepository->findByDateRange(DateRange::fromDates(
                $trainingPlan->getStartDay()->setTime(0, 0),
                $trainingPlan->getEndDay()->setTime(23, 59, 59),
            )),
            static fn (PlannedSession $plannedSession): bool => $plannedSession->getDay() >= $now->setTime(0, 0)
                && null === $plannedSession->getLinkedActivityId(),
        ));

        foreach ($replaceableSessions as $plannedSession) {
            $this->plannedSessionRepository->delete($plannedSession->getId());
        }

        return [] !== $replaceableSessions;
    }

    private function nullableString(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    private function nullableRaceEventId(?string $value): ?RaceEventId
    {
        $value = $this->nullableString($value);

        return null === $value ? null : RaceEventId::fromString($value);
    }

    private function nullableInt(?string $value): ?int
    {
        $value = $this->nullableString($value);

        return null === $value ? null : (int) $value;
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<int>|null
     */
    private function parseTrainingDays(array $values): ?array
    {
        if ([] === $values) {
            return null;
        }

        $days = [];
        foreach ($values as $v) {
            $day = (int) $v;
            if ($day >= 1 && $day <= 7) {
                $days[] = $day;
            }
        }

        sort($days);

        return [] === $days ? null : array_values(array_unique($days));
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

        return $this->sanitizeRedirectTarget(is_string($referer) ? $referer : null) ?? '/training-plans';
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

    private function parseDiscipline(string $value): ?TrainingPlanDiscipline
    {
        $value = trim($value);

        return '' === $value ? null : TrainingPlanDiscipline::from($value);
    }

    private function parseRaceProfile(string $value): ?RaceEventProfile
    {
        $value = trim($value);

        return '' === $value ? null : RaceEventProfile::tryFrom($value);
    }

    private function parseTrainingFocus(string $value): ?TrainingFocus
    {
        $value = trim($value);

        return '' === $value ? null : TrainingFocus::tryFrom($value);
    }

    private function parseTrainingBlockStyle(string $value): ?TrainingBlockStyle
    {
        $value = trim($value);

        return '' === $value ? null : TrainingBlockStyle::tryFrom($value);
    }

    private function parseRunningWorkoutTargetMode(string $value): ?RunningWorkoutTargetMode
    {
        $value = trim($value);

        return '' === $value ? null : RunningWorkoutTargetMode::tryFrom($value);
    }

    /**
     * @return list<array{family: RaceEventFamily, options: list<array{profile: RaceEventProfile, disciplineValues: list<string>}>}>
     */
    private function buildRaceProfileOptionGroups(): array
    {
        $grouped = [];

        foreach (RaceEventProfile::cases() as $profile) {
            $family = $profile->getFamily();
            $grouped[$family->value] ??= [
                'family' => $family,
                'options' => [],
            ];
            $grouped[$family->value]['options'][] = [
                'profile' => $profile,
                'disciplineValues' => array_map(
                    static fn (TrainingPlanDiscipline $discipline): string => $discipline->value,
                    $this->resolveCompatibleDisciplinesForRaceProfile($profile),
                ),
            ];
        }

        return array_values($grouped);
    }

    /**
     * @return list<TrainingPlanDiscipline>
     */
    private function resolveCompatibleDisciplinesForRaceProfile(RaceEventProfile $profile): array
    {
        return match ($profile->getFamily()) {
            RaceEventFamily::TRIATHLON,
            RaceEventFamily::MULTISPORT,
            RaceEventFamily::SWIM => [TrainingPlanDiscipline::TRIATHLON],
            RaceEventFamily::RIDE => [TrainingPlanDiscipline::CYCLING],
            RaceEventFamily::RUN,
            RaceEventFamily::OTHER => [TrainingPlanDiscipline::RUNNING],
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseSportSchedule(Request $request): ?array
    {
        $schedule = [];
        foreach (['swimDays', 'bikeDays', 'runDays', 'longRideDays', 'longRunDays'] as $key) {
            $days = $this->parseTrainingDays($request->request->all($key));
            if (null !== $days) {
                $schedule[$key] = $days;
            }
        }

        return [] === $schedule ? null : $schedule;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parsePerformanceMetrics(Request $request): ?array
    {
        $metrics = [];

        $cyclingFtp = $this->nullableInt($request->request->getString('cyclingFtp'));
        if (null !== $cyclingFtp) {
            $metrics['cyclingFtp'] = $cyclingFtp;
        }

        $runningThresholdPace = $this->nullableInt($request->request->getString('runningThresholdPace'));
        if (null !== $runningThresholdPace) {
            $metrics['runningThresholdPace'] = $runningThresholdPace;
        }

        $swimmingCss = $this->nullableInt($request->request->getString('swimmingCss'));
        if (null !== $swimmingCss) {
            $metrics['swimmingCss'] = $swimmingCss;
        }

        $weeklyRunningVolume = $this->nullableFloat($request->request->getString('weeklyRunningVolume'));
        if (null !== $weeklyRunningVolume) {
            $metrics['weeklyRunningVolume'] = $weeklyRunningVolume;
        }

        $weeklyBikingVolume = $this->nullableFloat($request->request->getString('weeklyBikingVolume'));
        if (null !== $weeklyBikingVolume) {
            $metrics['weeklyBikingVolume'] = $weeklyBikingVolume;
        }

        return [] === $metrics ? null : $metrics;
    }

    private function nullableFloat(?string $value): ?float
    {
        $value = $this->nullableString($value);

        return null === $value ? null : (float) $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveDefaultPerformanceMetrics(?TrainingPlan $trainingPlan, SerializableDateTime $today): array
    {
        // If editing, use stored values.
        if (null !== $trainingPlan && null !== $trainingPlan->getPerformanceMetrics()) {
            return $trainingPlan->getPerformanceMetrics();
        }

        $metrics = [];

        // Cycling FTP from PerformanceAnchorHistory.
        try {
            $cyclingAnchor = $this->performanceAnchorHistory->find(
                PerformanceAnchorType::CYCLING_THRESHOLD_POWER,
                $today,
            );
            $metrics['cyclingFtp'] = (int) $cyclingAnchor->getValue();
        } catch (\Throwable) {
        }

        // Swimming CSS from PerformanceAnchorHistory (stored as m/s, convert to seconds/100m).
        try {
            $swimmingAnchor = $this->performanceAnchorHistory->find(
                PerformanceAnchorType::SWIMMING_CRITICAL_SPEED,
                $today,
            );
            $speedMs = $swimmingAnchor->getValue();
            if ($speedMs > 0) {
                $metrics['swimmingCss'] = (int) round(100.0 / $speedMs);
            }
        } catch (\Throwable) {
        }

        // Running threshold pace: derive from running FTP if available.
        // Running FTP is stored as watts — not directly convertible to pace without more data.
        // Skip auto-prefill for running pace; user enters manually.

        // Weekly running volume (km/week) — average over last 6 weeks.
        $sixWeeksAgo = $today->modify('-6 weeks');
        $runVolume = $this->connection->executeQuery(
            'SELECT SUM(distance) / 1000.0 as totalKm
             FROM Activity
             WHERE sportType IN (\'Run\', \'TrailRun\', \'VirtualRun\')
               AND startDateTime >= :from',
            ['from' => $sixWeeksAgo->format('Y-m-d')]
        )->fetchOne();
        if (is_numeric($runVolume) && (float) $runVolume > 0) {
            $metrics['weeklyRunningVolume'] = round((float) $runVolume / 6, 1);
        }

        // Weekly biking volume (hours/week) — average over last 6 weeks.
        $bikeVolume = $this->connection->executeQuery(
            'SELECT SUM(movingTimeInSeconds) / 3600.0 as totalHours
             FROM Activity
             WHERE sportType IN (\'Ride\', \'MountainBikeRide\', \'GravelRide\', \'EBikeRide\', \'EMountainBikeRide\', \'VirtualRide\')
               AND startDateTime >= :from',
            ['from' => $sixWeeksAgo->format('Y-m-d')]
        )->fetchOne();
        if (is_numeric($bikeVolume) && (float) $bikeVolume > 0) {
            $metrics['weeklyBikingVolume'] = round((float) $bikeVolume / 6, 1);
        }

        return $metrics;
    }
}
