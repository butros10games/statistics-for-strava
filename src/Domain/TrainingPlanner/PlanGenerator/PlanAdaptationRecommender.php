<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner\PlanGenerator;

use App\Domain\Dashboard\Widget\TrainingLoad\ReadinessStatus;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventPriority;
use App\Domain\TrainingPlanner\RaceReadinessContext;
use App\Domain\TrainingPlanner\TrainingBlock;
use App\Domain\TrainingPlanner\TrainingBlockPhase;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class PlanAdaptationRecommender
{
    /**
     * @param list<TrainingBlock> $existingBlocks
     * @param list<PlannedSession> $existingSessions
     * @param list<RaceEvent> $upcomingRaces
     * @param array<string, null|float> $plannedSessionEstimatesById
     *
     * @return list<PlanAdaptationRecommendation>
     */
    public function recommend(
        RaceEvent $targetRace,
        array $existingBlocks,
        array $existingSessions,
        array $upcomingRaces,
        array $plannedSessionEstimatesById,
        ?RaceReadinessContext $readinessContext,
        SerializableDateTime $now,
        array $planWindowSessions = [],
        array $planWindowSessionEstimatesById = [],
    ): array {
        $rules = RaceProfileTrainingRules::forProfile($targetRace->getProfile());
        $recommendations = [];

        $recommendations = array_merge(
            $recommendations,
            $this->checkBlockCoverage($targetRace, $existingBlocks, $rules, $now),
            $this->checkBRaceAdaptations($targetRace, $existingBlocks, $upcomingRaces, $rules),
            $this->checkLoadProgression($existingSessions, $plannedSessionEstimatesById, $readinessContext, $rules),
            $this->checkTaperTiming($targetRace, $existingBlocks, $rules, $now),
            $this->checkUpcomingWeekCoverage(
                blocks: $existingBlocks,
                plannedSessions: [] !== $planWindowSessions ? $planWindowSessions : $existingSessions,
                plannedSessionEstimatesById: [] !== $planWindowSessionEstimatesById ? $planWindowSessionEstimatesById : $plannedSessionEstimatesById,
                rules: $rules,
                now: $now,
            ),
        );

        return array_slice($recommendations, 0, 8);
    }

    /**
     * @param list<TrainingBlock> $existingBlocks
     *
     * @return list<PlanAdaptationRecommendation>
     */
    private function checkBlockCoverage(
        RaceEvent $targetRace,
        array $existingBlocks,
        RaceProfileTrainingRules $rules,
        SerializableDateTime $now,
    ): array {
        $recommendations = [];
        $raceDay = $targetRace->getDay();
        $weeksToRace = max(0, (int) ceil($now->diff($raceDay)->days / 7));

        if ([] === $existingBlocks) {
            if ($weeksToRace >= $rules->getMinimumPlanWeeks()) {
                $recommendations[] = PlanAdaptationRecommendation::create(
                    type: PlanAdaptationRecommendationType::ADD_BLOCK,
                    title: 'No training blocks yet',
                    body: sprintf(
                        'There are %d weeks until race day but no training blocks defined. Generate a plan to create the periodization structure.',
                        $weeksToRace,
                    ),
                    severity: PlanAdaptationWarningSeverity::WARNING,
                );
            } elseif ($weeksToRace > 0) {
                $recommendations[] = PlanAdaptationRecommendation::create(
                    type: PlanAdaptationRecommendationType::ADD_BLOCK,
                    title: 'Limited time, no blocks defined',
                    body: sprintf(
                        'Only %d weeks until race day and no training block structure. A compressed plan is better than no plan.',
                        $weeksToRace,
                    ),
                    severity: PlanAdaptationWarningSeverity::CRITICAL,
                );
            }

            return $recommendations;
        }

        $linkedBlocks = array_filter(
            $existingBlocks,
            static fn (TrainingBlock $block): bool => null !== $block->getTargetRaceEventId()
                && (string) $block->getTargetRaceEventId() === (string) $targetRace->getId(),
        );

        if ([] === $linkedBlocks) {
            $recommendations[] = PlanAdaptationRecommendation::create(
                type: PlanAdaptationRecommendationType::ADD_BLOCK,
                title: 'No blocks linked to target race',
                body: 'Training blocks exist but none are linked to the A-race. Link blocks to the target race or generate a new plan.',
                severity: PlanAdaptationWarningSeverity::INFO,
            );

            return $recommendations;
        }

        $hasTaper = false;
        $hasBuild = false;

        foreach ($linkedBlocks as $block) {
            if (TrainingBlockPhase::TAPER === $block->getPhase()) {
                $hasTaper = true;
            }
            if (TrainingBlockPhase::BUILD === $block->getPhase()) {
                $hasBuild = true;
            }
        }

        if (!$hasTaper && $weeksToRace <= $rules->getTaperWeeks() + 2) {
            $recommendations[] = PlanAdaptationRecommendation::create(
                type: PlanAdaptationRecommendationType::SHIFT_TAPER,
                title: 'Taper block is missing',
                body: sprintf(
                    'Race day is %d weeks away but there is no taper block. A %d-week taper should start soon.',
                    $weeksToRace,
                    $rules->getTaperWeeks(),
                ),
                severity: PlanAdaptationWarningSeverity::WARNING,
            );
        }

        if (!$hasBuild && $weeksToRace > $rules->getTaperWeeks() + $rules->getPeakWeeks()) {
            $recommendations[] = PlanAdaptationRecommendation::create(
                type: PlanAdaptationRecommendationType::ADD_BLOCK,
                title: 'No build block in the plan',
                body: 'A build phase is missing from the plan. This is the key phase for developing race-specific fitness.',
                severity: PlanAdaptationWarningSeverity::INFO,
            );
        }

        return $recommendations;
    }

    /**
     * @param list<TrainingBlock> $existingBlocks
     * @param list<RaceEvent> $upcomingRaces
     *
     * @return list<PlanAdaptationRecommendation>
     */
    private function checkBRaceAdaptations(
        RaceEvent $targetRace,
        array $existingBlocks,
        array $upcomingRaces,
        RaceProfileTrainingRules $rules,
    ): array {
        $recommendations = [];
        $raceDay = $targetRace->getDay();

        $bRaces = array_filter(
            $upcomingRaces,
            static fn (RaceEvent $event): bool => RaceEventPriority::B === $event->getPriority()
                && $event->getDay() < $raceDay,
        );

        foreach ($bRaces as $bRace) {
            $daysBeforeARace = (int) $bRace->getDay()->diff($raceDay)->days;
            $bRaceBlock = $this->findBlockForDay($existingBlocks, $bRace->getDay());

            if (null !== $bRaceBlock && TrainingBlockPhase::TAPER === $bRaceBlock->getPhase()) {
                $recommendations[] = PlanAdaptationRecommendation::create(
                    type: PlanAdaptationRecommendationType::ADJUST_FOR_B_RACE,
                    title: sprintf('B-race falls in taper (%s)', $bRace->getTitle() ?? 'B-race'),
                    body: sprintf(
                        '%s lands in the taper block, %d days before the A-race. Use it as a sharp dress rehearsal, not a full send.',
                        $bRace->getTitle() ?? 'The B-race',
                        $daysBeforeARace,
                    ),
                    severity: PlanAdaptationWarningSeverity::WARNING,
                );
            } elseif ($daysBeforeARace <= $rules->getBRaceTaperDays() + 7) {
                $recommendations[] = PlanAdaptationRecommendation::create(
                    type: PlanAdaptationRecommendationType::ADJUST_FOR_B_RACE,
                    title: sprintf('B-race needs recovery buffer (%s)', $bRace->getTitle() ?? 'B-race'),
                    body: sprintf(
                        'Allow %d easier days after %s before resuming the A-race build.',
                        $rules->getBRaceTaperDays(),
                        $bRace->getTitle() ?? 'the B-race',
                    ),
                    severity: PlanAdaptationWarningSeverity::INFO,
                );
            }
        }

        $cRaces = array_filter(
            $upcomingRaces,
            static fn (RaceEvent $event): bool => RaceEventPriority::C === $event->getPriority()
                && $event->getDay() < $raceDay,
        );

        foreach ($cRaces as $cRace) {
            $cRaceBlock = $this->findBlockForDay($existingBlocks, $cRace->getDay());

            if (null !== $cRaceBlock && TrainingBlockPhase::PEAK === $cRaceBlock->getPhase()) {
                $recommendations[] = PlanAdaptationRecommendation::create(
                    type: PlanAdaptationRecommendationType::ADJUST_FOR_B_RACE,
                    title: sprintf('C-race during peak (%s)', $cRace->getTitle() ?? 'C-race'),
                    body: sprintf(
                        '%s lands in the peak block. Treat it as a hard training session, not a race effort.',
                        $cRace->getTitle() ?? 'The C-race',
                    ),
                    severity: PlanAdaptationWarningSeverity::INFO,
                );
            }
        }

        return $recommendations;
    }

    /**
     * @param list<PlannedSession> $existingSessions
     * @param array<string, null|float> $plannedSessionEstimatesById
     *
     * @return list<PlanAdaptationRecommendation>
     */
    private function checkLoadProgression(
        array $existingSessions,
        array $plannedSessionEstimatesById,
        ?RaceReadinessContext $readinessContext,
        RaceProfileTrainingRules $rules,
    ): array {
        $recommendations = [];

        if (null === $readinessContext) {
            return $recommendations;
        }

        $weeklyEstimatedLoad = $readinessContext->getEstimatedLoad();
        $hardSessionCount = $readinessContext->getHardSessionCount();
        $sessionCount = $readinessContext->getSessionCount();

        if ($hardSessionCount > $rules->getHardSessionsPerWeek() + 1) {
            $recommendations[] = PlanAdaptationRecommendation::create(
                type: PlanAdaptationRecommendationType::REDUCE_LOAD,
                title: 'Too many hard sessions this week',
                body: sprintf(
                    'There are %d hard sessions planned but the race profile recommends a maximum of %d. Convert some to easy or moderate.',
                    $hardSessionCount,
                    $rules->getHardSessionsPerWeek(),
                ),
                severity: PlanAdaptationWarningSeverity::WARNING,
            );
        }

        if ($sessionCount > $rules->getSessionsPerWeekMaximum()) {
            $recommendations[] = PlanAdaptationRecommendation::create(
                type: PlanAdaptationRecommendationType::REDUCE_LOAD,
                title: 'Session count exceeds recommendation',
                body: sprintf(
                    'There are %d sessions planned this week but %d is the recommended maximum for this race profile.',
                    $sessionCount,
                    $rules->getSessionsPerWeekMaximum(),
                ),
                severity: PlanAdaptationWarningSeverity::INFO,
            );
        }

        $readinessScore = $readinessContext->getReadinessScore();
        if (null !== $readinessScore && in_array($readinessScore->getStatus(), [ReadinessStatus::CAUTION, ReadinessStatus::NEEDS_RECOVERY], true) && $hardSessionCount >= 2) {
            $recommendations[] = PlanAdaptationRecommendation::create(
                type: PlanAdaptationRecommendationType::INSERT_RECOVERY,
                title: 'Readiness is compromised',
                body: 'The readiness score suggests recovery is under pressure. Consider replacing one hard session with easy recovery work.',
                severity: PlanAdaptationWarningSeverity::WARNING,
            );
        }

        return $recommendations;
    }

    /**
     * @param list<TrainingBlock> $existingBlocks
     *
     * @return list<PlanAdaptationRecommendation>
     */
    private function checkTaperTiming(
        RaceEvent $targetRace,
        array $existingBlocks,
        RaceProfileTrainingRules $rules,
        SerializableDateTime $now,
    ): array {
        $recommendations = [];
        $raceDay = $targetRace->getDay();
        $daysToRace = max(0, (int) $now->diff($raceDay)->days);
        $idealTaperStartDays = $rules->getTaperWeeks() * 7;

        $taperBlock = null;
        foreach ($existingBlocks as $block) {
            if (TrainingBlockPhase::TAPER === $block->getPhase()
                && null !== $block->getTargetRaceEventId()
                && (string) $block->getTargetRaceEventId() === (string) $targetRace->getId()) {
                $taperBlock = $block;

                break;
            }
        }

        if (null === $taperBlock) {
            return $recommendations;
        }

        $taperStartDaysBeforeRace = (int) $taperBlock->getStartDay()->diff($raceDay)->days;

        if ($taperStartDaysBeforeRace > $idealTaperStartDays + 7) {
            $recommendations[] = PlanAdaptationRecommendation::create(
                type: PlanAdaptationRecommendationType::SHIFT_TAPER,
                title: 'Taper starts too early',
                body: sprintf(
                    'The taper block starts %d days before race day, but %d days is typical for this profile. Starting too early may lose fitness.',
                    $taperStartDaysBeforeRace,
                    $idealTaperStartDays,
                ),
                severity: PlanAdaptationWarningSeverity::INFO,
            );
        }

        if ($taperStartDaysBeforeRace < $idealTaperStartDays - 3 && $daysToRace > $taperStartDaysBeforeRace) {
            $recommendations[] = PlanAdaptationRecommendation::create(
                type: PlanAdaptationRecommendationType::SHIFT_TAPER,
                title: 'Taper starts late',
                body: sprintf(
                    'The taper block starts only %d days before race day. A %d-day taper is recommended for this profile.',
                    $taperStartDaysBeforeRace,
                    $idealTaperStartDays,
                ),
                severity: PlanAdaptationWarningSeverity::WARNING,
            );
        }

        return $recommendations;
    }

    /**
     * @param list<TrainingBlock> $blocks
     */
    private function findBlockForDay(array $blocks, SerializableDateTime $day): ?TrainingBlock
    {
        foreach ($blocks as $block) {
            if ($block->containsDay($day)) {
                return $block;
            }
        }

        return null;
    }

    /**
     * @param list<TrainingBlock> $blocks
     * @param list<PlannedSession> $plannedSessions
     * @param array<string, null|float> $plannedSessionEstimatesById
     *
     * @return list<PlanAdaptationRecommendation>
     */
    private function checkUpcomingWeekCoverage(
        array $blocks,
        array $plannedSessions,
        array $plannedSessionEstimatesById,
        RaceProfileTrainingRules $rules,
        SerializableDateTime $now,
    ): array {
        $recommendations = [];
        $today = $now->setTime(0, 0);
        $reviewedUpcomingWeeks = 0;

        foreach ($blocks as $block) {
            if ($block->getEndDay() < $today) {
                continue;
            }

            $weekStart = $block->getStartDay();

            while ($weekStart <= $block->getEndDay()) {
                $weekEnd = min($weekStart->modify('+6 days'), $block->getEndDay());
                if ($weekEnd < $today) {
                    $weekStart = $weekEnd->modify('+1 day');

                    continue;
                }

                $sessionsForWeek = array_values(array_filter(
                    $plannedSessions,
                    static fn (PlannedSession $session): bool => $session->getDay() >= $weekStart && $session->getDay() <= $weekEnd,
                ));
                $sessionCount = count($sessionsForWeek);
                $hardSessionCount = 0;

                foreach ($sessionsForWeek as $session) {
                    if ($this->isHardSession($session, $plannedSessionEstimatesById)) {
                        ++$hardSessionCount;
                    }
                }

                if (0 === $sessionCount) {
                    $recommendations[] = PlanAdaptationRecommendation::create(
                        type: PlanAdaptationRecommendationType::INCREASE_LOAD,
                        title: sprintf('No sessions in %s week', ucfirst($block->getPhase()->value)),
                        body: sprintf(
                            'The %s week starting on %s has no planned sessions yet. Add workouts or let the planner fill that week before it arrives.',
                            $block->getPhase()->value,
                            $weekStart->format('M j'),
                        ),
                        severity: PlanAdaptationWarningSeverity::WARNING,
                    );
                } elseif ($sessionCount < $rules->getSessionsPerWeekMinimum()) {
                    $recommendations[] = PlanAdaptationRecommendation::create(
                        type: PlanAdaptationRecommendationType::INCREASE_LOAD,
                        title: sprintf('Light %s week ahead', $block->getPhase()->value),
                        body: sprintf(
                            'Only %d session%s are planned for the %s week starting on %s. This race profile usually needs at least %d session%s per week.',
                            $sessionCount,
                            1 === $sessionCount ? '' : 's',
                            $block->getPhase()->value,
                            $weekStart->format('M j'),
                            $rules->getSessionsPerWeekMinimum(),
                            1 === $rules->getSessionsPerWeekMinimum() ? '' : 's',
                        ),
                        severity: PlanAdaptationWarningSeverity::INFO,
                    );
                }

                if ($hardSessionCount > $rules->getHardSessionsPerWeek() + 1) {
                    $recommendations[] = PlanAdaptationRecommendation::create(
                        type: PlanAdaptationRecommendationType::REDUCE_LOAD,
                        title: sprintf('Hard sessions stack up in %s week', $block->getPhase()->value),
                        body: sprintf(
                            'The week starting on %s already contains %d hard sessions. Replace one or two with easier work to stay within the recommended range.',
                            $weekStart->format('M j'),
                            $hardSessionCount,
                        ),
                        severity: PlanAdaptationWarningSeverity::WARNING,
                    );
                }

                ++$reviewedUpcomingWeeks;
                if ($reviewedUpcomingWeeks >= 4) {
                    return $recommendations;
                }

                $weekStart = $weekEnd->modify('+1 day');
            }
        }

        return $recommendations;
    }

    /**
     * @param array<string, null|float> $plannedSessionEstimatesById
     */
    private function isHardSession(PlannedSession $plannedSession, array $plannedSessionEstimatesById): bool
    {
        $targetIntensity = $plannedSession->getTargetIntensity();
        if (null !== $targetIntensity) {
            return PlannedSessionIntensity::HARD === $targetIntensity || PlannedSessionIntensity::RACE === $targetIntensity;
        }

        return ($plannedSessionEstimatesById[(string) $plannedSession->getId()] ?? 0.0) >= 110.0;
    }
}
