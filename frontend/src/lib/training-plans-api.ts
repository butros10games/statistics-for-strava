import {buildAppPath} from './bootstrap';

export interface TrainingPlansPreviewRace {
    id: string;
    day: string;
    title: string;
    priority: string;
    profile: string;
    type: string;
    family: string;
}

export interface TrainingPlansPreviewPlan {
    id: string;
    title: string;
    status: 'current' | 'upcoming' | 'completed';
    type: 'race' | 'training';
    startDay: string;
    endDay: string;
    durationDays: number;
    durationWeeks: number;
    notes: string | null;
    racePlannerPath: string;
    visibility: string;
    linkedRace: TrainingPlansPreviewRace | null;
    linkedRaceState: 'none' | 'missing' | 'outside-window' | 'linked';
    windowRaces: TrainingPlansPreviewRace[];
    continuity: {
        kind: 'gap' | 'overlap' | 'handoff';
        days: number;
        nextPlanId: string;
        nextPlanTitle: string;
    } | null;
    discipline: string | null;
    objective: string | null;
    scheduleHighlights: string[];
    performanceHighlights: string[];
}

export interface TrainingPlansPreviewResponse {
    requestedAt: string;
    activePlanId: string | null;
    stats: {
        totalPlans: number;
        racePlans: number;
        trainingPlans: number;
        gapCount: number;
        overlapCount: number;
        handoffCount: number;
        unassignedUpcomingRaces: number;
        nextSuggestedStartDay: string;
    };
    plans: TrainingPlansPreviewPlan[];
    unassignedUpcomingRaces: TrainingPlansPreviewRace[];
}

export async function fetchTrainingPlansPreview(basePath: string, signal?: AbortSignal): Promise<TrainingPlansPreviewResponse> {
    const response = await fetch(buildAppPath(basePath, 'react-preview/api/training-plans'), {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
        },
        signal,
    });

    const contentType = response.headers.get('content-type') || '';

    if (!response.ok) {
        throw new Error(`Training plans preview request failed with ${response.status}`);
    }

    if (!contentType.includes('application/json')) {
        throw new Error('Training plans preview did not return JSON.');
    }

    return (await response.json()) as TrainingPlansPreviewResponse;
}