<?php

declare(strict_types=1);

namespace App\Application\Build\BuildMonthlyStatsHtml;

use App\Domain\Activity\ActivityType;
use App\Domain\Activity\EnrichedActivities;
use App\Domain\Activity\SportType\SportTypeRepository;
use App\Domain\Calendar\Calendar;
use App\Domain\Calendar\Month;
use App\Domain\Calendar\Months;
use App\Domain\Calendar\FindMonthlyStats\FindMonthlyStats;
use App\Domain\Calendar\Week;
use App\Domain\Challenge\ChallengeRepository;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionLoadEstimator;
use App\Domain\TrainingPlanner\PlannedSessionRepository;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventRepository;
use App\Domain\TrainingPlanner\TrainingBlock;
use App\Domain\TrainingPlanner\TrainingBlockRepository;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;
use App\Infrastructure\CQRS\Query\Bus\QueryBus;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use League\Flysystem\FilesystemOperator;
use Twig\Environment;

final readonly class BuildMonthlyStatsHtmlCommandHandler implements CommandHandler
{
    public function __construct(
        private ChallengeRepository $challengeRepository,
        private SportTypeRepository $sportTypeRepository,
        private EnrichedActivities $enrichedActivities,
        private PlannedSessionRepository $plannedSessionRepository,
        private RaceEventRepository $raceEventRepository,
        private TrainingBlockRepository $trainingBlockRepository,
        private PlannedSessionLoadEstimator $plannedSessionLoadEstimator,
        private CurrentWeekCoachInsightsBuilder $currentWeekCoachInsightsBuilder,
        private QueryBus $queryBus,
        private Environment $twig,
        private FilesystemOperator $buildStorage,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof BuildMonthlyStatsHtml);

        $now = $command->getCurrentDateTime();
        $allActivities = $this->enrichedActivities->findAll();
        $allChallenges = $this->challengeRepository->findAll();
        $earliestPlannedSession = $this->plannedSessionRepository->findEarliest();
        $latestPlannedSession = $this->plannedSessionRepository->findLatest();
        $earliestRaceEvent = $this->raceEventRepository->findEarliest();
        $latestRaceEvent = $this->raceEventRepository->findLatest();
        $earliestTrainingBlock = $this->trainingBlockRepository->findEarliest();
        $latestTrainingBlock = $this->trainingBlockRepository->findLatest();

        $startDate = $allActivities->getFirstActivityStartDate();
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

        $allMonths = Months::create(
            startDate: $startDate,
            endDate: $endDate,
        );

        $plannedSessions = $this->plannedSessionRepository->findByDateRange(DateRange::fromDates(
            $startDate->modify('first day of this month')->setTime(0, 0),
            $endDate->modify('last day of this month')->setTime(23, 59, 59),
        ));
        $plannedSessionsByMonth = $this->groupPlannedSessionsByMonth($plannedSessions);
        $plannedSessionsByDay = $this->groupPlannedSessionsByDay($plannedSessions);
        $plannedSessionEstimatesById = $this->buildPlannedSessionEstimatesById($plannedSessions);
        $raceEvents = $this->raceEventRepository->findByDateRange(DateRange::fromDates(
            $startDate->modify('first day of this month')->setTime(0, 0),
            $endDate->modify('last day of this month')->setTime(23, 59, 59),
        ));
        $raceEventsByMonth = $this->groupRaceEventsByMonth($raceEvents);
        $raceEventsByDay = $this->groupRaceEventsByDay($raceEvents);
        $raceEventsById = $this->buildRaceEventsById($raceEvents);
        $upcomingRaceEvents = $this->raceEventRepository->findUpcoming($now, 4);
        $raceEventCountdownDaysById = $this->buildRaceEventCountdownDaysById($raceEvents, $now);
        $trainingBlocks = $this->trainingBlockRepository->findByDateRange(DateRange::fromDates(
            $startDate->modify('first day of this month')->setTime(0, 0),
            $endDate->modify('last day of this month')->setTime(23, 59, 59),
        ));
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
        $currentWeekEstimatedLoad = $currentWeekCoachInsights->getEstimatedLoad();
        $currentWeekActivityTypeSummaries = $currentWeekCoachInsights->getActivityTypeSummaries();
        $currentWeekKeySessionIds = $currentWeekCoachInsights->getKeySessionIds();
        $currentWeekBrickSessionIds = $currentWeekCoachInsights->getBrickSessionIds();
        $currentWeekRaceIntent = $currentWeekCoachInsights->getRaceIntent();
        $currentWeekCoachCues = $currentWeekCoachInsights->getCoachCues();

        $monthlyStats = $this->queryBus->ask(new FindMonthlyStats());

        $currentMonth = Month::fromDate($now);
        $firstMonthId = (string) $allMonths->getFirst()?->getId();
        $lastMonthId = (string) $allMonths->getLast()?->getId();

        $this->buildStorage->write(
            'monthly-stats.html',
            $this->twig->load('html/calendar/monthly-stats.html.twig')->render($this->buildMonthlyStatsPageViewData(
                month: $currentMonth,
                firstMonthId: $firstMonthId,
                lastMonthId: $lastMonthId,
                monthlyStats: $monthlyStats,
                allChallenges: $allChallenges,
                plannedSessionsByMonth: $plannedSessionsByMonth,
                raceEventsByMonth: $raceEventsByMonth,
                trainingBlocksByMonth: $trainingBlocksByMonth,
                upcomingRaceEvents: $upcomingRaceEvents,
                raceEventsById: $raceEventsById,
                raceEventCountdownDaysById: $raceEventCountdownDaysById,
                currentWeek: $currentWeek,
                currentWeekPlannedSessions: $currentWeekPlannedSessions,
                currentWeekRaceEvents: $currentWeekRaceEvents,
                currentWeekTrainingBlocks: $currentWeekTrainingBlocks,
                currentWeekEstimatedLoad: $currentWeekEstimatedLoad,
                currentWeekActivityTypeSummaries: $currentWeekActivityTypeSummaries,
                currentWeekKeySessionIds: $currentWeekKeySessionIds,
                currentWeekBrickSessionIds: $currentWeekBrickSessionIds,
                currentWeekRaceIntent: $currentWeekRaceIntent,
                currentWeekCoachCues: $currentWeekCoachCues,
                currentTrainingBlock: $currentTrainingBlock,
                currentAndUpcomingTrainingBlocks: $currentAndUpcomingTrainingBlocks,
                plannedSessionEstimatesById: $plannedSessionEstimatesById,
                plannedSessionsByDay: $plannedSessionsByDay,
                raceEventsByDay: $raceEventsByDay,
                trainingBlocksByDay: $trainingBlocksByDay,
            )),
        );

        /** @var Month $month */
        foreach ($allMonths as $month) {
            $this->buildStorage->write(
                'monthly-stats/month-'.$month->getId().'.html',
                $this->twig->load('html/calendar/monthly-stats.html.twig')->render($this->buildMonthlyStatsPageViewData(
                    month: $month,
                    firstMonthId: $firstMonthId,
                    lastMonthId: $lastMonthId,
                    monthlyStats: $monthlyStats,
                    allChallenges: $allChallenges,
                    plannedSessionsByMonth: $plannedSessionsByMonth,
                    raceEventsByMonth: $raceEventsByMonth,
                    trainingBlocksByMonth: $trainingBlocksByMonth,
                    upcomingRaceEvents: $upcomingRaceEvents,
                    raceEventsById: $raceEventsById,
                    raceEventCountdownDaysById: $raceEventCountdownDaysById,
                    currentWeek: $currentWeek,
                    currentWeekPlannedSessions: $currentWeekPlannedSessions,
                    currentWeekRaceEvents: $currentWeekRaceEvents,
                    currentWeekTrainingBlocks: $currentWeekTrainingBlocks,
                    currentWeekEstimatedLoad: $currentWeekEstimatedLoad,
                    currentWeekActivityTypeSummaries: $currentWeekActivityTypeSummaries,
                    currentWeekKeySessionIds: $currentWeekKeySessionIds,
                    currentWeekBrickSessionIds: $currentWeekBrickSessionIds,
                    currentWeekRaceIntent: $currentWeekRaceIntent,
                    currentWeekCoachCues: $currentWeekCoachCues,
                    currentTrainingBlock: $currentTrainingBlock,
                    currentAndUpcomingTrainingBlocks: $currentAndUpcomingTrainingBlocks,
                    plannedSessionEstimatesById: $plannedSessionEstimatesById,
                    plannedSessionsByDay: $plannedSessionsByDay,
                    raceEventsByDay: $raceEventsByDay,
                    trainingBlocksByDay: $trainingBlocksByDay,
                )),
            );

            $this->buildStorage->write(
                'month/month-'.$month->getId().'.html',
                $this->twig->load('html/calendar/month.html.twig')->render([
                    'hasPreviousMonth' => $month->getId() !== $firstMonthId,
                    'hasNextMonth' => $month->getId() !== $lastMonthId,
                    'statistics' => $monthlyStats->getForMonth($month),
                    'challenges' => $allChallenges,
                    'plannedSessionsForMonth' => $plannedSessionsByMonth[$month->getId()] ?? [],
                    'raceEventsForMonth' => $raceEventsByMonth[$month->getId()] ?? [],
                    'trainingBlocksForMonth' => $trainingBlocksByMonth[$month->getId()] ?? [],
                    'upcomingRaceEvents' => $upcomingRaceEvents,
                    'raceEventsById' => $raceEventsById,
                    'raceEventCountdownDaysById' => $raceEventCountdownDaysById,
                    'currentWeek' => $currentWeek,
                    'currentWeekPlannedSessions' => $currentWeekPlannedSessions,
                    'currentWeekRaceEvents' => $currentWeekRaceEvents,
                    'currentWeekTrainingBlocks' => $currentWeekTrainingBlocks,
                    'currentWeekEstimatedLoad' => $currentWeekEstimatedLoad,
                    'currentWeekActivityTypeSummaries' => $currentWeekActivityTypeSummaries,
                    'currentWeekKeySessionIds' => $currentWeekKeySessionIds,
                    'currentWeekBrickSessionIds' => $currentWeekBrickSessionIds,
                    'currentWeekRaceIntent' => $currentWeekRaceIntent,
                    'currentWeekCoachCues' => $currentWeekCoachCues,
                    'currentTrainingBlock' => $currentTrainingBlock,
                    'currentAndUpcomingTrainingBlocks' => $currentAndUpcomingTrainingBlocks,
                    'plannedSessionEstimatesById' => $plannedSessionEstimatesById,
                    'calendar' => Calendar::create(
                        month: $month,
                        enrichedActivities: $this->enrichedActivities,
                        plannedSessionsByDay: $plannedSessionsByDay,
                        raceEventsByDay: $raceEventsByDay,
                        trainingBlocksByDay: $trainingBlocksByDay,
                    ),
                ]),
            );
        }
    }

    /**
     * @param array<string, mixed> $monthlyStats
     * @param list<mixed> $allChallenges
     * @param array<string, list<PlannedSession>> $plannedSessionsByMonth
     * @param array<string, list<RaceEvent>> $raceEventsByMonth
     * @param array<string, list<TrainingBlock>> $trainingBlocksByMonth
     * @param list<RaceEvent> $upcomingRaceEvents
     * @param array<string, RaceEvent> $raceEventsById
     * @param array<string, int> $raceEventCountdownDaysById
     * @param list<PlannedSession> $currentWeekPlannedSessions
     * @param list<RaceEvent> $currentWeekRaceEvents
     * @param list<TrainingBlock> $currentWeekTrainingBlocks
     * @param list<array{activityType: ActivityType, count: int}> $currentWeekActivityTypeSummaries
     * @param array<string, true> $currentWeekKeySessionIds
     * @param array<string, true> $currentWeekBrickSessionIds
     * @param null|array{label: string, tone: string, title: string, body: string} $currentWeekRaceIntent
     * @param list<array{tone: string, title: string, body: string}> $currentWeekCoachCues
     * @param list<TrainingBlock> $currentAndUpcomingTrainingBlocks
     * @param array<string, null|float> $plannedSessionEstimatesById
     * @param array<string, list<PlannedSession>> $plannedSessionsByDay
     * @param array<string, list<RaceEvent>> $raceEventsByDay
     * @param array<string, list<TrainingBlock>> $trainingBlocksByDay
     *
     * @return array<string, mixed>
     */
    private function buildMonthlyStatsPageViewData(
        Month $month,
        string $firstMonthId,
        string $lastMonthId,
        mixed $monthlyStats,
        mixed $allChallenges,
        array $plannedSessionsByMonth,
        array $raceEventsByMonth,
        array $trainingBlocksByMonth,
        array $upcomingRaceEvents,
        array $raceEventsById,
        array $raceEventCountdownDaysById,
        Week $currentWeek,
        array $currentWeekPlannedSessions,
        array $currentWeekRaceEvents,
        array $currentWeekTrainingBlocks,
        float $currentWeekEstimatedLoad,
        array $currentWeekActivityTypeSummaries,
        array $currentWeekKeySessionIds,
        array $currentWeekBrickSessionIds,
        ?array $currentWeekRaceIntent,
        array $currentWeekCoachCues,
        ?TrainingBlock $currentTrainingBlock,
        array $currentAndUpcomingTrainingBlocks,
        array $plannedSessionEstimatesById,
        array $plannedSessionsByDay,
        array $raceEventsByDay,
        array $trainingBlocksByDay,
    ): array {
        return [
            'challenges' => $allChallenges,
            'currentMonthStatistics' => $monthlyStats->getForMonth($month),
            'currentMonthPlannedSessions' => $plannedSessionsByMonth[$month->getId()] ?? [],
            'currentMonthRaceEvents' => $raceEventsByMonth[$month->getId()] ?? [],
            'currentMonthTrainingBlocks' => $trainingBlocksByMonth[$month->getId()] ?? [],
            'upcomingRaceEvents' => $upcomingRaceEvents,
            'raceEventsById' => $raceEventsById,
            'raceEventCountdownDaysById' => $raceEventCountdownDaysById,
            'currentWeek' => $currentWeek,
            'currentWeekPlannedSessions' => $currentWeekPlannedSessions,
            'currentWeekRaceEvents' => $currentWeekRaceEvents,
            'currentWeekTrainingBlocks' => $currentWeekTrainingBlocks,
            'currentWeekEstimatedLoad' => $currentWeekEstimatedLoad,
            'currentWeekActivityTypeSummaries' => $currentWeekActivityTypeSummaries,
            'currentWeekKeySessionIds' => $currentWeekKeySessionIds,
            'currentWeekBrickSessionIds' => $currentWeekBrickSessionIds,
            'currentWeekRaceIntent' => $currentWeekRaceIntent,
            'currentWeekCoachCues' => $currentWeekCoachCues,
            'currentTrainingBlock' => $currentTrainingBlock,
            'currentAndUpcomingTrainingBlocks' => $currentAndUpcomingTrainingBlocks,
            'plannedSessionEstimatesById' => $plannedSessionEstimatesById,
            'currentCalendar' => Calendar::create(
                month: $month,
                enrichedActivities: $this->enrichedActivities,
                plannedSessionsByDay: $plannedSessionsByDay,
                raceEventsByDay: $raceEventsByDay,
                trainingBlocksByDay: $trainingBlocksByDay,
            ),
            'currentMonthHasPrevious' => $month->getId() !== $firstMonthId,
            'currentMonthHasNext' => $month->getId() !== $lastMonthId,
        ];
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
