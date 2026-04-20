import {buildAppPath} from './bootstrap';
import {fetchJson} from './http';

export interface RaceEventsPreviewTrainingPlanReference {
    id: string;
    title: string;
    type: 'race' | 'training';
    racePlannerPath: string;
}

export interface RaceEventsPreviewRace {
    id: string;
    day: string;
    title: string;
    rawTitle: string | null;
    location: string | null;
    notes: string | null;
    priority: string;
    priorityLabel: string;
    family: string;
    familyLabel: string;
    profile: string;
    profileLabel: string;
    type: string;
    targetFinishTimeInSeconds: number | null;
    targetFinishTimeHours: number | null;
    targetFinishTimeMinutes: number | null;
    targetFinishTimeLabel: string | null;
    countdownDays: number | null;
    coverage: {
        state: 'linked' | 'covered' | 'unplanned';
        linkedTrainingPlan: RaceEventsPreviewTrainingPlanReference | null;
    };
    legacyModalPath: string;
}

export interface RaceEventsPreviewResponse {
    requestedAt: string;
    savedRaceEventId: string | null;
    deletedRaceEventId: string | null;
    initialSelectionId: string | null;
    legacyCreatePath: string;
    summary: {
        totalRaces: number;
        upcomingRaces: number;
        aRaces: number;
        coveredRaces: number;
        directlyLinkedRaces: number;
        unplannedRaces: number;
    };
    formDefaults: {
        day: string;
        family: string;
        profile: string;
        priority: string;
        title: string;
        location: string;
        notes: string;
        targetFinishTimeHours: string;
        targetFinishTimeMinutes: string;
    };
    options: {
        families: Array<{value: string; label: string}>;
        priorities: Array<{value: string; label: string}>;
        profileGroups: Array<{
            family: string;
            familyLabel: string;
            options: Array<{value: string; label: string; family: string}>;
        }>;
    };
    races: RaceEventsPreviewRace[];
}

export interface RaceEventPreviewSubmitPayload {
    raceEventId?: string;
    day: string;
    family: string;
    profile: string;
    priority: string;
    title: string;
    location: string;
    notes: string;
    targetFinishTimeHours: string;
    targetFinishTimeMinutes: string;
}

export async function fetchRaceEventsPreview(basePath: string, signal?: AbortSignal): Promise<RaceEventsPreviewResponse> {
    return fetchJson<RaceEventsPreviewResponse>(buildAppPath(basePath, 'react-preview/api/race-events'), {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
        },
        signal,
    });
}

export async function saveRaceEventPreview(basePath: string, payload: RaceEventPreviewSubmitPayload): Promise<RaceEventsPreviewResponse> {
    return fetchJson<RaceEventsPreviewResponse>(buildAppPath(basePath, 'react-preview/api/race-events'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(payload),
    });
}

export async function deleteRaceEventPreview(basePath: string, raceEventId: string): Promise<RaceEventsPreviewResponse> {
    return fetchJson<RaceEventsPreviewResponse>(buildAppPath(basePath, `react-preview/api/race-events/${raceEventId}`), {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
        },
    });
}
