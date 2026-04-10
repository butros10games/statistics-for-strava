<?php

declare(strict_types=1);

namespace App\Application\Build\BuildMonthlyStatsHtml;

use App\Domain\Activity\EnrichedActivities;
use App\Domain\Activity\SportType\SportTypeRepository;
use App\Domain\Calendar\Calendar;
use App\Domain\Calendar\FindMonthlyStats\FindMonthlyStats;
use App\Domain\Calendar\Month;
use App\Domain\Calendar\Months;
use App\Domain\Challenge\ChallengeRepository;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionLoadEstimator;
use App\Domain\TrainingPlanner\PlannedSessionRepository;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;
use App\Infrastructure\CQRS\Query\Bus\QueryBus;
use App\Infrastructure\ValueObject\Time\DateRange;
use League\Flysystem\FilesystemOperator;
use Twig\Environment;

final readonly class BuildMonthlyStatsHtmlCommandHandler implements CommandHandler
{
    public function __construct(
        private ChallengeRepository $challengeRepository,
        private SportTypeRepository $sportTypeRepository,
        private EnrichedActivities $enrichedActivities,
        private PlannedSessionRepository $plannedSessionRepository,
        private PlannedSessionLoadEstimator $plannedSessionLoadEstimator,
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

        $startDate = $allActivities->getFirstActivityStartDate();
        if (null !== $earliestPlannedSession && $earliestPlannedSession->getDay() < $startDate) {
            $startDate = $earliestPlannedSession->getDay();
        }

        $endDate = $now;
        if (null !== $latestPlannedSession && $latestPlannedSession->getDay() > $endDate) {
            $endDate = $latestPlannedSession->getDay();
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

        $monthlyStats = $this->queryBus->ask(new FindMonthlyStats());

        $this->buildStorage->write(
            'monthly-stats.html',
            $this->twig->load('html/calendar/monthly-stats.html.twig')->render([
                'monthlyStatistics' => $monthlyStats,
                'challenges' => $allChallenges,
                'months' => $allMonths->reverse(),
                'plannedSessionsByMonth' => $plannedSessionsByMonth,
                'sportTypes' => $this->sportTypeRepository->findAll(),
                'currentMonthId' => $now->format(Month::MONTH_ID_FORMAT),
                'today' => $now,
            ]),
        );

        $firstMonthId = (string) $allMonths->getFirst()?->getId();
        $lastMonthId = (string) $allMonths->getLast()?->getId();

        /** @var Month $month */
        foreach ($allMonths as $month) {
            $this->buildStorage->write(
                'month/month-'.$month->getId().'.html',
                $this->twig->load('html/calendar/month.html.twig')->render([
                    'hasPreviousMonth' => $month->getId() !== $firstMonthId,
                    'hasNextMonth' => $month->getId() !== $lastMonthId,
                    'statistics' => $monthlyStats->getForMonth($month),
                    'challenges' => $allChallenges,
                    'plannedSessionsForMonth' => $plannedSessionsByMonth[$month->getId()] ?? [],
                    'plannedSessionEstimatesById' => $plannedSessionEstimatesById,
                    'calendar' => Calendar::create(
                        month: $month,
                        enrichedActivities: $this->enrichedActivities,
                        plannedSessionsByDay: $plannedSessionsByDay,
                    ),
                ]),
            );
        }
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
