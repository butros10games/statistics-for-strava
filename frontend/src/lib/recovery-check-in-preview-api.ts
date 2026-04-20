import {buildAppPath} from './bootstrap';
import {fetchJson} from './http';

export interface RecoveryCheckInPreviewRecord {
    day: string;
    fatigue: number;
    soreness: number;
    stress: number;
    motivation: number;
    sleepQuality: number;
    averageScore: number;
    readinessScore: number;
}

export interface RecoveryCheckInPreviewResponse {
    requestedAt: string;
    legacyPath: string;
    savedDay: string | null;
    summary: {
        state: 'updated-today' | 'stale' | 'empty';
        hasTodayCheckIn: boolean;
        latestDay: string | null;
        averageScore: number | null;
        readinessScore: number | null;
    };
    form: {
        day: string;
        defaults: {
            fatigue: number;
            soreness: number;
            stress: number;
            motivation: number;
            sleepQuality: number;
        };
        scale: {
            min: number;
            max: number;
            neutral: number;
        };
    };
    todayCheckIn: RecoveryCheckInPreviewRecord | null;
    latestCheckIn: RecoveryCheckInPreviewRecord | null;
}

export interface RecoveryCheckInSubmitPayload {
    day: string;
    fatigue: number;
    soreness: number;
    stress: number;
    motivation: number;
    sleepQuality: number;
}

export async function fetchRecoveryCheckInPreview(basePath: string, signal?: AbortSignal): Promise<RecoveryCheckInPreviewResponse> {
    return fetchJson<RecoveryCheckInPreviewResponse>(buildAppPath(basePath, 'react-preview/api/recovery-check-in'), {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
        },
        signal,
    });
}

export async function saveRecoveryCheckInPreview(basePath: string, payload: RecoveryCheckInSubmitPayload): Promise<RecoveryCheckInPreviewResponse> {
    return fetchJson<RecoveryCheckInPreviewResponse>(buildAppPath(basePath, 'react-preview/api/recovery-check-in'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(payload),
    });
}
