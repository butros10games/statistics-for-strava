import {buildAppPath} from './bootstrap';
import {fetchJson} from './http';

export interface BestEffortSportType {
    value: string;
    label: string;
}

export interface BestEffortDistance {
    key: string;
    value: number;
    meterValue: number;
    symbol: string;
    label: string;
}

export interface BestEffortActivity {
    id: string;
    formattedTime: string;
    timeInSeconds: number;
    activityId: string;
    activityName: string;
    activityUrl: string | null;
    startDate: string | null;
}

export interface BestEffortCell {
    sportType: BestEffortSportType;
    effort: BestEffortActivity | null;
}

export interface BestEffortDistanceRow {
    distance: BestEffortDistance;
    efforts: BestEffortCell[];
}

export interface BestEffortPeriodPreview {
    value: string;
    label: string;
    chartOptions: Record<string, unknown>;
    sportTypes: BestEffortSportType[];
    rows: BestEffortDistanceRow[];
}

export interface BestEffortActivityTypePreview {
    value: string;
    label: string;
    periods: BestEffortPeriodPreview[];
}

export interface BestEffortsPreviewResponse {
    requestedAt: string;
    activityTypes: BestEffortActivityTypePreview[];
}

export interface BestEffortHistoryRanking {
    rank: number;
    efforts: BestEffortCell[];
}

export interface BestEffortHistoryResponse {
    requestedAt: string;
    activityType: {
        value: string;
        label: string;
    };
    distance: BestEffortDistance;
    sportTypes: BestEffortSportType[];
    rankings: BestEffortHistoryRanking[];
}

export async function fetchBestEffortsPreview(basePath: string, signal?: AbortSignal): Promise<BestEffortsPreviewResponse> {
    try {
        return await fetchJson<BestEffortsPreviewResponse>(buildAppPath(basePath, 'react-preview/api/best-efforts'), {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
            signal,
        });
    } catch (error) {
        if (error instanceof Error && error.message.startsWith('Request failed with')) {
            throw new Error(`Best efforts preview ${error.message.toLowerCase()}`);
        }

        if (error instanceof Error && error.message === 'Request did not return JSON.') {
            throw new Error('Best efforts preview did not return JSON.');
        }

        throw error;
    }
}

export async function fetchBestEffortHistory(
    basePath: string,
    activityType: string,
    distanceValue: number,
    distanceSymbol: string,
    signal?: AbortSignal,
): Promise<BestEffortHistoryResponse> {
    const query = new URLSearchParams({
        activityType,
        distanceValue: String(distanceValue),
        distanceSymbol,
    });

    try {
        return await fetchJson<BestEffortHistoryResponse>(buildAppPath(basePath, `react-preview/api/best-efforts/history?${query.toString()}`), {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
            signal,
        });
    } catch (error) {
        if (error instanceof Error && error.message.startsWith('Request failed with')) {
            throw new Error(`Best effort history ${error.message.toLowerCase()}`);
        }

        if (error instanceof Error && error.message === 'Request did not return JSON.') {
            throw new Error('Best effort history did not return JSON.');
        }

        throw error;
    }
}