<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner\Analysis;

use App\Domain\Activity\ActivityType;
use App\Domain\TrainingPlanner\PlanGenerator\PlanAdaptationWarning;
use App\Domain\TrainingPlanner\PlanGenerator\ProposedSession;
use App\Domain\TrainingPlanner\PlanGenerator\ProposedTrainingBlock;
use App\Domain\TrainingPlanner\PlanGenerator\TrainingPlanProposal;
use App\Domain\TrainingPlanner\TrainingBlockPhase;
use App\Domain\TrainingPlanner\TrainingFocus;
use App\Domain\TrainingPlanner\TrainingPlanDiscipline;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final class TrainingPlanQualityAnalyzer
{
    public function analyze(TrainingPlanAnalysisScenario $scenario, TrainingPlanProposal $proposal): TrainingPlanQualityReport
    {
        $rules = $proposal->getRules();
        $issueBuckets = [];
        $weekRows = [];
        $totalRecoveryWeeks = 0;
        $weeklySessionCounts = [];
        $globalWeekNumber = 1;
        $proposalWarningIssues = $this->buildIssuesFromPlanWarnings($proposal->getWarnings());

        foreach ($proposal->getProposedBlocks() as $block) {
            foreach ($block->getWeekSkeletons() as $week) {
                $disciplineCounts = [
                    'swim' => 0,
                    'bike' => 0,
                    'run' => 0,
                ];
                $disciplineDurations = [
                    'swim' => $week->getTargetDurationInSecondsForActivityType(ActivityType::WATER_SPORTS),
                    'bike' => $week->getTargetDurationInSecondsForActivityType(ActivityType::RIDE),
                    'run' => $week->getTargetDurationInSecondsForActivityType(ActivityType::RUN),
                ];
                $hardSessionCount = 0;
                $keySessionCount = 0;
                $brickSessionCount = 0;
                $contradictoryEasyLabelCount = 0;
                $longSessionCount = 0;
                $hardDayStreakCount = 0;
                $previousHardDay = null;

                $sessionsByDay = [];
                foreach ($week->getSessions() as $session) {
                    $sessionsByDay[$session->getDay()->format('Y-m-d')][] = $session;
                }

                foreach ($week->getSessions() as $session) {
                    $disciplineKey = $this->mapActivityTypeToSummaryKey($session->getActivityType());
                    if (null !== $disciplineKey) {
                        ++$disciplineCounts[$disciplineKey];
                    }

                    if ($session->isKeySession()) {
                        ++$keySessionCount;
                    }

                    if ($session->isBrickSession()) {
                        ++$brickSessionCount;
                    }

                    if (in_array($session->getTargetIntensity()->value, ['hard', 'race'], true)) {
                        ++$hardSessionCount;
                    }

                    if ($this->hasContradictoryEasyLabel($session)) {
                        ++$contradictoryEasyLabelCount;
                    }

                    if ($this->isLongSession($session)) {
                        ++$longSessionCount;
                    }
                }

                foreach (array_keys($sessionsByDay) as $sessionDay) {
                    $hasHardOrKeyDay = false;
                    foreach ($sessionsByDay[$sessionDay] as $session) {
                        if ($session->isKeySession() || in_array($session->getTargetIntensity()->value, ['hard', 'race'], true)) {
                            $hasHardOrKeyDay = true;

                            break;
                        }
                    }

                    if (!$hasHardOrKeyDay) {
                        continue;
                    }

                    if (null !== $previousHardDay) {
                        $daysBetweenHardDays = (int) SerializableDateTime::fromString(sprintf('%s 00:00:00', $previousHardDay))
                            ->diff(SerializableDateTime::fromString(sprintf('%s 00:00:00', $sessionDay)))
                            ->days;
                        if (1 === $daysBetweenHardDays) {
                            ++$hardDayStreakCount;
                        }
                    }

                    $previousHardDay = $sessionDay;
                }

                $weeklySessionCounts[] = $week->getSessionCount();
                if ($week->isRecoveryWeek()) {
                    ++$totalRecoveryWeeks;
                }

                $missingRequiredDisciplines = $this->resolveMissingRequiredDisciplines(
                    proposal: $proposal,
                    block: $block,
                    disciplineDurations: $disciplineDurations,
                );
                $focusMismatch = $this->hasFocusMismatch($scenario, $block, $disciplineCounts, $disciplineDurations);
                $weekReference = $this->formatWeekReference($block, $globalWeekNumber, $week->getTargetLoadPercentage(), $week->getStartDay()->format('Y-m-d'));

                if ($week->isRecoveryWeek() && $week->getTargetLoadPercentage() > 85) {
                    $this->addIssueExample($issueBuckets, 'shallow_recovery_weeks', $weekReference);
                }

                if ([] !== $missingRequiredDisciplines) {
                    $this->addIssueExample(
                        $issueBuckets,
                        'missing_required_disciplines',
                        sprintf('%s missing %s', $weekReference, implode(', ', $missingRequiredDisciplines)),
                    );
                }

                if ($contradictoryEasyLabelCount > 0) {
                    $this->addIssueExample(
                        $issueBuckets,
                        'contradictory_easy_labels',
                        sprintf('%s (%d easy-labelled key/hard session%s)', $weekReference, $contradictoryEasyLabelCount, 1 === $contradictoryEasyLabelCount ? '' : 's'),
                    );
                }

                if ($focusMismatch) {
                    $this->addIssueExample($issueBuckets, 'focus_imbalance_weeks', $weekReference);
                }

                if (
                    !$week->isRecoveryWeek()
                    && in_array($block->getPhase(), [TrainingBlockPhase::BASE, TrainingBlockPhase::BUILD, TrainingBlockPhase::PEAK], true)
                    && $hardDayStreakCount > 0
                ) {
                    $this->addIssueExample(
                        $issueBuckets,
                        'hard_day_clustering',
                        sprintf('%s (%d adjacent hard-day pairing%s)', $weekReference, $hardDayStreakCount, 1 === $hardDayStreakCount ? '' : 's'),
                    );
                }

                if (!$week->isRecoveryWeek() && $hardSessionCount > ($rules->getHardSessionsPerWeek() + 1)) {
                    $this->addIssueExample(
                        $issueBuckets,
                        'hard_session_overload',
                        sprintf('%s (%d hard sessions)', $weekReference, $hardSessionCount),
                    );
                }

                $maxExpectedKeySessions = $rules->getHardSessionsPerWeek() + $rules->getLongSessionsPerWeek() + ($rules->needsBrickSessions() ? 1 : 0);
                if ($keySessionCount > $maxExpectedKeySessions) {
                    $this->addIssueExample(
                        $issueBuckets,
                        'key_session_overload',
                        sprintf('%s (%d key sessions)', $weekReference, $keySessionCount),
                    );
                }

                if (
                    !$week->isRecoveryWeek()
                    && in_array($block->getPhase(), [TrainingBlockPhase::BASE, TrainingBlockPhase::BUILD, TrainingBlockPhase::PEAK], true)
                    && $longSessionCount < $rules->getLongSessionsPerWeek()
                ) {
                    $this->addIssueExample(
                        $issueBuckets,
                        'insufficient_long_sessions',
                        sprintf('%s (%d long session%s)', $weekReference, $longSessionCount, 1 === $longSessionCount ? '' : 's'),
                    );
                }

                if (
                    !$week->isRecoveryWeek()
                    && in_array($block->getPhase(), [TrainingBlockPhase::BASE, TrainingBlockPhase::BUILD, TrainingBlockPhase::PEAK], true)
                    && $week->getSessionCount() < $rules->getSessionsPerWeekMinimum()
                ) {
                    $this->addIssueExample(
                        $issueBuckets,
                        'insufficient_session_density',
                        sprintf('%s (%d sessions)', $weekReference, $week->getSessionCount()),
                    );
                }

                $weekRows[] = [
                    'planWeek' => $globalWeekNumber,
                    'phase' => $block->getPhase()->value,
                    'startDay' => $week->getStartDay()->format('Y-m-d'),
                    'endDay' => $week->getEndDay()->format('Y-m-d'),
                    'loadPercentage' => $week->getTargetLoadPercentage(),
                    'isRecoveryWeek' => $week->isRecoveryWeek(),
                    'sessionCount' => $week->getSessionCount(),
                    'hardSessionCount' => $hardSessionCount,
                    'keySessionCount' => $keySessionCount,
                    'brickSessionCount' => $brickSessionCount,
                    'longSessionCount' => $longSessionCount,
                    'hardDayStreakCount' => $hardDayStreakCount,
                    'disciplineCounts' => $disciplineCounts,
                    'disciplineDurationsInSeconds' => $disciplineDurations,
                    'missingRequiredDisciplines' => $missingRequiredDisciplines,
                    'contradictoryEasyLabelCount' => $contradictoryEasyLabelCount,
                    'focusMismatch' => $focusMismatch,
                ];

                ++$globalWeekNumber;
            }
        }

        if ($proposal->getTotalWeeks() >= 8 && 0 === $totalRecoveryWeeks) {
            $this->addIssueExample($issueBuckets, 'missing_recovery_weeks', 'No recovery weeks were generated in a plan that is long enough to expect them.');
        }

        $issues = [
            ...$this->finalizeIssues($issueBuckets),
            ...$proposalWarningIssues,
        ];
        $score = $this->calculateScore($issues);

        return TrainingPlanQualityReport::create(
            scenario: $scenario,
            score: $score,
            metrics: [
                'totalWeeks' => $proposal->getTotalWeeks(),
                'totalSessions' => $proposal->getTotalProposedSessions(),
                'warningCount' => count($issues),
                'generatorWarningCount' => count($proposalWarningIssues),
                'weeklySessionsMin' => [] === $weeklySessionCounts ? 0 : min($weeklySessionCounts),
                'weeklySessionsMax' => [] === $weeklySessionCounts ? 0 : max($weeklySessionCounts),
                'recoveryWeekCount' => $totalRecoveryWeeks,
                'shallowRecoveryWeekCount' => $issueBuckets['shallow_recovery_weeks']['count'] ?? 0,
                'missingRequiredDisciplineWeekCount' => $issueBuckets['missing_required_disciplines']['count'] ?? 0,
                'focusImbalanceWeekCount' => $issueBuckets['focus_imbalance_weeks']['count'] ?? 0,
                'contradictoryEasyLabelWeekCount' => $issueBuckets['contradictory_easy_labels']['count'] ?? 0,
                'hardSessionOverloadWeekCount' => $issueBuckets['hard_session_overload']['count'] ?? 0,
                'keySessionOverloadWeekCount' => $issueBuckets['key_session_overload']['count'] ?? 0,
                'insufficientSessionDensityWeekCount' => $issueBuckets['insufficient_session_density']['count'] ?? 0,
                'hardDayClusteringWeekCount' => $issueBuckets['hard_day_clustering']['count'] ?? 0,
                'insufficientLongSessionWeekCount' => $issueBuckets['insufficient_long_sessions']['count'] ?? 0,
            ],
            issues: $issues,
            weekRows: $weekRows,
        );
    }

    private function mapActivityTypeToSummaryKey(ActivityType $activityType): ?string
    {
        return match ($activityType) {
            ActivityType::WATER_SPORTS => 'swim',
            ActivityType::RIDE => 'bike',
            ActivityType::RUN => 'run',
            default => null,
        };
    }

    /**
     * @param array{swim: int, bike: int, run: int} $disciplineDurations
     *
     * @return list<string>
     */
    private function resolveMissingRequiredDisciplines(
        TrainingPlanProposal $proposal,
        ProposedTrainingBlock $block,
        array $disciplineDurations,
    ): array {
        if (!in_array($block->getPhase(), [TrainingBlockPhase::BASE, TrainingBlockPhase::BUILD, TrainingBlockPhase::PEAK], true)) {
            return [];
        }

        $requiredDisciplines = [];
        if ($proposal->getRules()->needsSwimSessions()) {
            $requiredDisciplines['swim'] = true;
        }
        if ($proposal->getRules()->needsBikeSessions()) {
            $requiredDisciplines['bike'] = true;
        }
        if ($proposal->getRules()->needsRunSessions()) {
            $requiredDisciplines['run'] = true;
        }

        $missing = [];
        foreach (array_keys($requiredDisciplines) as $disciplineKey) {
            if (($disciplineDurations[$disciplineKey] ?? 0) <= 0) {
                $missing[] = $disciplineKey;
            }
        }

        return $missing;
    }

    /**
     * @param array{swim: int, bike: int, run: int} $disciplineCounts
     * @param array{swim: int, bike: int, run: int} $disciplineDurations
     */
    private function hasFocusMismatch(
        TrainingPlanAnalysisScenario $scenario,
        ProposedTrainingBlock $block,
        array $disciplineCounts,
        array $disciplineDurations,
    ): bool {
        if (TrainingPlanDiscipline::TRIATHLON !== $scenario->getDiscipline()) {
            return false;
        }

        if (!in_array($block->getPhase(), [TrainingBlockPhase::BASE, TrainingBlockPhase::BUILD, TrainingBlockPhase::PEAK], true)) {
            return false;
        }

        $focusKey = match ($scenario->getTrainingFocus()) {
            TrainingFocus::RUN => 'run',
            TrainingFocus::BIKE => 'bike',
            TrainingFocus::SWIM => 'swim',
            default => null,
        };

        if (null === $focusKey) {
            return false;
        }

        $supportKeys = array_values(array_filter(['swim', 'bike', 'run'], static fn (string $key): bool => $key !== $focusKey));
        $maxSupportSessionCount = 0;
        $maxSupportDuration = 0;
        foreach ($supportKeys as $supportKey) {
            $maxSupportSessionCount = max($maxSupportSessionCount, $disciplineCounts[$supportKey] ?? 0);
            $maxSupportDuration = max($maxSupportDuration, $disciplineDurations[$supportKey] ?? 0);
        }

        $focusSessionCount = $disciplineCounts[$focusKey] ?? 0;
        $focusDuration = $disciplineDurations[$focusKey] ?? 0;

        return $focusSessionCount < $maxSupportSessionCount && $focusDuration < (int) round($maxSupportDuration * 0.9);
    }

    private function hasContradictoryEasyLabel(ProposedSession $session): bool
    {
        $title = mb_strtolower(trim((string) $session->getTitle()));
        if ('' === $title || !str_contains($title, 'easy')) {
            return false;
        }

        return $session->isKeySession() || in_array($session->getTargetIntensity()->value, ['hard', 'race'], true);
    }

    private function isLongSession(ProposedSession $session): bool
    {
        $title = mb_strtolower(trim((string) $session->getTitle()));
        if (str_contains($title, 'long')) {
            return true;
        }

        $durationInSeconds = max(0, $session->getTargetDurationInSeconds() ?? 0);

        return match ($session->getActivityType()) {
            ActivityType::WATER_SPORTS => $durationInSeconds >= 2_700,
            ActivityType::RIDE => $durationInSeconds >= 6_300,
            ActivityType::RUN => $durationInSeconds >= 4_500,
            default => false,
        };
    }

    /**
     * @param array<string, array{count: int, examples: list<string>}> $issueBuckets
     */
    private function addIssueExample(array &$issueBuckets, string $code, string $example): void
    {
        if (!array_key_exists($code, $issueBuckets)) {
            $issueBuckets[$code] = [
                'count' => 0,
                'examples' => [],
            ];
        }

        ++$issueBuckets[$code]['count'];
        if (count($issueBuckets[$code]['examples']) < 3) {
            $issueBuckets[$code]['examples'][] = $example;
        }
    }

    /**
     * @param array<string, array{count: int, examples: list<string>}> $issueBuckets
     *
     * @return list<TrainingPlanAnalysisIssue>
     */
    private function finalizeIssues(array $issueBuckets): array
    {
        $issues = [];

        if (($issueBuckets['shallow_recovery_weeks']['count'] ?? 0) > 0) {
            $count = $issueBuckets['shallow_recovery_weeks']['count'];
            $issues[] = TrainingPlanAnalysisIssue::create(
                code: 'shallow_recovery_weeks',
                severity: 'warning',
                message: sprintf('%d recovery week%s stay above 85%% load.', $count, 1 === $count ? '' : 's'),
                examples: $issueBuckets['shallow_recovery_weeks']['examples'],
            );
        }

        if (($issueBuckets['missing_required_disciplines']['count'] ?? 0) > 0) {
            $count = $issueBuckets['missing_required_disciplines']['count'];
            $issues[] = TrainingPlanAnalysisIssue::create(
                code: 'missing_required_disciplines',
                severity: 'critical',
                message: sprintf('%d build/base/peak week%s miss required disciplines for the target profile.', $count, 1 === $count ? '' : 's'),
                examples: $issueBuckets['missing_required_disciplines']['examples'],
            );
        }

        if (($issueBuckets['contradictory_easy_labels']['count'] ?? 0) > 0) {
            $count = $issueBuckets['contradictory_easy_labels']['count'];
            $issues[] = TrainingPlanAnalysisIssue::create(
                code: 'contradictory_easy_labels',
                severity: 'warning',
                message: sprintf('%d week%s contain easy-labelled sessions that are still hard or key.', $count, 1 === $count ? '' : 's'),
                examples: $issueBuckets['contradictory_easy_labels']['examples'],
            );
        }

        if (($issueBuckets['focus_imbalance_weeks']['count'] ?? 0) > 0) {
            $count = $issueBuckets['focus_imbalance_weeks']['count'];
            $issues[] = TrainingPlanAnalysisIssue::create(
                code: 'focus_imbalance_weeks',
                severity: 'warning',
                message: sprintf('%d week%s do not materially bias training toward the configured triathlon focus.', $count, 1 === $count ? '' : 's'),
                examples: $issueBuckets['focus_imbalance_weeks']['examples'],
            );
        }

        if (($issueBuckets['hard_session_overload']['count'] ?? 0) > 0) {
            $count = $issueBuckets['hard_session_overload']['count'];
            $issues[] = TrainingPlanAnalysisIssue::create(
                code: 'hard_session_overload',
                severity: 'warning',
                message: sprintf('%d week%s exceed the expected hard-session cap.', $count, 1 === $count ? '' : 's'),
                examples: $issueBuckets['hard_session_overload']['examples'],
            );
        }

        if (($issueBuckets['key_session_overload']['count'] ?? 0) > 0) {
            $count = $issueBuckets['key_session_overload']['count'];
            $issues[] = TrainingPlanAnalysisIssue::create(
                code: 'key_session_overload',
                severity: 'warning',
                message: sprintf('%d week%s exceed the expected key-session cap.', $count, 1 === $count ? '' : 's'),
                examples: $issueBuckets['key_session_overload']['examples'],
            );
        }

        if (($issueBuckets['insufficient_session_density']['count'] ?? 0) > 0) {
            $count = $issueBuckets['insufficient_session_density']['count'];
            $issues[] = TrainingPlanAnalysisIssue::create(
                code: 'insufficient_session_density',
                severity: 'info',
                message: sprintf('%d non-recovery week%s fall below the configured minimum session count.', $count, 1 === $count ? '' : 's'),
                examples: $issueBuckets['insufficient_session_density']['examples'],
            );
        }

        if (($issueBuckets['hard_day_clustering']['count'] ?? 0) > 0) {
            $count = $issueBuckets['hard_day_clustering']['count'];
            $issues[] = TrainingPlanAnalysisIssue::create(
                code: 'hard_day_clustering',
                severity: 'warning',
                message: sprintf('%d week%s stack hard or key work on adjacent days.', $count, 1 === $count ? '' : 's'),
                examples: $issueBuckets['hard_day_clustering']['examples'],
            );
        }

        if (($issueBuckets['insufficient_long_sessions']['count'] ?? 0) > 0) {
            $count = $issueBuckets['insufficient_long_sessions']['count'];
            $issues[] = TrainingPlanAnalysisIssue::create(
                code: 'insufficient_long_sessions',
                severity: 'warning',
                message: sprintf('%d build/base/peak week%s fall short on expected long-session volume.', $count, 1 === $count ? '' : 's'),
                examples: $issueBuckets['insufficient_long_sessions']['examples'],
            );
        }

        if (($issueBuckets['missing_recovery_weeks']['count'] ?? 0) > 0) {
            $issues[] = TrainingPlanAnalysisIssue::create(
                code: 'missing_recovery_weeks',
                severity: 'warning',
                message: 'The plan does not contain any recovery weeks even though the total length suggests they should exist.',
                examples: $issueBuckets['missing_recovery_weeks']['examples'],
            );
        }

        return $issues;
    }

    /**
     * @param list<PlanAdaptationWarning> $warnings
     *
     * @return list<TrainingPlanAnalysisIssue>
     */
    private function buildIssuesFromPlanWarnings(array $warnings): array
    {
        $buckets = [];

        foreach ($warnings as $warning) {
            $code = $this->normalizeWarningCode($warning->getType()->value);
            if (!array_key_exists($code, $buckets)) {
                $buckets[$code] = [
                    'count' => 0,
                    'severity' => $warning->getSeverity()->value,
                    'body' => $warning->getBody(),
                    'examples' => [],
                ];
            }

            ++$buckets[$code]['count'];
            if (count($buckets[$code]['examples']) < 3) {
                $buckets[$code]['examples'][] = $warning->getTitle();
            }
        }

        $issues = [];
        foreach ($buckets as $code => $bucket) {
            $message = 1 === $bucket['count']
                ? $bucket['body']
                : sprintf('%s (%d occurrences)', $bucket['body'], $bucket['count']);

            $issues[] = TrainingPlanAnalysisIssue::create(
                code: $code,
                severity: $bucket['severity'],
                message: $message,
                examples: $bucket['examples'],
            );
        }

        return $issues;
    }

    private function normalizeWarningCode(string $code): string
    {
        return mb_strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $code));
    }

    /**
     * @param list<TrainingPlanAnalysisIssue> $issues
     */
    private function calculateScore(array $issues): int
    {
        $score = 100;
        foreach ($issues as $issue) {
            $score -= match ($issue->getSeverity()) {
                'critical' => 12,
                'warning' => 6,
                default => 2,
            };
        }

        return max(0, $score);
    }

    private function formatWeekReference(ProposedTrainingBlock $block, int $globalWeekNumber, int $loadPercentage, string $startDay): string
    {
        return sprintf('%s W%d · %d%% · %s', ucfirst($block->getPhase()->value), $globalWeekNumber, $loadPercentage, $startDay);
    }
}
