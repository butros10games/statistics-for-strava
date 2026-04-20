import {buildAppPath} from './bootstrap';
import {fetchJson} from './http';

export interface ActivitiesPreviewFilterOption {
    value: string;
    label: string;
}

export interface ActivitiesPreviewRow {
    active: boolean;
    searchables: string;
    filterables: Record<string, string | number | string[]>;
    summables: Record<string, number>;
    sort: Record<string, string | number>;
    markup: string;
}

export interface ActivitiesPreviewResponse {
    requestedAt: string;
    rows: ActivitiesPreviewRow[];
    filters: {
        sportTypes: ActivitiesPreviewFilterOption[];
        countries: ActivitiesPreviewFilterOption[];
        gears: ActivitiesPreviewFilterOption[];
        devices: ActivitiesPreviewFilterOption[];
        workoutTypes: ActivitiesPreviewFilterOption[];
    };
}

export async function fetchActivitiesPreview(basePath: string, signal?: AbortSignal): Promise<ActivitiesPreviewResponse> {
    try {
        return await fetchJson<ActivitiesPreviewResponse>(buildAppPath(basePath, 'react-preview/api/activities'), {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
            signal,
        });
    } catch (error) {
        if (error instanceof Error && error.message.startsWith('Request failed with')) {
            throw new Error(`Activities preview ${error.message.toLowerCase()}`);
        }

        if (error instanceof Error && error.message === 'Request did not return JSON.') {
            throw new Error('Activities preview did not return JSON.');
        }

        throw error;
    }
}