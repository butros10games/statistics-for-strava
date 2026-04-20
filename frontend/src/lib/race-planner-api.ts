import {buildAppPath} from './bootstrap';
import {fetchJson} from './http';

export interface RacePlannerPreviewRace {
    id: string;
    day: string;
    title: string;
    location: string | null;
    priority: string;
    profile: string;
    type: string;
    family: string;
    targetFinishTimeInSeconds: number | null;
    countdownDays: number | null;
}

export interface RacePlannerPreviewTrainingPlan {
    id: string;
    title: string | null;
    type: 'race' | 'training';
    startDay: string;
    endDay: string;
    targetRaceProfile: string | null;
    discipline: string | null;
    notes: string | null;
    exportPath: string | null;
    legacyPlannerPath: string;
}

export interface RacePlannerPreviewRules {
    minimumPlanWeeks: number;
    idealPlanWeeks: number;
    maximumPlanWeeks: number;
    taperWeeks: number;
    peakWeeks: number;
    postRaceRecoveryWeeks: number;
    sessionsPerWeekMinimum: number;
    sessionsPerWeekIdeal: number;
    sessionsPerWeekMaximum: number;
    hardSessionsPerWeek: number;
    longSessionsPerWeek: number;
    needsBrickSessions: boolean;
    needsSwimSessions: boolean;
    needsBikeSessions: boolean;
    needsRunSessions: boolean;
    disciplines: string[];
}

export interface RacePlannerPreviewWarning {
    type: string;
    title: string;
    body: string;
    severity: 'critical' | 'warning' | 'info';
}

export interface RacePlannerPreviewRecommendedBlock {
    title: string;
    phase: string;
    focus: string | null;
    startDay: string;
    endDay: string;
    durationInWeeks: number;
}

export interface RacePlannerPreviewRecommendation {
    type: string;
    title: string;
    body: string;
    severity: 'critical' | 'warning' | 'info';
    suggestedBlock: RacePlannerPreviewRecommendedBlock | null;
}

export interface RacePlannerPreviewSession {
    day: string;
    dayLabel: string;
    activityType: string;
    activityLabel: string;
    targetIntensity: 'easy' | 'moderate' | 'hard' | 'race';
    targetIntensityLabel: string;
    title: string;
    notes: string | null;
    targetDurationInSeconds: number | null;
    durationLabel: string | null;
    isKeySession: boolean;
    isBrickSession: boolean;
    isDoubleRunSession: boolean;
    isSecondaryRunSession: boolean;
    projectedThresholdPace: string | null;
    usesWeekForecastCopy: boolean;
    workoutPreviewRows: Array<{
        headline: string;
        meta: string | null;
        depth: number;
    }>;
}

export interface RacePlannerPreviewWeek {
    weekNumber: number;
    startDay: string;
    endDay: string;
    sessionCount: number;
    targetLoadPercentage: number;
    isManuallyPlanned: boolean;
    isRecoveryWeek: boolean;
    hasRaceEffortSession: boolean;
    raceSummaryLabel: string | null;
    projectedThresholdPace: string | null;
    doubleRunDayCount: number;
    disciplineDurations: {
        swim: string | null;
        bike: string | null;
        run: string | null;
    };
    sessions: RacePlannerPreviewSession[];
}

export interface RacePlannerPreviewBlock {
    title: string;
    phase: string;
    phaseLabel: string;
    focus: string | null;
    startDay: string;
    endDay: string;
    durationInWeeks: number;
    totalSessions: number;
    weeks: RacePlannerPreviewWeek[];
}

export interface RacePlannerPreviewProposal {
    planStartDay: string;
    planEndDay: string;
    totalWeeks: number;
    totalProposedSessions: number;
    blocks: RacePlannerPreviewBlock[];
}

export interface RacePlannerPreviewExistingBlock {
    id: string;
    title: string | null;
    phase: string;
    phaseLabel: string;
    focus: string | null;
    startDay: string;
    endDay: string;
}

export interface RacePlannerRunningPerformancePrediction {
    confidenceLabel: string;
    currentThresholdPace: string;
    trajectoryThresholdPace: string | null;
    trajectoryGainLabel: string | null;
    trajectoryStatusLabel: string | null;
    projectedThresholdPace: string;
    projectedGainLabel: string;
    benchmarkPredictions: Array<{
        label: string;
        currentFinishTimeInSeconds: number;
        projectedFinishTimeInSeconds: number;
    }>;
    projectedThresholdPacesByWeekStartDate: Record<string, string>;
    basisRows: Array<{
        label: string;
        value: string;
    }>;
    basisNote: string;
}

export interface RacePlannerPreviewResponse {
    requestedAt: string;
    mode: 'global' | 'plan-preview';
    plannerRoute: string;
    legacyPlannerPath: string;
    legacyTrainingPlansPath: string;
    hasUpcomingRaces: boolean;
    plannerSupportsRaceActions: boolean;
    plannerUsesExistingBlocks: boolean;
    hasCustomPlanStartDay: boolean;
    planStartDayInputValue: string | null;
    targetRace: RacePlannerPreviewRace | null;
    countdownDays: number | null;
    linkedTrainingPlan: RacePlannerPreviewTrainingPlan | null;
    linkedTrainingPlanNeedsSync: boolean;
    displayedUpcomingRaces: RacePlannerPreviewRace[];
    rules: RacePlannerPreviewRules | null;
    warnings: RacePlannerPreviewWarning[];
    recommendations: RacePlannerPreviewRecommendation[];
    proposal: RacePlannerPreviewProposal | null;
    existingBlocks: RacePlannerPreviewExistingBlock[];
    runningPerformancePrediction: RacePlannerRunningPerformancePrediction | null;
    recoverySaveSummary: {
        missingRecoveryBlockCount: number;
        missingRecoverySessionCount: number;
        hasAnythingToSave: boolean;
    } | null;
    actions: {
        canEditLinkedTrainingPlan: boolean;
        canRegenerateUpcomingSessions: boolean;
        canSetupPlan: boolean;
        canSaveRecovery: boolean;
        canChangeStartDay: boolean;
    };
}

interface RacePlannerActionResponse {
    ok: boolean;
}

interface RacePlannerActionPayload {
    raceEventId?: string;
    planStartDay?: string;
    resetPlanStartDay?: boolean;
}

export async function fetchRacePlannerPreview(
    basePath: string,
    trainingPlanId?: string,
    signal?: AbortSignal,
): Promise<RacePlannerPreviewResponse> {
    const route = trainingPlanId
        ? buildAppPath(basePath, `react-preview/api/race-planner/plan/${trainingPlanId}`)
        : buildAppPath(basePath, 'react-preview/api/race-planner');

    try {
        return await fetchJson<RacePlannerPreviewResponse>(route, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
            signal,
        });
    } catch (error) {
        if (error instanceof Error && error.message.startsWith('Request failed with')) {
            throw new Error(`Race planner preview ${error.message.toLowerCase()}`);
        }

        if (error instanceof Error && error.message === 'Request did not return JSON.') {
            throw new Error('Race planner preview did not return JSON.');
        }

        throw error;
    }
}

export async function setupRacePlannerPlan(basePath: string, raceEventId: string): Promise<void> {
    await runRacePlannerAction(basePath, 'setup-plan', {raceEventId});
}

export async function regenerateRacePlannerUpcomingSessions(basePath: string, raceEventId: string): Promise<void> {
    await runRacePlannerAction(basePath, 'regenerate-upcoming-sessions', {raceEventId});
}

export async function updateRacePlannerStartDate(basePath: string, payload: RacePlannerActionPayload): Promise<void> {
    await runRacePlannerAction(basePath, 'start-date', payload);
}

export async function saveRacePlannerRecovery(basePath: string, raceEventId: string): Promise<void> {
    await runRacePlannerAction(basePath, 'save-recovery', {raceEventId});
}

async function runRacePlannerAction(basePath: string, action: string, payload: RacePlannerActionPayload): Promise<void> {
    await fetchJson<RacePlannerActionResponse>(buildAppPath(basePath, `react-preview/api/race-planner/${action}`), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(payload),
    });
}
