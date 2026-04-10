<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Activity\Activity;
use App\Domain\Activity\DbalActivityRepository;

final readonly class PlannedSessionActivityMatcher
{
    public function __construct(
        private DbalActivityRepository $activityRepository,
    ) {
    }

    /**
     * @param null|list<Activity> $candidateActivities
     */
    public function findSuggestedMatch(PlannedSession $plannedSession, ?array $candidateActivities = null): ?Activity
    {
        $candidates = array_values(array_filter(
            $candidateActivities ?? iterator_to_array($this->activityRepository->findAll()),
            static fn (Activity $activity): bool =>
                $activity->getStartDate()->format('Y-m-d') === $plannedSession->getDay()->format('Y-m-d')
                && $activity->getSportType()->getActivityType() === $plannedSession->getActivityType(),
        ));

        if ([] === $candidates) {
            return null;
        }

        if (1 === count($candidates)) {
            return $candidates[0];
        }

        $durationMatches = $this->findDurationMatches($plannedSession, $candidates);
        if (1 === count($durationMatches)) {
            return $durationMatches[0];
        }

        $titleMatches = $this->findTitleMatches($plannedSession, $candidates);
        if (1 === count($titleMatches)) {
            return $titleMatches[0];
        }

        return null;
    }

    /**
     * @param list<Activity> $candidates
     *
     * @return list<Activity>
     */
    private function findDurationMatches(PlannedSession $plannedSession, array $candidates): array
    {
        $targetDurationInSeconds = $plannedSession->getTargetDurationInSeconds();
        if (null === $targetDurationInSeconds || $targetDurationInSeconds <= 0) {
            return [];
        }

        $tolerance = max(900, (int) round($targetDurationInSeconds * 0.25));

        return array_values(array_filter(
            $candidates,
            static fn (Activity $activity): bool => abs($activity->getMovingTimeInSeconds() - $targetDurationInSeconds) <= $tolerance,
        ));
    }

    /**
     * @param list<Activity> $candidates
     *
     * @return list<Activity>
     */
    private function findTitleMatches(PlannedSession $plannedSession, array $candidates): array
    {
        $title = $this->normalize($plannedSession->getTitle());
        if ('' === $title) {
            return [];
        }

        $tokens = array_values(array_filter(
            explode(' ', $title),
            static fn (string $token): bool => strlen($token) >= 4,
        ));

        if ([] === $tokens) {
            return [];
        }

        return array_values(array_filter(
            $candidates,
            fn (Activity $activity): bool => $this->matchesTitleTokens($this->normalize($activity->getName()), $tokens),
        ));
    }

    /**
     * @param list<string> $tokens
     */
    private function matchesTitleTokens(string $activityName, array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (str_contains($activityName, $token)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(?string $value): string
    {
        if (null === $value) {
            return '';
        }

        $normalized = preg_replace('/[^a-z0-9]+/i', ' ', strtolower($value));

        return trim($normalized ?? '');
    }
}