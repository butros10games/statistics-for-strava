<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner\Prediction;

use App\Domain\Activity\ActivityType;
use App\Domain\TrainingPlanner\PlanGenerator\ProposedSession;
use App\Domain\TrainingPlanner\PlanGenerator\ProposedWeekSkeleton;
use App\Domain\TrainingPlanner\PlanGenerator\TrainingPlanProposal;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Domain\TrainingPlanner\PlannedSessionLinkStatus;
use App\Domain\TrainingPlanner\RaceEventProfile;
use App\Domain\TrainingPlanner\TrainingBlockPhase;
use App\Domain\TrainingPlanner\TrainingFocus;
use App\Domain\TrainingPlanner\TrainingPlan;
use App\Domain\TrainingPlanner\TrainingPlanDiscipline;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final class RunningPlanPerformancePredictor
{
    public const MODEL_VERSION = RunningPlanPerformancePrediction::DEFAULT_MODEL_VERSION;

    /**
     * @param list<PlannedSession> $existingSessions
     */
    public function predict(
        TrainingPlan $trainingPlan,
        TrainingPlanProposal $proposal,
        array $existingSessions = [],
        ?SerializableDateTime $referenceDate = null,
    ): ?RunningPlanPerformancePrediction {
        $currentThresholdPaceInSeconds = $this->resolveCurrentThresholdPaceInSeconds($trainingPlan);
        if (null === $currentThresholdPaceInSeconds) {
            return null;
        }

        $projectedImprovementRatio = $this->resolveProjectedImprovementRatio(
            trainingPlan: $trainingPlan,
            proposal: $proposal,
            currentThresholdPaceInSeconds: $currentThresholdPaceInSeconds,
        );
        $projectedThresholdPaceInSeconds = max(
            150,
            (int) round($currentThresholdPaceInSeconds * (1 - $projectedImprovementRatio)),
        );
        $adherenceSnapshot = $this->buildAdherenceSnapshot($trainingPlan, $proposal, $existingSessions, $referenceDate);
        $trajectoryThresholdPaceInSeconds = null;
        $guidanceThresholdPaceInSeconds = $projectedThresholdPaceInSeconds;

        if ($adherenceSnapshot?->hasMeasuredProgress()) {
            $trajectoryImprovementRatio = $this->resolveTrajectoryImprovementRatio(
                $projectedImprovementRatio,
                $adherenceSnapshot,
                $proposal,
            );
            $trajectoryThresholdPaceInSeconds = max(
                150,
                (int) round($currentThresholdPaceInSeconds * (1 - $trajectoryImprovementRatio)),
            );
            $guidanceThresholdPaceInSeconds = $trajectoryThresholdPaceInSeconds;
        }
        $confidenceFactors = $this->buildConfidenceFactors($trainingPlan, $proposal, $adherenceSnapshot);
        $confidenceScore = $this->resolveConfidenceScore($confidenceFactors);

        return new RunningPlanPerformancePrediction(
            currentThresholdPaceInSeconds: $currentThresholdPaceInSeconds,
            projectedThresholdPaceInSeconds: $projectedThresholdPaceInSeconds,
            trajectoryThresholdPaceInSeconds: $trajectoryThresholdPaceInSeconds,
            confidenceLabel: $this->resolveConfidenceLabel($confidenceScore),
            benchmarkPredictions: $this->buildBenchmarkPredictions(
                profile: $trainingPlan->getTargetRaceProfile() ?? $proposal->getTargetRace()->getProfile(),
                currentThresholdPaceInSeconds: $currentThresholdPaceInSeconds,
                projectedThresholdPaceInSeconds: $projectedThresholdPaceInSeconds,
            ),
            projectedThresholdPaceByWeekStartDate: $this->buildProjectedThresholdPaceByWeekStartDate(
                proposal: $proposal,
                currentThresholdPaceInSeconds: $currentThresholdPaceInSeconds,
                projectedThresholdPaceInSeconds: $guidanceThresholdPaceInSeconds,
            ),
            adherenceSnapshot: $adherenceSnapshot,
            modelVersion: self::MODEL_VERSION,
            confidenceScore: $confidenceScore,
            confidenceFactors: $confidenceFactors,
            projectedThresholdPaceRange: $this->buildProjectedThresholdPaceRange(
                currentThresholdPaceInSeconds: $currentThresholdPaceInSeconds,
                projectedThresholdPaceInSeconds: $projectedThresholdPaceInSeconds,
                confidenceScore: $confidenceScore,
            ),
        );
    }

    private function resolveTrajectoryImprovementRatio(
        float $projectedImprovementRatio,
        RunningPlanAdherenceSnapshot $adherenceSnapshot,
        TrainingPlanProposal $proposal,
    ): float {
        $elapsedPlanFraction = min(1.0, $adherenceSnapshot->getElapsedPlanWeeks() / max(1, $proposal->getTotalWeeks()));
        $adherenceInfluence = min(0.8, 0.18 + (0.62 * $elapsedPlanFraction));
        $trajectoryMultiplier = 1.0 - ((1.0 - $adherenceSnapshot->getAdherenceScore()) * $adherenceInfluence);

        return $projectedImprovementRatio * max(0.55, min(1.0, $trajectoryMultiplier));
    }

    private function resolveCurrentThresholdPaceInSeconds(TrainingPlan $trainingPlan): ?int
    {
        $performanceMetrics = $trainingPlan->getPerformanceMetrics();
        if (!is_array($performanceMetrics)) {
            return null;
        }

        if (!isset($performanceMetrics['runningThresholdPace']) || !is_numeric($performanceMetrics['runningThresholdPace'])) {
            return null;
        }

        $thresholdPaceInSeconds = (int) $performanceMetrics['runningThresholdPace'];

        return $thresholdPaceInSeconds >= 150 && $thresholdPaceInSeconds <= 600
            ? $thresholdPaceInSeconds
            : null;
    }

    private function resolveProjectedImprovementRatio(
        TrainingPlan $trainingPlan,
        TrainingPlanProposal $proposal,
        int $currentThresholdPaceInSeconds,
    ): float {
        $performanceMetrics = $trainingPlan->getPerformanceMetrics();
        $weeklyRunningVolume = is_array($performanceMetrics)
            && isset($performanceMetrics['weeklyRunningVolume'])
            && is_numeric($performanceMetrics['weeklyRunningVolume'])
            ? (float) $performanceMetrics['weeklyRunningVolume']
            : null;
        $runningStructure = $this->summarizeRunningStructure($proposal);

        $durationFactor = $this->resolveDurationFactor($runningStructure['effectiveRunningWeeks']);
        $disciplineFactor = $this->resolveDisciplineFactor($trainingPlan->getDiscipline());
        $focusFactor = $this->resolveFocusFactor($trainingPlan->getTrainingFocus());
        $volumeFactor = $this->resolveBaselineVolumeFactor($weeklyRunningVolume);
        $sessionDensityFactor = $this->resolveSessionDensityFactor($runningStructure['averageRunSessionsPerWeek']);
        $keySessionFactor = $this->resolveKeySessionFactor($runningStructure['averageKeyRunsPerWeek']);
        $longRunFactor = $this->resolveLongRunFactor($runningStructure['averageLongRunsPerWeek']);
        $phaseSpecificityFactor = $this->resolvePhaseSpecificityFactor($runningStructure['phaseQualityScore']);
        $plannedExposureFactor = $this->resolvePlannedExposureFactor($runningStructure['averageRunningMinutesPerWeek']);
        $baselineHeadroomFactor = $this->resolveBaselineHeadroomFactor($currentThresholdPaceInSeconds);

        $projectedImprovementRatio = 0.024
            * $durationFactor
            * $disciplineFactor
            * $focusFactor
            * $volumeFactor
            * $sessionDensityFactor
            * $keySessionFactor
            * $longRunFactor
            * $phaseSpecificityFactor
            * $plannedExposureFactor
            * $baselineHeadroomFactor;

        return min(0.05, max(0.008, $projectedImprovementRatio));
    }

    private function resolveDisciplineFactor(?TrainingPlanDiscipline $discipline): float
    {
        return match ($discipline) {
            TrainingPlanDiscipline::RUNNING => 1.0,
            TrainingPlanDiscipline::TRIATHLON => 0.84,
            TrainingPlanDiscipline::CYCLING => 0.45,
            null => 0.75,
        };
    }

    private function resolveFocusFactor(?TrainingFocus $trainingFocus): float
    {
        return match ($trainingFocus) {
            TrainingFocus::RUN => 1.0,
            TrainingFocus::GENERAL, null => 0.9,
            TrainingFocus::BIKE => 0.65,
            TrainingFocus::SWIM => 0.55,
        };
    }

    private function resolveBaselineVolumeFactor(?float $weeklyRunningVolume): float
    {
        return match (true) {
            null === $weeklyRunningVolume => 0.82,
            $weeklyRunningVolume < 25.0 => 1.03,
            $weeklyRunningVolume < 45.0 => 1.0,
            $weeklyRunningVolume < 60.0 => 0.95,
            $weeklyRunningVolume < 75.0 => 0.9,
            default => 0.85,
        };
    }

    private function resolveDurationFactor(int $effectiveRunningWeeks): float
    {
        if ($effectiveRunningWeeks <= 0) {
            return 0.72;
        }

        return min(1.22, 0.45 + (0.85 * (1 - exp(-$effectiveRunningWeeks / 9.0))));
    }

    private function resolveSessionDensityFactor(float $averageRunSessionsPerWeek): float
    {
        return match (true) {
            $averageRunSessionsPerWeek <= 0.0 => 0.78,
            $averageRunSessionsPerWeek < 2.0 => 0.82,
            $averageRunSessionsPerWeek < 3.0 => 0.92,
            $averageRunSessionsPerWeek < 4.0 => 1.0,
            $averageRunSessionsPerWeek < 5.0 => 1.05,
            default => 1.09,
        };
    }

    private function resolveKeySessionFactor(float $averageKeyRunsPerWeek): float
    {
        return match (true) {
            $averageKeyRunsPerWeek <= 0.0 => 0.8,
            $averageKeyRunsPerWeek < 0.5 => 0.84,
            $averageKeyRunsPerWeek < 1.0 => 0.95,
            $averageKeyRunsPerWeek < 1.5 => 1.03,
            default => 1.08,
        };
    }

    private function resolveLongRunFactor(float $averageLongRunsPerWeek): float
    {
        return match (true) {
            $averageLongRunsPerWeek <= 0.0 => 0.87,
            $averageLongRunsPerWeek < 0.5 => 0.94,
            $averageLongRunsPerWeek < 1.0 => 0.99,
            default => 1.05,
        };
    }

    private function resolvePhaseSpecificityFactor(float $phaseQualityScore): float
    {
        return max(0.88, min(1.08, $phaseQualityScore));
    }

    private function resolvePlannedExposureFactor(float $averageRunningMinutesPerWeek): float
    {
        return match (true) {
            $averageRunningMinutesPerWeek <= 0.0 => 0.78,
            $averageRunningMinutesPerWeek < 90.0 => 0.82,
            $averageRunningMinutesPerWeek < 150.0 => 0.92,
            $averageRunningMinutesPerWeek < 240.0 => 1.0,
            $averageRunningMinutesPerWeek < 330.0 => 1.05,
            default => 1.08,
        };
    }

    private function resolveBaselineHeadroomFactor(int $currentThresholdPaceInSeconds): float
    {
        return match (true) {
            $currentThresholdPaceInSeconds >= 330 => 1.05,
            $currentThresholdPaceInSeconds >= 285 => 1.02,
            $currentThresholdPaceInSeconds >= 240 => 1.0,
            default => 0.96,
        };
    }

    /**
     * @param list<PlannedSession> $existingSessions
     */
    private function buildAdherenceSnapshot(
        TrainingPlan $trainingPlan,
        TrainingPlanProposal $proposal,
        array $existingSessions,
        ?SerializableDateTime $referenceDate,
    ): ?RunningPlanAdherenceSnapshot {
        if (!$referenceDate instanceof SerializableDateTime) {
            return null;
        }

        $planStartDay = $trainingPlan->getStartDay() < $proposal->getPlanStartDay()
            ? $trainingPlan->getStartDay()->setTime(0, 0)
            : $proposal->getPlanStartDay()->setTime(0, 0);
        $historicalWindowEnd = $referenceDate->modify('-1 day')->setTime(23, 59, 59);

        if ($historicalWindowEnd < $planStartDay) {
            return null;
        }

        $plannedRunSessionCount = 0;
        $completedRunSessionCount = 0;
        $plannedKeyRunSessionCount = 0;
        $completedKeyRunSessionCount = 0;
        $plannedLongRunCount = 0;
        $completedLongRunCount = 0;
        $plannedRunningMinutes = 0;
        $completedRunningMinutes = 0;

        foreach ($existingSessions as $plannedSession) {
            if (ActivityType::RUN !== $plannedSession->getActivityType()) {
                continue;
            }
            if ($plannedSession->getDay() < $planStartDay) {
                continue;
            }
            if ($plannedSession->getDay() > $historicalWindowEnd) {
                continue;
            }

            ++$plannedRunSessionCount;

            $durationInMinutes = (int) round($this->resolvePlannedSessionDurationInSeconds($plannedSession) / 60);
            $plannedRunningMinutes += $durationInMinutes;

            $isKeyRunSession = $this->isKeyPlannedRunSession($plannedSession);
            if ($isKeyRunSession) {
                ++$plannedKeyRunSessionCount;
            }

            $isLongRunSession = $this->isLongRunPlannedSession($plannedSession);
            if ($isLongRunSession) {
                ++$plannedLongRunCount;
            }

            if (!$this->isCompletedPlannedSession($plannedSession)) {
                continue;
            }

            ++$completedRunSessionCount;
            $completedRunningMinutes += $durationInMinutes;

            if ($isKeyRunSession) {
                ++$completedKeyRunSessionCount;
            }

            if ($isLongRunSession) {
                ++$completedLongRunCount;
            }
        }

        if ($plannedRunSessionCount <= 0) {
            return null;
        }

        $elapsedPlanDays = ((int) $planStartDay->diff($historicalWindowEnd)->format('%a')) + 1;

        return new RunningPlanAdherenceSnapshot(
            plannedRunSessionCount: $plannedRunSessionCount,
            completedRunSessionCount: $completedRunSessionCount,
            plannedKeyRunSessionCount: $plannedKeyRunSessionCount,
            completedKeyRunSessionCount: $completedKeyRunSessionCount,
            plannedLongRunCount: $plannedLongRunCount,
            completedLongRunCount: $completedLongRunCount,
            plannedRunningMinutes: $plannedRunningMinutes,
            completedRunningMinutes: $completedRunningMinutes,
            elapsedPlanWeeks: max(1, (int) ceil($elapsedPlanDays / 7)),
        );
    }

    private function isCompletedPlannedSession(PlannedSession $plannedSession): bool
    {
        return PlannedSessionLinkStatus::LINKED === $plannedSession->getLinkStatus()
            && $plannedSession->getLinkedActivityId() instanceof \App\Domain\Activity\ActivityId;
    }

    private function isKeyPlannedRunSession(PlannedSession $plannedSession): bool
    {
        if (ActivityType::RUN !== $plannedSession->getActivityType()) {
            return false;
        }

        if (in_array($plannedSession->getTargetIntensity(), [PlannedSessionIntensity::HARD, PlannedSessionIntensity::RACE], true)) {
            return true;
        }

        $title = strtolower(trim((string) $plannedSession->getTitle()));

        return array_any(['interval', 'tempo', 'threshold', 'hill', 'fartlek', 'progression', 'race pace', 'vo2'], fn (string $needle): bool => str_contains($title, $needle));
    }

    private function isLongRunPlannedSession(PlannedSession $plannedSession): bool
    {
        if (ActivityType::RUN !== $plannedSession->getActivityType()) {
            return false;
        }

        $title = strtolower(trim((string) $plannedSession->getTitle()));
        if (str_contains($title, 'long run')) {
            return true;
        }

        return $this->resolvePlannedSessionDurationInSeconds($plannedSession) >= 5_400;
    }

    private function resolvePlannedSessionDurationInSeconds(PlannedSession $plannedSession): int
    {
        return max(0, $plannedSession->getTargetDurationInSeconds() ?? $plannedSession->getWorkoutDurationInSeconds() ?? 0);
    }

    /**
     * @return list<RunningRaceBenchmarkPrediction>
     */
    private function buildBenchmarkPredictions(
        RaceEventProfile $profile,
        int $currentThresholdPaceInSeconds,
        int $projectedThresholdPaceInSeconds,
    ): array {
        $benchmarkProfiles = $this->resolveBenchmarkProfiles($profile);

        return array_map(
            fn (RaceEventProfile $benchmarkProfile): RunningRaceBenchmarkPrediction => new RunningRaceBenchmarkPrediction(
                label: $this->getBenchmarkLabel($benchmarkProfile),
                distanceInMeters: $this->getBenchmarkDistanceInMeters($benchmarkProfile),
                currentFinishTimeInSeconds: $this->estimateFinishTimeInSeconds($currentThresholdPaceInSeconds, $benchmarkProfile),
                projectedFinishTimeInSeconds: $this->estimateFinishTimeInSeconds($projectedThresholdPaceInSeconds, $benchmarkProfile),
            ),
            $benchmarkProfiles,
        );
    }

    /**
     * @return array<string, int>
     */
    public function buildProjectedThresholdPaceByWeekStartDate(
        TrainingPlanProposal $proposal,
        int $currentThresholdPaceInSeconds,
        int $projectedThresholdPaceInSeconds,
    ): array {
        $projectedGainInSeconds = max(0, $currentThresholdPaceInSeconds - $projectedThresholdPaceInSeconds);
        if (0 === $projectedGainInSeconds) {
            return [];
        }

        $weekWeights = [];
        $totalWeight = 0.0;

        foreach ($proposal->getProposedBlocks() as $block) {
            $phaseWeight = $this->resolvePhaseProgressionWeight($block->getPhase());

            foreach ($block->getWeekSkeletons() as $week) {
                $weekRunningWork = $this->summarizeWeekRunningWork($week);
                $qualityWeight = 1.0
                    + min(0.3, $weekRunningWork['keyRunSessionCount'] * 0.1)
                    + ($weekRunningWork['longRunCount'] > 0 ? 0.08 : 0.0);
                $exposureWeight = match (true) {
                    $weekRunningWork['runningDurationInSeconds'] <= 0 => 0.4,
                    $weekRunningWork['runningDurationInSeconds'] < 5_400 => 0.82,
                    $weekRunningWork['runningDurationInSeconds'] < 9_000 => 0.96,
                    $weekRunningWork['runningDurationInSeconds'] < 13_500 => 1.05,
                    default => 1.12,
                };
                $weight = $phaseWeight * $qualityWeight * $exposureWeight;

                if ($week->isRecoveryWeek()) {
                    $weight *= 0.72;
                }

                $weekWeights[$week->getStartDay()->format('Y-m-d')] = $weight;
                $totalWeight += $weight;
            }
        }

        if ($totalWeight <= 0.0) {
            return [];
        }

        $projectedThresholdPaces = [];
        $accumulatedWeight = 0.0;
        $weekCount = count($weekWeights);

        foreach ($weekWeights as $weekStartDate => $weight) {
            $accumulatedWeight += $weight;
            $progress = 1 === $weekCount
                ? 0.0
                : max(0.0, min(1.0, ($accumulatedWeight - $weight) / max(0.0001, $totalWeight - $weight)));
            $projectedThresholdPaces[$weekStartDate] = max(
                $projectedThresholdPaceInSeconds,
                $currentThresholdPaceInSeconds - (int) round($projectedGainInSeconds * $progress),
            );
        }

        $lastWeekStartDate = array_key_last($projectedThresholdPaces);
        if ($weekCount > 1 && is_string($lastWeekStartDate)) {
            $projectedThresholdPaces[$lastWeekStartDate] = $projectedThresholdPaceInSeconds;
        }

        return $projectedThresholdPaces;
    }

    /**
     * @return list<RunningPlanConfidenceFactor>
     */
    private function buildConfidenceFactors(
        TrainingPlan $trainingPlan,
        TrainingPlanProposal $proposal,
        ?RunningPlanAdherenceSnapshot $adherenceSnapshot,
    ): array {
        $performanceMetrics = $trainingPlan->getPerformanceMetrics();
        $weeklyRunningVolume = is_array($performanceMetrics)
            && isset($performanceMetrics['weeklyRunningVolume'])
            && is_numeric($performanceMetrics['weeklyRunningVolume'])
            ? (float) $performanceMetrics['weeklyRunningVolume']
            : null;
        $runningStructure = $this->summarizeRunningStructure($proposal);

        return [
            new RunningPlanConfidenceFactor(
                key: 'baseline-threshold-pace',
                label: 'Baseline threshold pace',
                score: 94,
                reason: 'A current running threshold pace is available and inside the supported 150-600s/km range.',
                weight: 1.2,
            ),
            $this->buildBaselineVolumeConfidenceFactor($weeklyRunningVolume),
            $this->buildTrainingSpecificityConfidenceFactor($trainingPlan),
            $this->buildPlanDurationConfidenceFactor($runningStructure['effectiveRunningWeeks']),
            $this->buildRunFrequencyConfidenceFactor($runningStructure['averageRunSessionsPerWeek']),
            $this->buildQualityDistributionConfidenceFactor(
                averageKeyRunsPerWeek: $runningStructure['averageKeyRunsPerWeek'],
                averageLongRunsPerWeek: $runningStructure['averageLongRunsPerWeek'],
            ),
            $this->buildPhaseSpecificityConfidenceFactor($runningStructure['phaseQualityScore']),
            $this->buildAdherenceConfidenceFactor($adherenceSnapshot),
        ];
    }

    private function buildBaselineVolumeConfidenceFactor(?float $weeklyRunningVolume): RunningPlanConfidenceFactor
    {
        if (null === $weeklyRunningVolume) {
            return new RunningPlanConfidenceFactor(
                key: 'baseline-volume',
                label: 'Baseline running volume',
                score: 52,
                reason: 'Weekly running volume is missing, so load tolerance is inferred from the plan only.',
                weight: 1.0,
            );
        }

        $score = match (true) {
            $weeklyRunningVolume < 15.0 => 62,
            $weeklyRunningVolume < 25.0 => 74,
            $weeklyRunningVolume < 60.0 => 88,
            $weeklyRunningVolume < 80.0 => 82,
            default => 76,
        };

        return new RunningPlanConfidenceFactor(
            key: 'baseline-volume',
            label: 'Baseline running volume',
            score: $score,
            reason: sprintf('Weekly running volume is %.1fkm, so planned work can be compared with recent load.', $weeklyRunningVolume),
            weight: 1.0,
        );
    }

    private function buildTrainingSpecificityConfidenceFactor(TrainingPlan $trainingPlan): RunningPlanConfidenceFactor
    {
        $discipline = $trainingPlan->getDiscipline();
        $trainingFocus = $trainingPlan->getTrainingFocus();
        $score = match (true) {
            TrainingPlanDiscipline::RUNNING === $discipline && TrainingFocus::RUN === $trainingFocus => 94,
            TrainingPlanDiscipline::TRIATHLON === $discipline && TrainingFocus::RUN === $trainingFocus => 84,
            TrainingPlanDiscipline::RUNNING === $discipline => 82,
            TrainingPlanDiscipline::TRIATHLON === $discipline => 72,
            !$discipline instanceof TrainingPlanDiscipline && !$trainingFocus instanceof TrainingFocus => 58,
            default => 46,
        };

        return new RunningPlanConfidenceFactor(
            key: 'training-specificity',
            label: 'Run specificity',
            score: $score,
            reason: sprintf(
                'Plan discipline is %s and focus is %s.',
                strtolower($discipline?->value ?? 'unspecified'),
                strtolower($trainingFocus?->value ?? 'unspecified'),
            ),
            weight: 1.0,
        );
    }

    private function buildPlanDurationConfidenceFactor(int $effectiveRunningWeeks): RunningPlanConfidenceFactor
    {
        $score = match (true) {
            $effectiveRunningWeeks >= 16 => 92,
            $effectiveRunningWeeks >= 12 => 86,
            $effectiveRunningWeeks >= 8 => 78,
            $effectiveRunningWeeks >= 4 => 58,
            $effectiveRunningWeeks > 0 => 44,
            default => 35,
        };

        return new RunningPlanConfidenceFactor(
            key: 'plan-duration',
            label: 'Plan duration',
            score: $score,
            reason: sprintf('The proposal includes %d running weeks with at least one run session.', $effectiveRunningWeeks),
            weight: 1.0,
        );
    }

    private function buildRunFrequencyConfidenceFactor(float $averageRunSessionsPerWeek): RunningPlanConfidenceFactor
    {
        $score = match (true) {
            $averageRunSessionsPerWeek >= 4.0 => 88,
            $averageRunSessionsPerWeek >= 3.0 => 82,
            $averageRunSessionsPerWeek >= 2.0 => 70,
            $averageRunSessionsPerWeek >= 1.0 => 60,
            $averageRunSessionsPerWeek > 0.0 => 48,
            default => 35,
        };

        return new RunningPlanConfidenceFactor(
            key: 'run-frequency',
            label: 'Run frequency',
            score: $score,
            reason: sprintf('The proposal averages %.1f run sessions per running week.', $averageRunSessionsPerWeek),
            weight: 0.85,
        );
    }

    private function buildQualityDistributionConfidenceFactor(
        float $averageKeyRunsPerWeek,
        float $averageLongRunsPerWeek,
    ): RunningPlanConfidenceFactor {
        $score = match (true) {
            $averageKeyRunsPerWeek >= 1.0 && $averageLongRunsPerWeek >= 0.75 => 90,
            $averageKeyRunsPerWeek >= 0.75 && $averageLongRunsPerWeek > 0.0 => 84,
            $averageKeyRunsPerWeek > 0.0 && $averageLongRunsPerWeek > 0.0 => 78,
            $averageKeyRunsPerWeek > 0.0 => 72,
            $averageLongRunsPerWeek > 0.0 => 66,
            default => 58,
        };

        return new RunningPlanConfidenceFactor(
            key: 'quality-distribution',
            label: 'Workout mix',
            score: $score,
            reason: sprintf(
                'The proposal averages %.1f key runs and %.1f long runs per running week.',
                $averageKeyRunsPerWeek,
                $averageLongRunsPerWeek,
            ),
            weight: 0.75,
        );
    }

    private function buildPhaseSpecificityConfidenceFactor(float $phaseQualityScore): RunningPlanConfidenceFactor
    {
        $boundedPhaseQualityScore = max(0.76, min(1.08, $phaseQualityScore));
        $score = (int) round(55 + ((($boundedPhaseQualityScore - 0.76) / 0.32) * 35));

        return new RunningPlanConfidenceFactor(
            key: 'phase-specificity',
            label: 'Phase specificity',
            score: $score,
            reason: sprintf('The weighted phase specificity score is %.2f.', $phaseQualityScore),
            weight: 0.65,
        );
    }

    private function buildAdherenceConfidenceFactor(?RunningPlanAdherenceSnapshot $adherenceSnapshot): RunningPlanConfidenceFactor
    {
        if (!$adherenceSnapshot instanceof RunningPlanAdherenceSnapshot) {
            return new RunningPlanConfidenceFactor(
                key: 'adherence-trajectory',
                label: 'Completed-session trajectory',
                score: 68,
                reason: 'No historical completed-session trajectory is available yet; the projection is plan-based.',
                weight: 0.0,
            );
        }

        $score = (int) round(58 + (37 * $adherenceSnapshot->getAdherenceScore()));

        return new RunningPlanConfidenceFactor(
            key: 'adherence-trajectory',
            label: 'Completed-session trajectory',
            score: $score,
            reason: sprintf(
                'Completed run adherence is %d%% across %d planned run sessions.',
                (int) round($adherenceSnapshot->getAdherenceScore() * 100),
                $adherenceSnapshot->getPlannedRunSessionCount(),
            ),
            weight: 0.7,
        );
    }

    /**
     * @param list<RunningPlanConfidenceFactor> $confidenceFactors
     */
    private function resolveConfidenceScore(array $confidenceFactors): int
    {
        $weightedScore = 0.0;
        $weightTotal = 0.0;

        foreach ($confidenceFactors as $confidenceFactor) {
            if ($confidenceFactor->getWeight() <= 0.0) {
                continue;
            }

            $weightedScore += $confidenceFactor->getWeightedScore();
            $weightTotal += $confidenceFactor->getWeight();
        }

        if ($weightTotal <= 0.0) {
            return 50;
        }

        return max(0, min(100, (int) round($weightedScore / $weightTotal)));
    }

    private function resolveConfidenceLabel(int $confidenceScore): string
    {
        return match (true) {
            $confidenceScore >= 80 => 'High confidence',
            $confidenceScore >= 58 => 'Medium confidence',
            default => 'Low confidence',
        };
    }

    private function buildProjectedThresholdPaceRange(
        int $currentThresholdPaceInSeconds,
        int $projectedThresholdPaceInSeconds,
        int $confidenceScore,
    ): RunningPlanProjectedThresholdPaceRange {
        $uncertaintyInSeconds = (int) round(4 + (((100 - $confidenceScore) / 100) * 22));
        $uncertaintyInSeconds = max(4, min(26, $uncertaintyInSeconds));

        return new RunningPlanProjectedThresholdPaceRange(
            optimisticPaceInSeconds: max(150, $projectedThresholdPaceInSeconds - $uncertaintyInSeconds),
            expectedPaceInSeconds: $projectedThresholdPaceInSeconds,
            conservativePaceInSeconds: min($currentThresholdPaceInSeconds, $projectedThresholdPaceInSeconds + $uncertaintyInSeconds),
        );
    }

    /**
     * @return array{
     *   effectiveRunningWeeks: int,
     *   runningSessionCount: int,
     *   keyRunSessionCount: int,
     *   longRunCount: int,
     *   averageRunSessionsPerWeek: float,
     *   averageKeyRunsPerWeek: float,
     *   averageLongRunsPerWeek: float,
     *   averageRunningMinutesPerWeek: float,
     *   phaseQualityScore: float
     * }
     */
    private function summarizeRunningStructure(TrainingPlanProposal $proposal): array
    {
        $effectiveRunningWeeks = 0;
        $runningSessionCount = 0;
        $keyRunSessionCount = 0;
        $longRunCount = 0;
        $runningDurationInSeconds = 0;
        $weightedPhaseScore = 0.0;
        $phaseWeightTotal = 0.0;

        foreach ($proposal->getProposedBlocks() as $block) {
            $phaseWeight = $this->resolvePhaseStructureWeight($block->getPhase());

            foreach ($block->getWeekSkeletons() as $week) {
                $weekRunningWork = $this->summarizeWeekRunningWork($week);
                if (0 === $weekRunningWork['runningSessionCount']) {
                    continue;
                }

                ++$effectiveRunningWeeks;
                $runningSessionCount += $weekRunningWork['runningSessionCount'];
                $keyRunSessionCount += $weekRunningWork['keyRunSessionCount'];
                $longRunCount += $weekRunningWork['longRunCount'];
                $runningDurationInSeconds += $weekRunningWork['runningDurationInSeconds'];

                $weekPhaseWeight = $phaseWeight * ($week->isRecoveryWeek() ? 0.82 : 1.0);
                $weekSessionWeight = $weekRunningWork['runningSessionCount'] + (0.35 * $weekRunningWork['keyRunSessionCount']);

                $weightedPhaseScore += $weekPhaseWeight * $weekSessionWeight;
                $phaseWeightTotal += $weekSessionWeight;
            }
        }

        $divisor = max(1, $effectiveRunningWeeks);

        return [
            'effectiveRunningWeeks' => $effectiveRunningWeeks,
            'runningSessionCount' => $runningSessionCount,
            'keyRunSessionCount' => $keyRunSessionCount,
            'longRunCount' => $longRunCount,
            'averageRunSessionsPerWeek' => $runningSessionCount / $divisor,
            'averageKeyRunsPerWeek' => $keyRunSessionCount / $divisor,
            'averageLongRunsPerWeek' => $longRunCount / $divisor,
            'averageRunningMinutesPerWeek' => ($runningDurationInSeconds / 60) / $divisor,
            'phaseQualityScore' => $phaseWeightTotal > 0.0 ? $weightedPhaseScore / $phaseWeightTotal : 0.88,
        ];
    }

    /**
     * @return array{runningSessionCount: int, keyRunSessionCount: int, longRunCount: int, runningDurationInSeconds: int}
     */
    private function summarizeWeekRunningWork(ProposedWeekSkeleton $week): array
    {
        $runningSessionCount = 0;
        $keyRunSessionCount = 0;
        $longRunCount = 0;
        $runningDurationInSeconds = 0;

        foreach ($week->getSessions() as $session) {
            if (ActivityType::RUN !== $session->getActivityType()) {
                continue;
            }

            ++$runningSessionCount;
            $runningDurationInSeconds += max(0, $session->getTargetDurationInSeconds() ?? 0);

            if ($session->isKeySession()) {
                ++$keyRunSessionCount;
            }

            if ($this->isLongRunSession($session)) {
                ++$longRunCount;
            }
        }

        return [
            'runningSessionCount' => $runningSessionCount,
            'keyRunSessionCount' => $keyRunSessionCount,
            'longRunCount' => $longRunCount,
            'runningDurationInSeconds' => $runningDurationInSeconds,
        ];
    }

    private function isLongRunSession(ProposedSession $session): bool
    {
        $title = strtolower(trim((string) $session->getTitle()));
        if (str_contains($title, 'long run')) {
            return true;
        }

        return ($session->getTargetDurationInSeconds() ?? 0) >= 5_400;
    }

    private function resolvePhaseStructureWeight(TrainingBlockPhase $phase): float
    {
        return match ($phase) {
            TrainingBlockPhase::BASE => 0.94,
            TrainingBlockPhase::BUILD => 1.08,
            TrainingBlockPhase::PEAK => 1.02,
            TrainingBlockPhase::TAPER => 0.9,
            TrainingBlockPhase::RECOVERY => 0.76,
        };
    }

    private function resolvePhaseProgressionWeight(TrainingBlockPhase $phase): float
    {
        return match ($phase) {
            TrainingBlockPhase::BASE => 0.96,
            TrainingBlockPhase::BUILD => 1.18,
            TrainingBlockPhase::PEAK => 0.68,
            TrainingBlockPhase::TAPER => 0.28,
            TrainingBlockPhase::RECOVERY => 0.12,
        };
    }

    /**
     * @return list<RaceEventProfile>
     */
    private function resolveBenchmarkProfiles(RaceEventProfile $profile): array
    {
        return match ($profile) {
            RaceEventProfile::RUN_5K => [RaceEventProfile::RUN_5K, RaceEventProfile::RUN_10K, RaceEventProfile::HALF_MARATHON],
            RaceEventProfile::RUN_10K => [RaceEventProfile::RUN_10K, RaceEventProfile::RUN_5K, RaceEventProfile::HALF_MARATHON],
            RaceEventProfile::HALF_MARATHON => [RaceEventProfile::HALF_MARATHON, RaceEventProfile::RUN_10K, RaceEventProfile::RUN_5K],
            RaceEventProfile::MARATHON => [RaceEventProfile::MARATHON, RaceEventProfile::HALF_MARATHON, RaceEventProfile::RUN_10K],
            default => [RaceEventProfile::RUN_5K, RaceEventProfile::RUN_10K, RaceEventProfile::HALF_MARATHON],
        };
    }

    private function estimateFinishTimeInSeconds(int $thresholdPaceInSeconds, RaceEventProfile $profile): int
    {
        $paceFactor = match ($profile) {
            RaceEventProfile::RUN_5K => 0.97,
            RaceEventProfile::RUN_10K => 0.99,
            RaceEventProfile::HALF_MARATHON => 1.04,
            RaceEventProfile::MARATHON => 1.10,
            default => 1.0,
        };
        $paceInSecondsPerKm = (int) round($thresholdPaceInSeconds * $paceFactor);
        $distanceInMeters = $this->getBenchmarkDistanceInMeters($profile);

        return (int) round(($paceInSecondsPerKm / 1000) * $distanceInMeters);
    }

    private function getBenchmarkLabel(RaceEventProfile $profile): string
    {
        return match ($profile) {
            RaceEventProfile::RUN_5K => '5K',
            RaceEventProfile::RUN_10K => '10K',
            RaceEventProfile::HALF_MARATHON => 'Half marathon',
            RaceEventProfile::MARATHON => 'Marathon',
            default => 'Run',
        };
    }

    private function getBenchmarkDistanceInMeters(RaceEventProfile $profile): int
    {
        return match ($profile) {
            RaceEventProfile::RUN_5K => 5_000,
            RaceEventProfile::RUN_10K => 10_000,
            RaceEventProfile::HALF_MARATHON => 21_097,
            RaceEventProfile::MARATHON => 42_195,
            default => 10_000,
        };
    }
}
