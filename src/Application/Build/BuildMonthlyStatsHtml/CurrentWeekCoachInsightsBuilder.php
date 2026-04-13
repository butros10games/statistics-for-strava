<?php

declare(strict_types=1);

namespace App\Application\Build\BuildMonthlyStatsHtml;

use App\Domain\Activity\ActivityType;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventProfile;
use App\Domain\TrainingPlanner\RaceReadinessContext;
use App\Domain\TrainingPlanner\RaceReadinessContextBuilder;
use App\Domain\TrainingPlanner\TrainingBlock;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final class CurrentWeekCoachInsightsBuilder
{
    public function __construct(
        private RaceReadinessContextBuilder $raceReadinessContextBuilder,
    ) {
    }

    /**
     * @param list<PlannedSession> $plannedSessions
     * @param list<RaceEvent> $raceEvents
     * @param list<TrainingBlock> $trainingBlocks
     * @param array<string, RaceEvent> $raceEventsById
     * @param array<string, null|float> $plannedSessionEstimatesById
     */
    public function build(
        SerializableDateTime $referenceDate,
        array $plannedSessions,
        array $raceEvents,
        array $trainingBlocks,
        ?TrainingBlock $currentTrainingBlock,
        array $raceEventsById,
        array $plannedSessionEstimatesById,
    ): CurrentWeekCoachInsights {
        $keySessionIds = $this->buildKeySessionIdsForWeek($plannedSessions, $plannedSessionEstimatesById);
        $brickSessionIds = $this->buildBrickSessionIdsForWeek($plannedSessions);
        $raceReadinessContext = $this->raceReadinessContextBuilder->build(
            referenceDate: $referenceDate,
            plannedSessions: $plannedSessions,
            raceEvents: $raceEvents,
            trainingBlocks: $trainingBlocks,
            currentTrainingBlock: $currentTrainingBlock,
            raceEventsById: $raceEventsById,
            plannedSessionEstimatesById: $plannedSessionEstimatesById,
        );
        $estimatedLoad = $raceReadinessContext->getEstimatedLoad();
        $activityTypeSummaries = $raceReadinessContext->getActivityTypeSummaries();
        $raceIntent = $this->buildRaceIntentForWeek($raceReadinessContext);
        $coachCues = $this->buildCoachCuesForWeek(
            plannedSessions: $plannedSessions,
            plannedSessionEstimatesById: $plannedSessionEstimatesById,
            raceReadinessContext: $raceReadinessContext,
            keySessionIds: $keySessionIds,
            raceIntent: $raceIntent,
        );

        return new CurrentWeekCoachInsights(
            estimatedLoad: $estimatedLoad,
            activityTypeSummaries: $activityTypeSummaries,
            keySessionIds: $keySessionIds,
            brickSessionIds: $brickSessionIds,
            raceIntent: $raceIntent,
            coachCues: $coachCues,
        );
    }

    /**
     * @param list<PlannedSession> $plannedSessions
     * @param array<string, null|float> $plannedSessionEstimatesById
     *
     * @return array<string, true>
     */
    private function buildKeySessionIdsForWeek(array $plannedSessions, array $plannedSessionEstimatesById): array
    {
        if ([] === $plannedSessions) {
            return [];
        }

        usort($plannedSessions, function (PlannedSession $left, PlannedSession $right) use ($plannedSessionEstimatesById): int {
            $leftHard = $this->isHardPlannedSession($left, $plannedSessionEstimatesById);
            $rightHard = $this->isHardPlannedSession($right, $plannedSessionEstimatesById);
            if ($leftHard !== $rightHard) {
                return $rightHard <=> $leftHard;
            }

            $leftLoad = $plannedSessionEstimatesById[(string) $left->getId()] ?? 0.0;
            $rightLoad = $plannedSessionEstimatesById[(string) $right->getId()] ?? 0.0;
            if ($leftLoad !== $rightLoad) {
                return $rightLoad <=> $leftLoad;
            }

            $leftDuration = $left->getTargetDurationInSeconds() ?? $left->getWorkoutDurationInSeconds() ?? 0;
            $rightDuration = $right->getTargetDurationInSeconds() ?? $right->getWorkoutDurationInSeconds() ?? 0;
            if ($leftDuration !== $rightDuration) {
                return $rightDuration <=> $leftDuration;
            }

            return $left->getDay() <=> $right->getDay();
        });

        $keySessionIds = [];

        foreach (array_slice($plannedSessions, 0, min(2, count($plannedSessions))) as $plannedSession) {
            $keySessionIds[(string) $plannedSession->getId()] = true;
        }

        return $keySessionIds;
    }

    /**
     * @param list<PlannedSession> $plannedSessions
     *
     * @return array<string, true>
     */
    private function buildBrickSessionIdsForWeek(array $plannedSessions): array
    {
        $sessionsByDay = [];

        foreach ($plannedSessions as $plannedSession) {
            $sessionsByDay[$plannedSession->getDay()->format('Y-m-d')][] = $plannedSession;
        }

        $brickSessionIds = [];

        foreach ($sessionsByDay as $sessionsForDay) {
            $hasRide = false;
            $hasRun = false;

            foreach ($sessionsForDay as $plannedSession) {
                $hasRide = $hasRide || ActivityType::RIDE === $plannedSession->getActivityType();
                $hasRun = $hasRun || ActivityType::RUN === $plannedSession->getActivityType();
            }

            if (!$hasRide || !$hasRun) {
                continue;
            }

            foreach ($sessionsForDay as $plannedSession) {
                if (in_array($plannedSession->getActivityType(), [ActivityType::RIDE, ActivityType::RUN], true)) {
                    $brickSessionIds[(string) $plannedSession->getId()] = true;
                }
            }
        }

        return $brickSessionIds;
    }

    /**
     * @return null|array{label: string, tone: string, title: string, body: string}
     */
    private function buildRaceIntentForWeek(RaceReadinessContext $raceReadinessContext): ?array
    {
        $targetRace = $raceReadinessContext->getTargetRace();
        if (null === $targetRace) {
            return null;
        }

        $hardSessionCount = $raceReadinessContext->getHardSessionCount();
        $disciplineCounts = $raceReadinessContext->getDisciplineCounts();
        $brickDayCount = $raceReadinessContext->getBrickDayCount();
        $hasLongRide = $raceReadinessContext->hasLongRideSession();
        $hasLongRun = $raceReadinessContext->hasLongRunSession();

        return match ($targetRace->getProfile()) {
            RaceEventProfile::HALF_DISTANCE_TRIATHLON => [
                'label' => '70.3 focus',
                'tone' => ($hasLongRide || $hasLongRun || $brickDayCount > 0) ? 'positive' : 'info',
                'title' => ($hasLongRide || $hasLongRun || $brickDayCount > 0)
                    ? '70.3 specificity is showing'
                    : '70.3 week could use more durability',
                'body' => ($hasLongRide || $hasLongRun || $brickDayCount > 0)
                    ? 'The week includes endurance or bike-run specificity that fits a half-distance target.'
                    : 'The target race is a 70.3, but the microcycle lacks the longer endurance cues that usually support it.',
            ],
            RaceEventProfile::FULL_DISTANCE_TRIATHLON => [
                'label' => 'Full-distance focus',
                'tone' => ($hasLongRide && $hasLongRun) || $brickDayCount > 0 ? 'positive' : 'info',
                'title' => (($hasLongRide && $hasLongRun) || $brickDayCount > 0)
                    ? 'Long-course intent is visible'
                    : 'Long-course week looks a little light',
                'body' => (($hasLongRide && $hasLongRun) || $brickDayCount > 0)
                    ? 'There is enough long-course flavor here to support a full-distance target.'
                    : 'A full-distance goal usually wants more durability cues than this week currently shows.',
            ],
            RaceEventProfile::SPRINT_TRIATHLON,
            RaceEventProfile::OLYMPIC_TRIATHLON,
            RaceEventProfile::DUATHLON,
            RaceEventProfile::AQUATHLON => [
                'label' => match ($targetRace->getProfile()) {
                    RaceEventProfile::SPRINT_TRIATHLON => 'Sprint focus',
                    RaceEventProfile::OLYMPIC_TRIATHLON => 'Olympic focus',
                    RaceEventProfile::DUATHLON => 'Duathlon focus',
                    default => 'Aquathlon focus',
                },
                'tone' => ($hardSessionCount >= 2 || $brickDayCount > 0) ? 'positive' : 'info',
                'title' => ($hardSessionCount >= 2 || $brickDayCount > 0)
                    ? 'The week has some race-day punch'
                    : 'Short-course goal could use more sharpness',
                'body' => ($hardSessionCount >= 2 || $brickDayCount > 0)
                    ? 'There is enough sharper work or race-specific structure to match a faster, shorter target.'
                    : 'This week leans more steady than sharp. A short-course goal often benefits from a bit more punch.',
            ],
            RaceEventProfile::RUN_5K => [
                'label' => '5K focus',
                'tone' => ($hardSessionCount >= 2 && $disciplineCounts['run'] >= 2) ? 'positive' : 'info',
                'title' => ($hardSessionCount >= 2 && $disciplineCounts['run'] >= 2)
                    ? '5K sharpness is showing'
                    : '5K week could use a bit more punch',
                'body' => ($hardSessionCount >= 2 && $disciplineCounts['run'] >= 2)
                    ? 'The week has enough run-specific intensity to support a sharp 5K tune-up race.'
                    : 'A 5K tune-up usually wants more run-specific pop than this week currently shows.',
            ],
            RaceEventProfile::RUN_10K => [
                'label' => '10K focus',
                'tone' => ($hardSessionCount >= 1 && $disciplineCounts['run'] >= 2) ? 'positive' : 'info',
                'title' => ($hardSessionCount >= 1 && $disciplineCounts['run'] >= 2)
                    ? '10K rhythm is showing'
                    : '10K week could use a touch more race rhythm',
                'body' => ($hardSessionCount >= 1 && $disciplineCounts['run'] >= 2)
                    ? 'The week has enough run rhythm and controlled quality to support a solid 10K build.'
                    : 'A 10K block usually wants a clearer blend of run frequency and quality than this week currently shows.',
            ],
            RaceEventProfile::HALF_MARATHON => [
                'label' => 'Half-marathon focus',
                'tone' => ($hasLongRun && $disciplineCounts['run'] >= 2) ? 'positive' : 'info',
                'title' => ($hasLongRun && $disciplineCounts['run'] >= 2)
                    ? 'Half-marathon durability is showing'
                    : 'Half-marathon week could use more run durability',
                'body' => ($hasLongRun && $disciplineCounts['run'] >= 2)
                    ? 'The week includes the run durability that usually supports a strong half marathon build.'
                    : 'A half marathon build usually wants a clearer long-run and run-volume signal than this week currently shows.',
            ],
            RaceEventProfile::MARATHON => [
                'label' => 'Marathon focus',
                'tone' => ($hasLongRun && $disciplineCounts['run'] >= 2 && 0 === $brickDayCount) ? 'positive' : 'info',
                'title' => ($hasLongRun && $disciplineCounts['run'] >= 2)
                    ? 'Marathon durability is showing'
                    : 'Marathon week could use more durability',
                'body' => ($hasLongRun && $disciplineCounts['run'] >= 2)
                    ? 'There is enough long-run durability in the week to support a marathon-focused block.'
                    : 'A marathon build usually wants more obvious run durability and long-run emphasis than this week currently shows.',
            ],
            RaceEventProfile::RUN => [
                'label' => 'Run-race focus',
                'tone' => $disciplineCounts['run'] >= max(2, $disciplineCounts['bike']) ? 'positive' : 'info',
                'title' => $disciplineCounts['run'] >= max(2, $disciplineCounts['bike'])
                    ? 'The week is running-led'
                    : 'Run-race week could lean more toward running',
                'body' => $disciplineCounts['run'] >= max(2, $disciplineCounts['bike'])
                    ? 'The current microcycle is tilted toward the run, which suits the target event.'
                    : 'The target is a run race, but the week does not yet revolve clearly around run-specific work.',
            ],
            RaceEventProfile::RIDE,
            RaceEventProfile::TIME_TRIAL,
            RaceEventProfile::GRAVEL_RACE => [
                'label' => 'Bike-race focus',
                'tone' => $disciplineCounts['bike'] >= max(2, $disciplineCounts['run']) ? 'positive' : 'info',
                'title' => $disciplineCounts['bike'] >= max(2, $disciplineCounts['run'])
                    ? 'The week is bike-led'
                    : 'Bike-race week could lean more toward cycling',
                'body' => $disciplineCounts['bike'] >= max(2, $disciplineCounts['run'])
                    ? 'Cycling is clearly the lead discipline this week, which matches the target race.'
                    : 'The target is a bike race, but the week is not yet clearly anchored around cycling.',
            ],
            RaceEventProfile::SWIM,
            RaceEventProfile::OPEN_WATER_SWIM => [
                'label' => 'Swim-race focus',
                'tone' => $disciplineCounts['swim'] >= 1 ? 'positive' : 'info',
                'title' => $disciplineCounts['swim'] >= 1
                    ? 'The week still nods to the water'
                    : 'Swim-race week needs more water time',
                'body' => $disciplineCounts['swim'] >= 1
                    ? 'Swimming is represented, so the week still respects the target event.'
                    : 'The target is a swim race, but there is no obvious water-specific session in this microcycle.',
            ],
            default => [
                'label' => 'Goal-race focus',
                'tone' => 'info',
                'title' => 'The week is pointing toward the target',
                'body' => 'The current microcycle is being read through the lens of your target event, even if that event is custom-shaped.',
            ],
        };
    }

    /**
     * @param list<PlannedSession> $plannedSessions
     * @param array<string, null|float> $plannedSessionEstimatesById
     * @param array<string, true> $keySessionIds
     * @param null|array{label: string, tone: string, title: string, body: string} $raceIntent
     *
     * @return list<array{tone: string, title: string, body: string}>
     */
    private function buildCoachCuesForWeek(
        array $plannedSessions,
        array $plannedSessionEstimatesById,
        RaceReadinessContext $raceReadinessContext,
        array $keySessionIds,
        ?array $raceIntent,
    ): array {
        if ([] === $plannedSessions) {
            return [];
        }

        $cues = [];
        $totalEstimatedLoad = $raceReadinessContext->getEstimatedLoad();
        $sessionCount = $raceReadinessContext->getSessionCount();
        $hardSessionCount = $raceReadinessContext->getHardSessionCount();
        $easySessionCount = $raceReadinessContext->getEasySessionCount();
        $distinctSessionDayCount = $raceReadinessContext->getDistinctSessionDayCount();
        $keySessions = $this->findKeySessionsFromIds($plannedSessions, $keySessionIds);
        $disciplineBalanceCue = $this->buildDisciplineBalanceCue($raceReadinessContext);

        if (null !== $disciplineBalanceCue) {
            $cues[] = $disciplineBalanceCue;
        }

        if (null !== $raceIntent) {
            $cues[] = [
                'tone' => $raceIntent['tone'],
                'title' => $raceIntent['title'],
                'body' => $raceIntent['body'],
            ];
        }

        if (count($keySessions) >= 2) {
            $sequencingCue = $this->buildKeySessionSequencingCue($keySessions);
            if (null !== $sequencingCue) {
                $cues[] = $sequencingCue;
            }
        }

        if ([] !== $keySessions) {
            $recoveryCue = $this->buildRecoverySpacingCue($plannedSessions, $plannedSessionEstimatesById, $keySessions);
            if (null !== $recoveryCue) {
                $cues[] = $recoveryCue;
            }
        }

        if ($raceReadinessContext->hasRaceEventInContextWindow()) {
            $cues[] = $hardSessionCount > 1 || $totalEstimatedLoad > 260.0
                ? [
                    'tone' => 'warning',
                    'title' => 'Freshness is under pressure',
                    'body' => 'The race is this week, but the load still looks busy. Trim the extras and keep only the work that sharpens race day.',
                ]
                : [
                    'tone' => 'positive',
                    'title' => 'Race week looks controlled',
                    'body' => 'The week has enough work to stay sharp without turning the final build into one last fitness chase.',
                ];
        } elseif (null !== $raceReadinessContext->getPrimaryTrainingBlock() && 'taper' === $raceReadinessContext->getPrimaryTrainingBlock()?->getPhase()->value) {
            $cues[] = $hardSessionCount > 1 || $totalEstimatedLoad > 280.0
                ? [
                    'tone' => 'warning',
                    'title' => 'Taper load looks busy',
                    'body' => 'This taper still stacks stress. Keep the sessions that sharpen confidence and trim the ones that only add fatigue.',
                ]
                : [
                    'tone' => 'positive',
                    'title' => 'Taper looks controlled',
                    'body' => 'The load is light enough to protect freshness while keeping a bit of rhythm in the legs.',
                ];
        } elseif (null !== $raceReadinessContext->getPrimaryTrainingBlock() && 'build' === $raceReadinessContext->getPrimaryTrainingBlock()?->getPhase()->value && 0 === $hardSessionCount && $sessionCount >= 3 && $totalEstimatedLoad >= 180.0) {
            $cues[] = [
                'tone' => 'info',
                'title' => 'Build week needs a clear quality touch',
                'body' => 'There is useful volume on the calendar, but no obvious demanding session yet. Add one key workout if recovery is in a good place.',
            ];
        }

        if ($hardSessionCount >= 3) {
            $cues[] = [
                'tone' => 'warning',
                'title' => 'Quality is piling up',
                'body' => sprintf('There are %d demanding sessions planned this week. Protect an easy reset between the big swings.', $hardSessionCount),
            ];
        }

        if ($distinctSessionDayCount >= 4 && 0 === $easySessionCount) {
            $cues[] = [
                'tone' => 'info',
                'title' => 'No clear easy reset day',
                'body' => 'Every planned day looks moderate or harder. A true easy day can help the key work land better.',
            ];
        }

        $dominantActivityTypeSummary = $raceReadinessContext->getActivityTypeSummaries()[0] ?? null;
        if (
            null !== $dominantActivityTypeSummary
            && $sessionCount >= 3
            && $dominantActivityTypeSummary['count'] >= max(3, $sessionCount - 1)
        ) {
            $cues[] = [
                'tone' => 'info',
                'title' => sprintf('%s is dominating the week', $this->buildActivityTypeLabel($dominantActivityTypeSummary['activityType'])),
                'body' => sprintf(
                    'The microcycle leans heavily toward %s. Add complementary work if this block is meant to stay triathlon-balanced.',
                    strtolower($this->buildActivityTypeLabel($dominantActivityTypeSummary['activityType']))
                ),
            ];
        }

        if ([] === $cues) {
            $cues[] = [
                'tone' => 'positive',
                'title' => 'Week shape looks sensible',
                'body' => 'The current microcycle looks manageable. Keep the easy work easy and let the key session set the tone for the week.',
            ];
        }

        return array_slice($cues, 0, 3);
    }

    /**
     * @param array<string, null|float> $plannedSessionEstimatesById
     */
    private function isHardPlannedSession(PlannedSession $plannedSession, array $plannedSessionEstimatesById): bool
    {
        $targetIntensity = $plannedSession->getTargetIntensity();
        if (PlannedSessionIntensity::HARD === $targetIntensity || PlannedSessionIntensity::RACE === $targetIntensity) {
            return true;
        }

        return ($plannedSessionEstimatesById[(string) $plannedSession->getId()] ?? 0.0) >= 110.0;
    }

    /**
     * @param array<string, null|float> $plannedSessionEstimatesById
     */
    private function isEasyPlannedSession(PlannedSession $plannedSession, array $plannedSessionEstimatesById): bool
    {
        if (PlannedSessionIntensity::EASY === $plannedSession->getTargetIntensity()) {
            return true;
        }

        $estimatedLoad = $plannedSessionEstimatesById[(string) $plannedSession->getId()] ?? null;

        return null !== $estimatedLoad && $estimatedLoad > 0 && $estimatedLoad <= 50.0;
    }

    private function buildActivityTypeLabel(ActivityType $activityType): string
    {
        return match ($activityType) {
            ActivityType::RIDE => 'Cycling',
            ActivityType::RUN => 'Running',
            ActivityType::WATER_SPORTS => 'Swimming',
            default => ucwords(strtolower(str_replace('_', ' ', $activityType->name))),
        };
    }

    /**
     * @param list<PlannedSession> $plannedSessions
     * @param array<string, true> $keySessionIds
     *
     * @return list<PlannedSession>
     */
    private function findKeySessionsFromIds(array $plannedSessions, array $keySessionIds): array
    {
        $keySessions = array_values(array_filter(
            $plannedSessions,
            static fn (PlannedSession $plannedSession): bool => isset($keySessionIds[(string) $plannedSession->getId()]),
        ));

        usort($keySessions, static fn (PlannedSession $left, PlannedSession $right): int => $left->getDay() <=> $right->getDay());

        return $keySessions;
    }

    /**
     * @param list<PlannedSession> $keySessions
     *
     * @return array{tone: string, title: string, body: string}|null
     */
    private function buildKeySessionSequencingCue(array $keySessions): ?array
    {
        for ($index = 0; $index < count($keySessions) - 1; ++$index) {
            $currentKeySession = $keySessions[$index];
            $nextKeySession = $keySessions[$index + 1];
            $dayGap = (int) $currentKeySession->getDay()->diff($nextKeySession->getDay())->format('%a');

            if ($dayGap <= 1) {
                return [
                    'tone' => 'warning',
                    'title' => 'Key sessions are stacked tight',
                    'body' => sprintf(
                        '%s and %s land almost back-to-back. Add more recovery space if both are meant to be high-quality days.',
                        $this->buildPlannedSessionShortLabel($currentKeySession),
                        $this->buildPlannedSessionShortLabel($nextKeySession),
                    ),
                ];
            }
        }

        return [
            'tone' => 'positive',
            'title' => 'Key sessions are spaced well',
            'body' => 'The main work is separated enough to give you a better shot at hitting each session with purpose instead of surviving it.',
        ];
    }

    /**
     * @param list<PlannedSession> $plannedSessions
     * @param array<string, null|float> $plannedSessionEstimatesById
     * @param list<PlannedSession> $keySessions
     *
     * @return array{tone: string, title: string, body: string}|null
     */
    private function buildRecoverySpacingCue(array $plannedSessions, array $plannedSessionEstimatesById, array $keySessions): ?array
    {
        $sessionsByDay = [];

        foreach ($plannedSessions as $plannedSession) {
            $sessionsByDay[$plannedSession->getDay()->format('Y-m-d')][] = $plannedSession;
        }

        foreach ($keySessions as $keySession) {
            $nextDayKey = $keySession->getDay()->modify('+1 day')->format('Y-m-d');
            $nextDaySessions = $sessionsByDay[$nextDayKey] ?? [];

            if ([] === $nextDaySessions) {
                return [
                    'tone' => 'positive',
                    'title' => 'A recovery window follows the main work',
                    'body' => sprintf('There is open space after %s, which gives that session room to actually do its job.', $this->buildPlannedSessionShortLabel($keySession)),
                ];
            }

            $hasEasyNextDay = false;
            foreach ($nextDaySessions as $nextDaySession) {
                if ($this->isEasyPlannedSession($nextDaySession, $plannedSessionEstimatesById)) {
                    $hasEasyNextDay = true;

                    break;
                }
            }

            if (!$hasEasyNextDay) {
                return [
                    'tone' => 'info',
                    'title' => 'Recovery after a key day looks light',
                    'body' => sprintf('The day after %s still carries work. Keep at least one truly easy follow-up if this is a priority session.', $this->buildPlannedSessionShortLabel($keySession)),
                ];
            }
        }

        return null;
    }

    /**
     * @return array{tone: string, title: string, body: string}|null
     */
    private function buildDisciplineBalanceCue(RaceReadinessContext $raceReadinessContext): ?array
    {
        $disciplineCounts = $raceReadinessContext->getDisciplineCounts();
        $brickDayCount = $raceReadinessContext->getBrickDayCount();

        if ($brickDayCount > 0) {
            return [
                'tone' => 'positive',
                'title' => 'Brick structure is in the week',
                'body' => 1 === $brickDayCount
                    ? 'There is one ride-to-run combination in the microcycle, which is a nice race-specific touch.'
                    : sprintf('There are %d bike-to-run combinations in the week, so the race-specific rhythm is showing up clearly.', $brickDayCount),
            ];
        }

        $presentTriDisciplines = count(array_filter($disciplineCounts, static fn (int $count): bool => $count > 0));

        if ($presentTriDisciplines === 3) {
            return [
                'tone' => 'positive',
                'title' => 'All three triathlon disciplines are represented',
                'body' => 'Swim, bike, and run all show up this week, so the microcycle has a properly multisport feel.',
            ];
        }

        if ($raceReadinessContext->getSessionCount() >= 4 && $presentTriDisciplines <= 2) {
            $missingDisciplines = [];

            if (0 === $disciplineCounts['swim']) {
                $missingDisciplines[] = 'swim';
            }
            if (0 === $disciplineCounts['bike']) {
                $missingDisciplines[] = 'bike';
            }
            if (0 === $disciplineCounts['run']) {
                $missingDisciplines[] = 'run';
            }

            return [
                'tone' => 'info',
                'title' => 'Triathlon balance is a bit narrow',
                'body' => sprintf(
                    'This week is missing %s. If this block is meant to stay triathlon-balanced, add a touch of that discipline.',
                    implode(' and ', $missingDisciplines),
                ),
            ];
        }

        return null;
    }

    private function buildPlannedSessionShortLabel(PlannedSession $plannedSession): string
    {
        return sprintf(
            '%s %s',
            $plannedSession->getDay()->format('D'),
            $plannedSession->getTitle() ?? $this->buildActivityTypeLabel($plannedSession->getActivityType()),
        );
    }

}
