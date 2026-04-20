import {buildAppPath} from './bootstrap';
import {fetchJson} from './http';

export interface TrainingBlocksPreviewRaceEventOption {
    id: string;
    title: string;
    day: string;
    profile: string;
    profileLabel: string;
    priority: string;
    priorityLabel: string;
    location: string | null;
    countdownDays: number | null;
}

export interface TrainingBlocksPreviewBlock {
    id: string;
    title: string;
    rawTitle: string | null;
    startDay: string;
    endDay: string;
    durationInDays: number;
    phase: string;
    phaseLabel: string;
    focus: string | null;
    notes: string | null;
    state: 'current' | 'upcoming' | 'completed';
    linkedRace: TrainingBlocksPreviewRaceEventOption | null;
    legacyModalPath: string;
}

export interface TrainingBlocksPreviewResponse {
    requestedAt: string;
    savedTrainingBlockId: string | null;
    deletedTrainingBlockId: string | null;
    initialSelectionId: string | null;
    legacyCreatePath: string;
    summary: {
        totalBlocks: number;
        currentBlocks: number;
        upcomingBlocks: number;
        completedBlocks: number;
        linkedRaceBlocks: number;
        totalPlannedDays: number;
    };
    formDefaults: {
        startDay: string;
        endDay: string;
        phase: string;
        title: string;
        focus: string;
        notes: string;
        targetRaceEventId: string;
    };
    options: {
        phases: Array<{value: string; label: string}>;
        raceEvents: TrainingBlocksPreviewRaceEventOption[];
    };
    blocks: TrainingBlocksPreviewBlock[];
}

export interface TrainingBlockPreviewSubmitPayload {
    trainingBlockId?: string;
    startDay: string;
    endDay: string;
    phase: string;
    title: string;
    focus: string;
    notes: string;
    targetRaceEventId: string;
}

export async function fetchTrainingBlocksPreview(basePath: string, signal?: AbortSignal): Promise<TrainingBlocksPreviewResponse> {
    return fetchJson<TrainingBlocksPreviewResponse>(buildAppPath(basePath, 'react-preview/api/training-blocks'), {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
        },
        signal,
    });
}

export async function saveTrainingBlockPreview(basePath: string, payload: TrainingBlockPreviewSubmitPayload): Promise<TrainingBlocksPreviewResponse> {
    return fetchJson<TrainingBlocksPreviewResponse>(buildAppPath(basePath, 'react-preview/api/training-blocks'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(payload),
    });
}

export async function deleteTrainingBlockPreview(basePath: string, trainingBlockId: string): Promise<TrainingBlocksPreviewResponse> {
    return fetchJson<TrainingBlocksPreviewResponse>(buildAppPath(basePath, `react-preview/api/training-blocks/${trainingBlockId}`), {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
        },
    });
}
