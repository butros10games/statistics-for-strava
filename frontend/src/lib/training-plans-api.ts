import {buildAppPath} from './bootstrap';
import {fetchJson} from './http';

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
    try {
        return await fetchJson<TrainingPlansPreviewResponse>(buildAppPath(basePath, 'react-preview/api/training-plans'), {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
            signal,
        });
    } catch (error) {
        if (error instanceof Error && error.message.startsWith('Request failed with')) {
            throw new Error(`Training plans preview ${error.message.toLowerCase()}`);
        }

        if (error instanceof Error && error.message === 'Request did not return JSON.') {
            throw new Error('Training plans preview did not return JSON.');
        }

        throw error;
    }
}