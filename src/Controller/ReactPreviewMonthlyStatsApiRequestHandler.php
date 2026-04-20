<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Build\BuildMonthlyStatsHtml\CurrentWeekCoachInsightsBuilder;
use App\Domain\Activity\Activities;
use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityType;
use App\Domain\Activity\EnrichedActivities;
use App\Domain\Calendar\Calendar;
use App\Domain\Calendar\Day;
use App\Domain\Calendar\FindMonthlyStats\FindMonthlyStats;
use App\Domain\Calendar\Month;
use App\Domain\Calendar\Months;
use App\Domain\Calendar\Week;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Domain\TrainingPlanner\PlannedSessionLinkStatus;
use App\Domain\TrainingPlanner\PlannedSessionLoadEstimator;
use App\Domain\TrainingPlanner\PlannedSessionRepository;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventPriority;
use App\Domain\TrainingPlanner\RaceEventRepository;
use App\Domain\TrainingPlanner\TrainingBlock;
use App\Domain\TrainingPlanner\TrainingBlockPhase;
use App\Domain\TrainingPlanner\TrainingBlockRepository;
use App\Infrastructure\CQRS\Query\Bus\QueryBus;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsController]
final readonly class ReactPreviewMonthlyStatsApiRequestHandler
{
    public function __construct(
        private CurrentAppUser $currentAppUser,
        private Clock $clock,
        private EnrichedActivities $enrichedActivities,
        private PlannedSessionRepository $plannedSessionRepository,
        private RaceEventRepository $raceEventRepository,
        private TrainingBlockRepository $trainingBlockRepository,
        private PlannedSessionLoadEstimator $plannedSessionLoadEstimator,
        private CurrentWeekCoachInsightsBuilder $currentWeekCoachInsightsBuilder,
        private QueryBus $queryBus,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/react-preview/api/monthly-stats', methods: ['GET'], priority: 6)]
    public function handle(Request $request): JsonResponse
    {
        $this->currentAppUser->require();

        $now = $this->clock->getCurrentDateTimeImmutable();
        [$startDate, $endDate] = $this->resolveTimelineBounds($now);
        $allMonths = Months::create($startDate, $endDate);
        $selectedMonth = $this->resolveSelectedMonth(
            requestedMonthId: $request->query->getString('month'),
            allMonths: $allMonths,
            fallbackMonth: Month::fromDate($now),
        );

        $dateRange = DateRange::fromDates(
            $startDate->modify('first day of this month')->setTime(0, 0),
            $endDate->modify('last day of this month')->setTime(23, 59, 59),
        );

        $plannedSessions = $this->plannedSessionRepository->findByDateRange($dateRange);
        $plannedSessionEstimatesById = $this->buildPlannedSessionEstimatesById($plannedSessions);
        $plannedSessionsByMonth = $this->groupPlannedSessionsByMonth($plannedSessions);
        $plannedSessionsByDay = $this->groupPlannedSessionsByDay($plannedSessions);

        $raceEvents = $this->raceEventRepository->findByDateRange($dateRange);
        $raceEventsByMonth = $this->groupRaceEventsByMonth($raceEvents);
        $raceEventsByDay = $this->groupRaceEventsByDay($raceEvents);
        $raceEventsById = $this->buildRaceEventsById($raceEvents);
        $raceEventCountdownDaysById = $this->buildRaceEventCountdownDaysById($raceEvents, $now);
        $upcomingRaceEvents = $this->raceEventRepository->findUpcoming($now, 4);

        $trainingBlocks = $this->trainingBlockRepository->findByDateRange($dateRange);
        $trainingBlocksByMonth = $this->groupTrainingBlocksByMonth($trainingBlocks);
        $trainingBlocksByDay = $this->groupTrainingBlocksByDay($trainingBlocks);
        $currentAndUpcomingTrainingBlocks = $this->trainingBlockRepository->findCurrentAndUpcoming($now, 4);
        $currentTrainingBlock = $this->findCurrentTrainingBlock($currentAndUpcomingTrainingBlocks, $now);

        $currentWeek = Week::fromDate($now);
        $currentWeekPlannedSessions = $this->findPlannedSessionsInWeek($plannedSessions, $currentWeek);
        $currentWeekRaceEvents = $this->findRaceEventsInWeek($raceEvents, $currentWeek);
        $currentWeekTrainingBlocks = $this->findTrainingBlocksInWeek($trainingBlocks, $currentWeek);
        $currentWeekCoachInsights = $this->currentWeekCoachInsightsBuilder->build(
            referenceDate: $now,
            plannedSessions: $currentWeekPlannedSessions,
            raceEvents: $currentWeekRaceEvents,
            trainingBlocks: $currentWeekTrainingBlocks,
            currentTrainingBlock: $currentTrainingBlock,
            raceEventsById: $raceEventsById,
            plannedSessionEstimatesById: $plannedSessionEstimatesById,
        );

        $monthlyStats = $this->queryBus->ask(new FindMonthlyStats());
        $selectedMonthStatistics = $monthlyStats->getForMonth($selectedMonth);
        $selectedMonthPlannedSessions = $plannedSessionsByMonth[$selectedMonth->getId()] ?? [];
        $selectedMonthRaceEvents = $raceEventsByMonth[$selectedMonth->getId()] ?? [];
        $selectedMonthTrainingBlocks = $trainingBlocksByMonth[$selectedMonth->getId()] ?? [];
        $selectedMonthEstimatedLoad = array_sum(array_map(
            fn (PlannedSession $plannedSession): float => $plannedSessionEstimatesById[(string) $plannedSession->getId()] ?? 0.0,
            $selectedMonthPlannedSessions,
        ));
        $selectedMonthDistance = null === $selectedMonthStatistics ? 0.0 : round($selectedMonthStatistics['distance']->toFloat(), 2);
        $selectedMonthElevation = null === $selectedMonthStatistics ? 0 : $selectedMonthStatistics['elevation']->toInt();
        $selectedMonthMovingTime = null === $selectedMonthStatistics ? 0 : $selectedMonthStatistics['movingTime']->toInt();
        $selectedMonthCalories = null === $selectedMonthStatistics ? 0 : $selectedMonthStatistics['calories'];

        $calendar = Calendar::create(
            month: $selectedMonth,
            enrichedActivities: $this->enrichedActivities,
            plannedSessionsByDay: $plannedSessionsByDay,
            raceEventsByDay: $raceEventsByDay,
            trainingBlocksByDay: $trainingBlocksByDay,
        );

        return new JsonResponse([
            'requestedAt' => $now->format(DATE_ATOM),
            'summary' => [
                'monthCount' => count($allMonths),
                'totalActivities' => $selectedMonthStatistics['numberOfActivities'] ?? 0,
                'totalDistance' => $selectedMonthDistance,
                'totalElevation' => $selectedMonthElevation,
                'totalMovingTime' => $selectedMonthMovingTime,
                'totalCalories' => $selectedMonthCalories,
                'plannedSessionCount' => count($selectedMonthPlannedSessions),
                'linkedPlannedSessionCount' => count(array_filter(
                    $selectedMonthPlannedSessions,
                    static fn (PlannedSession $plannedSession): bool => PlannedSessionLinkStatus::LINKED === $plannedSession->getLinkStatus(),
                )),
                'raceEventCount' => count($selectedMonthRaceEvents),
                'trainingBlockCount' => count($selectedMonthTrainingBlocks),
                'estimatedPlannedLoad' => round($selectedMonthEstimatedLoad, 1),
            ],
            'navigation' => $this->serializeNavigation($selectedMonth, $allMonths, $now),
            'month' => [
                'id' => $selectedMonth->getId(),
                'label' => $selectedMonth->getLabel(),
                'isCurrentMonth' => $selectedMonth->getId() === Month::fromDate($now)->getId(),
                'legacyPath' => $this->buildLegacyPath($selectedMonth, $now),
                'activityTypeBreakdown' => $this->buildActivityTypeBreakdown($monthlyStats, $selectedMonth),
            ],
            'currentWeek' => $this->serializeCurrentWeek(
                week: $currentWeek,
                plannedSessions: $currentWeekPlannedSessions,
                raceEvents: $currentWeekRaceEvents,
                trainingBlocks: $currentWeekTrainingBlocks,
                estimatedLoad: $currentWeekCoachInsights->getEstimatedLoad(),
                activityTypeSummaries: $currentWeekCoachInsights->getActivityTypeSummaries(),
                keySessionIds: $currentWeekCoachInsights->getKeySessionIds(),
                brickSessionIds: $currentWeekCoachInsights->getBrickSessionIds(),
                raceIntent: $currentWeekCoachInsights->getRaceIntent(),
                coachCues: $currentWeekCoachInsights->getCoachCues(),
                plannedSessionEstimatesById: $plannedSessionEstimatesById,
                raceEventCountdownDaysById: $raceEventCountdownDaysById,
            ),
            'upcomingRaceEvents' => array_map(
                fn (RaceEvent $raceEvent): array => $this->serializeRaceEvent($raceEvent, $raceEventCountdownDaysById),
                $upcomingRaceEvents,
            ),
            'trainingBlocks' => [
                'current' => $currentTrainingBlock ? $this->serializeTrainingBlock($currentTrainingBlock) : null,
                'upcoming' => array_values(array_map(
                    fn (TrainingBlock $trainingBlock): array => $this->serializeTrainingBlock($trainingBlock),
                    array_filter(
                        $currentAndUpcomingTrainingBlocks,
                        static fn (TrainingBlock $trainingBlock): bool => null === $currentTrainingBlock || (string) $trainingBlock->getId() !== (string) $currentTrainingBlock->getId(),
                    ),
                )),
            ],
            'calendar' => [
                'weeks' => $this->serializeCalendarWeeks(
                    calendar: $calendar,
                    plannedSessionEstimatesById: $plannedSessionEstimatesById,
                    raceEventCountdownDaysById: $raceEventCountdownDaysById,
                    keySessionIds: $currentWeekCoachInsights->getKeySessionIds(),
                    brickSessionIds: $currentWeekCoachInsights->getBrickSessionIds(),
                ),
            ],
        ]);
    }

    /**
     * @return array{0: SerializableDateTime, 1: SerializableDateTime}
     */
    private function resolveTimelineBounds(SerializableDateTime $now): array
    {
        $startDate = $now;
        $allActivities = $this->enrichedActivities->findAll();

        try {
            $startDate = $allActivities->getFirstActivityStartDate();
        } catch (\RuntimeException) {
            $startDate = $now;
        }

        $earliestPlannedSession = $this->plannedSessionRepository->findEarliest();
        $latestPlannedSession = $this->plannedSessionRepository->findLatest();
        $earliestRaceEvent = $this->raceEventRepository->findEarliest();
        $latestRaceEvent = $this->raceEventRepository->findLatest();
        $earliestTrainingBlock = $this->trainingBlockRepository->findEarliest();
        $latestTrainingBlock = $this->trainingBlockRepository->findLatest();

        if (null !== $earliestPlannedSession && $earliestPlannedSession->getDay() < $startDate) {
            $startDate = $earliestPlannedSession->getDay();
        }
        if (null !== $earliestRaceEvent && $earliestRaceEvent->getDay() < $startDate) {
            $startDate = $earliestRaceEvent->getDay();
        }
        if (null !== $earliestTrainingBlock && $earliestTrainingBlock->getStartDay() < $startDate) {
            $startDate = $earliestTrainingBlock->getStartDay();
        }

        $endDate = $now;
        if (null !== $latestPlannedSession && $latestPlannedSession->getDay() > $endDate) {
            $endDate = $latestPlannedSession->getDay();
        }
        if (null !== $latestRaceEvent && $latestRaceEvent->getDay() > $endDate) {
            $endDate = $latestRaceEvent->getDay();
        }
        if (null !== $latestTrainingBlock && $latestTrainingBlock->getEndDay() > $endDate) {
            $endDate = $latestTrainingBlock->getEndDay();
        }

        return [$startDate, $endDate];
    }

    private function resolveSelectedMonth(string $requestedMonthId, Months $allMonths, Month $fallbackMonth): Month
    {
        $requestedMonthId = preg_replace('/^month-/', '', trim($requestedMonthId)) ?? '';

        if ('' !== $requestedMonthId && preg_match('/^\d{4}-\d{2}$/', $requestedMonthId)) {
            foreach ($allMonths as $month) {
                if ($month->getId() === $requestedMonthId) {
                    return $month;
                }
            }
        }

        foreach ($allMonths as $month) {
            if ($month->getId() === $fallbackMonth->getId()) {
                return $month;
            }
        }

        /** @var Month|null $firstMonth */
        $firstMonth = $allMonths->getFirst();

        return $firstMonth ?? $fallbackMonth;
    }

    /**
     * @return array{currentMonthId: string, currentMonthLabel: string, previousMonthId: string|null, previousMonthLabel: string|null, nextMonthId: string|null, nextMonthLabel: string|null, hasPrevious: bool, hasNext: bool, legacyPath: string}
     */
    private function serializeNavigation(Month $selectedMonth, Months $allMonths, SerializableDateTime $now): array
    {
        $months = [];
        foreach ($allMonths as $month) {
            $months[] = $month;
        }

        $selectedIndex = 0;
        foreach ($months as $index => $month) {
            if ($month->getId() === $selectedMonth->getId()) {
                $selectedIndex = $index;
                break;
            }
        }

        $previousMonth = $months[$selectedIndex - 1] ?? null;
        $nextMonth = $months[$selectedIndex + 1] ?? null;

        return [
            'currentMonthId' => $selectedMonth->getId(),
            'currentMonthLabel' => $selectedMonth->getLabel(),
            'previousMonthId' => $previousMonth?->getId(),
            'previousMonthLabel' => $previousMonth?->getLabel(),
            'nextMonthId' => $nextMonth?->getId(),
            'nextMonthLabel' => $nextMonth?->getLabel(),
            'hasPrevious' => null !== $previousMonth,
            'hasNext' => null !== $nextMonth,
            'legacyPath' => $this->buildLegacyPath($selectedMonth, $now),
        ];
    }

    private function buildLegacyPath(Month $selectedMonth, SerializableDateTime $now): string
    {
        $currentMonth = Month::fromDate($now);

        return $selectedMonth->getId() === $currentMonth->getId()
            ? 'monthly-stats'
            : sprintf('monthly-stats/month-%s', $selectedMonth->getId());
    }

    /**
     * @return list<array{activityType: string, label: string, color: string, count: int, distance: float, elevation: int, movingTime: int, calories: int}>
     */
    private function buildActivityTypeBreakdown(mixed $monthlyStats, Month $month): array
    {
        $breakdown = [];

        foreach (ActivityType::cases() as $activityType) {
            $stats = $monthlyStats->getForMonthAndActivityType($month, $activityType);
            if (null === $stats) {
                continue;
            }

            $breakdown[] = [
                'activityType' => $activityType->value,
                'label' => $activityType->trans($this->translator),
                'color' => $activityType->getColor(),
                'count' => $stats['numberOfActivities'],
                'distance' => round($stats['distance']->toFloat(), 2),
                'elevation' => $stats['elevation']->toInt(),
                'movingTime' => $stats['movingTime']->toInt(),
                'calories' => $stats['calories'],
            ];
        }

        usort($breakdown, static fn (array $left, array $right): int => $right['count'] <=> $left['count']);

        return $breakdown;
    }

    /**
     * @param list<PlannedSession> $plannedSessions
     * @param list<RaceEvent> $raceEvents
     * @param list<TrainingBlock> $trainingBlocks
     * @param list<array{activityType: ActivityType, count: int}> $activityTypeSummaries
     * @param array<string, true> $keySessionIds
     * @param array<string, true> $brickSessionIds
     * @param null|array{label: string, tone: string, title: string, body: string} $raceIntent
     * @param list<array{tone: string, title: string, body: string}> $coachCues
     * @param array<string, null|float> $plannedSessionEstimatesById
     * @param array<string, int> $raceEventCountdownDaysById
     *
     * @return array<string, mixed>
     */
    private function serializeCurrentWeek(
        Week $week,
        array $plannedSessions,
        array $raceEvents,
        array $trainingBlocks,
        float $estimatedLoad,
        array $activityTypeSummaries,
        array $keySessionIds,
        array $brickSessionIds,
        ?array $raceIntent,
        array $coachCues,
        array $plannedSessionEstimatesById,
        array $raceEventCountdownDaysById,
    ): array {
        return [
            'from' => $week->getFrom()->format('Y-m-d'),
            'to' => $week->getTo()->format('Y-m-d'),
            'estimatedLoad' => round($estimatedLoad, 1),
            'plannedSessionCount' => count($plannedSessions),
            'raceEventCount' => count($raceEvents),
            'trainingBlockCount' => count($trainingBlocks),
            'keySessionIds' => array_keys($keySessionIds),
            'brickSessionIds' => array_keys($brickSessionIds),
            'activityTypeSummaries' => array_map(
                fn (array $summary): array => [
                    'activityType' => $summary['activityType']->value,
                    'label' => $summary['activityType']->trans($this->translator),
                    'count' => $summary['count'],
                ],
                $activityTypeSummaries,
            ),
            'raceIntent' => $raceIntent,
            'coachCues' => $coachCues,
            'plannedSessions' => array_map(
                fn (PlannedSession $plannedSession): array => $this->serializePlannedSession($plannedSession, $plannedSessionEstimatesById, $keySessionIds, $brickSessionIds),
                $plannedSessions,
            ),
            'raceEvents' => array_map(
                fn (RaceEvent $raceEvent): array => $this->serializeRaceEvent($raceEvent, $raceEventCountdownDaysById),
                $raceEvents,
            ),
            'trainingBlocks' => array_map(
                fn (TrainingBlock $trainingBlock): array => $this->serializeTrainingBlock($trainingBlock),
                $trainingBlocks,
            ),
        ];
    }

    /**
     * @param array<string, null|float> $plannedSessionEstimatesById
     * @param array<string, true> $keySessionIds
     * @param array<string, true> $brickSessionIds
     *
     * @return array{id: string, title: string, day: string, activityType: string, label: string, targetIntensity: string|null, targetIntensityLabel: string|null, linkStatus: string, estimatedLoad: float|null, durationInSeconds: int|null, isKeySession: bool, isBrickSession: bool}
     */
    private function serializePlannedSession(PlannedSession $plannedSession, array $plannedSessionEstimatesById, array $keySessionIds, array $brickSessionIds): array
    {
        $targetIntensity = $plannedSession->getTargetIntensity();

        return [
            'id' => (string) $plannedSession->getId(),
            'title' => $plannedSession->getTitle() ?? $plannedSession->getActivityType()->trans($this->translator),
            'day' => $plannedSession->getDay()->format('Y-m-d'),
            'activityType' => $plannedSession->getActivityType()->value,
            'label' => $plannedSession->getActivityType()->trans($this->translator),
            'targetIntensity' => $targetIntensity?->value,
            'targetIntensityLabel' => $targetIntensity instanceof PlannedSessionIntensity ? $targetIntensity->getLabel() : null,
            'linkStatus' => $plannedSession->getLinkStatus()->value,
            'estimatedLoad' => isset($plannedSessionEstimatesById[(string) $plannedSession->getId()])
                ? round((float) $plannedSessionEstimatesById[(string) $plannedSession->getId()], 1)
                : null,
            'durationInSeconds' => $plannedSession->getTargetDurationInSeconds() ?? $plannedSession->getWorkoutDurationInSeconds(),
            'isKeySession' => isset($keySessionIds[(string) $plannedSession->getId()]),
            'isBrickSession' => isset($brickSessionIds[(string) $plannedSession->getId()]),
        ];
    }

    /**
     * @param array<string, int> $raceEventCountdownDaysById
     *
     * @return array{id: string, title: string, day: string, profile: string, profileLabel: string, priority: string, priorityLabel: string, location: string|null, countdownDays: int|null}
     */
    private function serializeRaceEvent(RaceEvent $raceEvent, array $raceEventCountdownDaysById): array
    {
        return [
            'id' => (string) $raceEvent->getId(),
            'title' => $raceEvent->getTitle() ?? $raceEvent->getType()->trans($this->translator),
            'day' => $raceEvent->getDay()->format('Y-m-d'),
            'profile' => $raceEvent->getProfile()->value,
            'profileLabel' => $raceEvent->getProfile()->trans($this->translator),
            'priority' => $raceEvent->getPriority()->value,
            'priorityLabel' => $raceEvent->getPriority()->trans($this->translator),
            'location' => $raceEvent->getLocation(),
            'countdownDays' => $raceEventCountdownDaysById[(string) $raceEvent->getId()] ?? null,
        ];
    }

    /**
     * @return array{id: string, title: string, startDay: string, endDay: string, phase: string, phaseLabel: string, focus: string|null}
     */
    private function serializeTrainingBlock(TrainingBlock $trainingBlock): array
    {
        return [
            'id' => (string) $trainingBlock->getId(),
            'title' => $trainingBlock->getTitle() ?? $trainingBlock->getPhase()->trans($this->translator),
            'startDay' => $trainingBlock->getStartDay()->format('Y-m-d'),
            'endDay' => $trainingBlock->getEndDay()->format('Y-m-d'),
            'phase' => $trainingBlock->getPhase()->value,
            'phaseLabel' => $trainingBlock->getPhase()->trans($this->translator),
            'focus' => $trainingBlock->getFocus(),
        ];
    }

    /**
     * @param array<string, null|float> $plannedSessionEstimatesById
     * @param array<string, int> $raceEventCountdownDaysById
     * @param array<string, true> $keySessionIds
     * @param array<string, true> $brickSessionIds
     *
     * @return list<array{id: string, days: list<array<string, mixed>>}>
     */
    private function serializeCalendarWeeks(
        Calendar $calendar,
        array $plannedSessionEstimatesById,
        array $raceEventCountdownDaysById,
        array $keySessionIds,
        array $brickSessionIds,
    ): array {
        $serializedDays = array_map(
            fn (Day $day): array => $this->serializeCalendarDay($day, $plannedSessionEstimatesById, $raceEventCountdownDaysById, $keySessionIds, $brickSessionIds),
            iterator_to_array($calendar->getDays()),
        );

        $weeks = [];
        foreach (array_chunk($serializedDays, 7) as $weekIndex => $days) {
            $weeks[] = [
                'id' => sprintf('%s-week-%d', $calendar->getMonth()->getId(), $weekIndex + 1),
                'days' => $days,
            ];
        }

        return $weeks;
    }

    /**
     * @param array<string, null|float> $plannedSessionEstimatesById
     * @param array<string, int> $raceEventCountdownDaysById
     * @param array<string, true> $keySessionIds
     * @param array<string, true> $brickSessionIds
     *
     * @return array<string, mixed>
     */
    private function serializeCalendarDay(Day $day, array $plannedSessionEstimatesById, array $raceEventCountdownDaysById, array $keySessionIds, array $brickSessionIds): array
    {
        return [
            'date' => $day->getDate()->format('Y-m-d'),
            'dayNumber' => $day->getDayNumber(),
            'isCurrentMonth' => $day->isCurrentMonth(),
            'isToday' => $day->getDate()->format('Y-m-d') === $this->clock->getCurrentDateTimeImmutable()->format('Y-m-d'),
            'trainingBlockPhase' => $this->resolveTrainingBlockPhase($day->getTrainingBlocks()),
            'racePriority' => $this->resolveRacePriority($day->getRaceEvents()),
            'activities' => $this->serializeActivities($day->getActivities()),
            'plannedSessions' => array_map(
                fn (PlannedSession $plannedSession): array => $this->serializePlannedSession($plannedSession, $plannedSessionEstimatesById, $keySessionIds, $brickSessionIds),
                $day->getPlannedSessions(),
            ),
            'raceEvents' => array_map(
                fn (RaceEvent $raceEvent): array => $this->serializeRaceEvent($raceEvent, $raceEventCountdownDaysById),
                $day->getRaceEvents(),
            ),
            'trainingBlocks' => array_map(
                fn (TrainingBlock $trainingBlock): array => $this->serializeTrainingBlock($trainingBlock),
                $day->getTrainingBlocks(),
            ),
        ];
    }

    /**
     * @return list<array{id: string, name: string, activityType: string, label: string, distance: float, elevation: int, movingTime: int}>
     */
    private function serializeActivities(Activities $activities): array
    {
        $serialized = [];

        /** @var Activity $activity */
        foreach ($activities as $activity) {
            $serialized[] = [
                'id' => (string) $activity->getId(),
                'name' => $activity->getName(),
                'activityType' => $activity->getSportType()->getActivityType()->value,
                'label' => $activity->getSportType()->getActivityType()->trans($this->translator),
                'distance' => round($activity->getDistance()->toFloat(), 2),
                'elevation' => $activity->getElevation()->toInt(),
                'movingTime' => $activity->getMovingTimeInSeconds(),
            ];
        }

        return $serialized;
    }

    /**
     * @param list<TrainingBlock> $trainingBlocks
     */
    private function resolveTrainingBlockPhase(array $trainingBlocks): ?string
    {
        $firstTrainingBlock = $trainingBlocks[0] ?? null;

        return $firstTrainingBlock instanceof TrainingBlock ? $firstTrainingBlock->getPhase()->value : null;
    }

    /**
     * @param list<RaceEvent> $raceEvents
     */
    private function resolveRacePriority(array $raceEvents): ?string
    {
        foreach ([RaceEventPriority::A, RaceEventPriority::B, RaceEventPriority::C] as $priority) {
            foreach ($raceEvents as $raceEvent) {
                if ($raceEvent->getPriority() === $priority) {
                    return $priority->value;
                }
            }
        }

        return null;
    }

    /**
     * @param list<PlannedSession> $plannedSessions
     *
     * @return array<string, list<PlannedSession>>
     */
    private function groupPlannedSessionsByMonth(array $plannedSessions): array
    {
        $groupedPlannedSessions = [];

        foreach ($plannedSessions as $plannedSession) {
            $groupedPlannedSessions[$plannedSession->getDay()->format(Month::MONTH_ID_FORMAT)][] = $plannedSession;
        }

        return $groupedPlannedSessions;
    }

    /**
     * @param list<PlannedSession> $plannedSessions
     *
     * @return array<string, list<PlannedSession>>
     */
    private function groupPlannedSessionsByDay(array $plannedSessions): array
    {
        $groupedPlannedSessions = [];

        foreach ($plannedSessions as $plannedSession) {
            $groupedPlannedSessions[$plannedSession->getDay()->format('Y-m-d')][] = $plannedSession;
        }

        return $groupedPlannedSessions;
    }

    /**
     * @param list<RaceEvent> $raceEvents
     *
     * @return array<string, list<RaceEvent>>
     */
    private function groupRaceEventsByMonth(array $raceEvents): array
    {
        $groupedRaceEvents = [];

        foreach ($raceEvents as $raceEvent) {
            $groupedRaceEvents[$raceEvent->getDay()->format(Month::MONTH_ID_FORMAT)][] = $raceEvent;
        }

        return $groupedRaceEvents;
    }

    /**
     * @param list<RaceEvent> $raceEvents
     *
     * @return array<string, list<RaceEvent>>
     */
    private function groupRaceEventsByDay(array $raceEvents): array
    {
        $groupedRaceEvents = [];

        foreach ($raceEvents as $raceEvent) {
            $groupedRaceEvents[$raceEvent->getDay()->format('Y-m-d')][] = $raceEvent;
        }

        return $groupedRaceEvents;
    }

    /**
     * @param list<RaceEvent> $raceEvents
     *
     * @return array<string, RaceEvent>
     */
    private function buildRaceEventsById(array $raceEvents): array
    {
        $indexedRaceEvents = [];

        foreach ($raceEvents as $raceEvent) {
            $indexedRaceEvents[(string) $raceEvent->getId()] = $raceEvent;
        }

        return $indexedRaceEvents;
    }

    /**
     * @param list<TrainingBlock> $trainingBlocks
     *
     * @return array<string, list<TrainingBlock>>
     */
    private function groupTrainingBlocksByMonth(array $trainingBlocks): array
    {
        $groupedTrainingBlocks = [];

        foreach ($trainingBlocks as $trainingBlock) {
            foreach (Months::create($trainingBlock->getStartDay(), $trainingBlock->getEndDay()) as $month) {
                $groupedTrainingBlocks[$month->getId()][] = $trainingBlock;
            }
        }

        return $groupedTrainingBlocks;
    }

    /**
     * @param list<TrainingBlock> $trainingBlocks
     *
     * @return array<string, list<TrainingBlock>>
     */
    private function groupTrainingBlocksByDay(array $trainingBlocks): array
    {
        $groupedTrainingBlocks = [];

        foreach ($trainingBlocks as $trainingBlock) {
            $currentDay = $trainingBlock->getStartDay();
            while ($currentDay <= $trainingBlock->getEndDay()) {
                $groupedTrainingBlocks[$currentDay->format('Y-m-d')][] = $trainingBlock;
                $currentDay = SerializableDateTime::fromDateTimeImmutable($currentDay->modify('+1 day'));
            }
        }

        return $groupedTrainingBlocks;
    }

    /**
     * @param list<RaceEvent> $raceEvents
     *
     * @return array<string, int>
     */
    private function buildRaceEventCountdownDaysById(array $raceEvents, \DateTimeInterface $referenceDate): array
    {
        $countdowns = [];
        $referenceDate = SerializableDateTime::fromString($referenceDate->format('Y-m-d 00:00:00'));

        foreach ($raceEvents as $raceEvent) {
            $countdowns[(string) $raceEvent->getId()] = (int) $referenceDate->diff($raceEvent->getDay())->format('%r%a');
        }

        return $countdowns;
    }

    /**
     * @param list<TrainingBlock> $trainingBlocks
     */
    private function findCurrentTrainingBlock(array $trainingBlocks, SerializableDateTime $referenceDate): ?TrainingBlock
    {
        foreach ($trainingBlocks as $trainingBlock) {
            if ($trainingBlock->containsDay($referenceDate)) {
                return $trainingBlock;
            }
        }

        return null;
    }

    /**
     * @param list<PlannedSession> $plannedSessions
     *
     * @return list<PlannedSession>
     */
    private function findPlannedSessionsInWeek(array $plannedSessions, Week $week): array
    {
        return array_values(array_filter(
            $plannedSessions,
            static fn (PlannedSession $plannedSession): bool => $plannedSession->getDay() >= $week->getFrom()
                && $plannedSession->getDay() <= $week->getTo(),
        ));
    }

    /**
     * @param list<RaceEvent> $raceEvents
     *
     * @return list<RaceEvent>
     */
    private function findRaceEventsInWeek(array $raceEvents, Week $week): array
    {
        return array_values(array_filter(
            $raceEvents,
            static fn (RaceEvent $raceEvent): bool => $raceEvent->getDay() >= $week->getFrom()
                && $raceEvent->getDay() <= $week->getTo(),
        ));
    }

    /**
     * @param list<TrainingBlock> $trainingBlocks
     *
     * @return list<TrainingBlock>
     */
    private function findTrainingBlocksInWeek(array $trainingBlocks, Week $week): array
    {
        return array_values(array_filter(
            $trainingBlocks,
            static fn (TrainingBlock $trainingBlock): bool => $trainingBlock->getEndDay() >= $week->getFrom()
                && $trainingBlock->getStartDay() <= $week->getTo(),
        ));
    }

    /**
     * @param list<PlannedSession> $plannedSessions
     *
     * @return array<string, null|float>
     */
    private function buildPlannedSessionEstimatesById(array $plannedSessions): array
    {
        $plannedSessionEstimatesById = [];

        foreach ($plannedSessions as $plannedSession) {
            $plannedSessionEstimatesById[(string) $plannedSession->getId()] = $this->plannedSessionLoadEstimator
                ->estimate($plannedSession)?->getEstimatedLoad();
        }

        return $plannedSessionEstimatesById;
    }
}
