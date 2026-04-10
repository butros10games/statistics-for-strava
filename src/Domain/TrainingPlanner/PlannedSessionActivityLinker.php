<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Activity\Activity;
use App\Domain\Activity\DbalActivityRepository;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class PlannedSessionActivityLinker
{
    public function __construct(
        private PlannedSessionRepository $plannedSessionRepository,
        private DbalActivityRepository $activityRepository,
        private PlannedSessionActivityMatcher $plannedSessionActivityMatcher,
        private Clock $clock,
    ) {
    }

    public function syncDay(SerializableDateTime $day): void
    {
        $this->syncDateRange(DateRange::fromDates(
            $day->setTime(0, 0),
            $day->setTime(23, 59, 59),
        ));
    }

    public function syncUpTo(SerializableDateTime $until): void
    {
        $earliestPlannedSession = $this->plannedSessionRepository->findEarliest();
        if (null === $earliestPlannedSession) {
            return;
        }

        $from = $earliestPlannedSession->getDay()->setTime(0, 0);
        $till = $until->setTime(23, 59, 59);
        if ($from > $till) {
            return;
        }

        $this->syncDateRange(DateRange::fromDates($from, $till));
    }

    public function syncDateRange(DateRange $dateRange): void
    {
        $plannedSessions = $this->plannedSessionRepository->findByDateRange($dateRange);
        if ([] === $plannedSessions) {
            return;
        }

        $activitiesByDay = $this->groupActivitiesByDay(
            iterator_to_array($this->activityRepository->findByDateRange($dateRange))
        );
        $updatedAt = $this->clock->getCurrentDateTimeImmutable();

        foreach ($this->groupPlannedSessionsByDay($plannedSessions) as $day => $sessionsForDay) {
            $activitiesForDay = $activitiesByDay[$day] ?? [];
            $lockedActivityIds = $this->getLockedActivityIds($sessionsForDay, $activitiesForDay);

            foreach ($sessionsForDay as $plannedSession) {
                $linkedActivityId = $plannedSession->getLinkedActivityId()?->__toString();
                if (
                    PlannedSessionLinkStatus::LINKED === $plannedSession->getLinkStatus()
                    && null !== $linkedActivityId
                    && isset($lockedActivityIds[$linkedActivityId])
                    && $lockedActivityIds[$linkedActivityId] === (string) $plannedSession->getId()
                ) {
                    continue;
                }

                $matchedActivity = $this->plannedSessionActivityMatcher->findSuggestedMatch(
                    $plannedSession,
                    array_values(array_filter(
                        $activitiesForDay,
                        static function (Activity $activity) use ($lockedActivityIds, $plannedSession): bool {
                            if ($activity->getSportType()->getActivityType() !== $plannedSession->getActivityType()) {
                                return false;
                            }

                            $activityId = (string) $activity->getId();

                            return !isset($lockedActivityIds[$activityId])
                                || $lockedActivityIds[$activityId] === (string) $plannedSession->getId();
                        }
                    ))
                );

                if (null === $matchedActivity) {
                    if (null !== $plannedSession->getLinkedActivityId() || PlannedSessionLinkStatus::UNLINKED !== $plannedSession->getLinkStatus()) {
                        $this->plannedSessionRepository->upsert($plannedSession->withoutLink($updatedAt));
                    }

                    continue;
                }

                $matchedActivityId = (string) $matchedActivity->getId();
                $lockedActivityIds[$matchedActivityId] = (string) $plannedSession->getId();

                if (
                    PlannedSessionLinkStatus::LINKED === $plannedSession->getLinkStatus()
                    && $plannedSession->getLinkedActivityId()?->__toString() === $matchedActivityId
                ) {
                    continue;
                }

                $this->plannedSessionRepository->upsert($plannedSession->withConfirmedLink(
                    linkedActivityId: $matchedActivity->getId(),
                    updatedAt: $updatedAt,
                ));
            }
        }
    }

    /**
     * @param list<Activity> $activities
     *
     * @return array<string, list<Activity>>
     */
    private function groupActivitiesByDay(array $activities): array
    {
        $groupedActivities = [];

        foreach ($activities as $activity) {
            $groupedActivities[$activity->getStartDate()->format('Y-m-d')][] = $activity;
        }

        return $groupedActivities;
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
     * @param list<PlannedSession> $sessionsForDay
     * @param list<Activity> $activitiesForDay
     *
     * @return array<string, string>
     */
    private function getLockedActivityIds(array $sessionsForDay, array $activitiesForDay): array
    {
        $activityIdsForDay = array_fill_keys(
            array_map(static fn (Activity $activity): string => (string) $activity->getId(), $activitiesForDay),
            true,
        );
        $lockedActivityIds = [];

        foreach ($sessionsForDay as $plannedSession) {
            $linkedActivityId = $plannedSession->getLinkedActivityId()?->__toString();
            if (
                PlannedSessionLinkStatus::LINKED !== $plannedSession->getLinkStatus()
                || null === $linkedActivityId
                || !isset($activityIdsForDay[$linkedActivityId])
            ) {
                continue;
            }

            $lockedActivityIds[$linkedActivityId] = (string) $plannedSession->getId();
        }

        return $lockedActivityIds;
    }
}