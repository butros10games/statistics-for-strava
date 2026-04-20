import {buildAppPath} from './bootstrap';
import {fetchJson} from './http';
import type {TrainingPlansPreviewRace, TrainingPlansPreviewResponse} from './training-plans-api';

export interface TrainingPlanFormBootstrapResponse {
    mode: 'create' | 'edit';
    context: {
        trainingPlan: {
            id: string;
            title: string | null;
            type: string;
            startDay: string;
            endDay: string;
        } | null;
        afterTrainingPlan: {
            id: string;
            title: string | null;
            type: string;
            endDay: string;
        } | null;
        suggestedRaceEvent: TrainingPlansPreviewRace | null;
    };
    defaults: {
        type: 'race' | 'training';
        title: string | null;
        startDay: string;
        endDay: string;
        targetRaceEventId: string | null;
        discipline: string | null;
        sportSchedule: Partial<Record<'swimDays' | 'bikeDays' | 'runDays' | 'longRideDays' | 'longRunDays', number[]>>;
        performanceMetrics: Partial<Record<'cyclingFtp' | 'runningThresholdPace' | 'swimmingCss' | 'weeklyRunningVolume' | 'weeklyBikingVolume', number>>;
        targetRaceProfile: string | null;
        trainingFocus: string | null;
        trainingBlockStyle: string;
        runningWorkoutTargetMode: string;
        runHillSessionsEnabled: boolean;
        notes: string | null;
    };
    options: {
        types: Array<{value: 'race' | 'training'}>;
        disciplines: Array<{value: string}>;
        raceEvents: TrainingPlansPreviewRace[];
        raceProfileGroups: Array<{
            family: string;
            options: Array<{
                value: string;
                disciplineValues: string[];
            }>;
        }>;
        trainingFocuses: Array<{value: string}>;
        trainingBlockStyles: Array<{value: string}>;
        runningWorkoutTargetModes: Array<{value: string}>;
    };
}

export interface TrainingPlanFormSubmitPayload {
    trainingPlanId?: string;
    type: 'race' | 'training';
    title: string;
    startDay: string;
    endDay: string;
    targetRaceEventId?: string;
    discipline?: string;
    swimDays?: number[];
    bikeDays?: number[];
    runDays?: number[];
    longRideDays?: number[];
    longRunDays?: number[];
    cyclingFtp?: number;
    runningThresholdPace?: number;
    swimmingCss?: number;
    weeklyRunningVolume?: number;
    weeklyBikingVolume?: number;
    targetRaceProfile?: string;
    trainingFocus?: string;
    trainingBlockStyle?: string;
    runningWorkoutTargetMode?: string;
    runHillSessionsEnabled?: boolean;
    notes?: string;
}

interface FetchTrainingPlanFormOptions {
    trainingPlanId?: string;
    afterTrainingPlanId?: string;
    targetRaceEventId?: string;
    signal?: AbortSignal;
}

export async function fetchTrainingPlanFormPreview(basePath: string, options: FetchTrainingPlanFormOptions = {}): Promise<TrainingPlanFormBootstrapResponse> {
    const searchParams = new URLSearchParams();

    if (options.trainingPlanId) {
        searchParams.set('trainingPlanId', options.trainingPlanId);
    }

    if (options.afterTrainingPlanId) {
        searchParams.set('afterTrainingPlanId', options.afterTrainingPlanId);
    }

    if (options.targetRaceEventId) {
        searchParams.set('targetRaceEventId', options.targetRaceEventId);
    }

    const path = buildAppPath(basePath, `react-preview/api/training-plan-form${searchParams.size > 0 ? `?${searchParams.toString()}` : ''}`);

    return fetchJson<TrainingPlanFormBootstrapResponse>(path, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
        },
        signal: options.signal,
    });
}

export async function createTrainingPlanPreview(basePath: string, payload: TrainingPlanFormSubmitPayload): Promise<TrainingPlansPreviewResponse> {
    return fetchJson<TrainingPlansPreviewResponse>(buildAppPath(basePath, 'react-preview/api/training-plans'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(payload),
    });
}

export async function deleteTrainingPlanPreview(basePath: string, trainingPlanId: string): Promise<TrainingPlansPreviewResponse> {
    return fetchJson<TrainingPlansPreviewResponse>(buildAppPath(basePath, `react-preview/api/training-plans/${trainingPlanId}`), {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
        },
    });
}
