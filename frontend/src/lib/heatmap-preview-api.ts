import {buildAppPath} from './bootstrap';
import {fetchJson} from './http';

export interface HeatmapPreviewFilterOption {
    value: string;
    label: string;
}

export interface HeatmapPreviewConfig {
    polylineColor: string;
    tileLayerUrls: string[];
    enableGreyScale: boolean;
}

export interface HeatmapPreviewRoute {
    id: string;
    activityId: string;
    activityUrl: string | null;
    startDate: string;
    distance: string;
    name: string;
    sportType: HeatmapPreviewFilterOption;
    workoutType: HeatmapPreviewFilterOption | null;
    startLocation: {
        countryCode: string | null;
        countryName: string | null;
        state: string | null;
    };
    filterables: {
        sportType: string;
        'start-date': number;
        isCommute: 'true' | 'false';
        workoutType: string | null;
    };
    coordinates: Array<[number, number]>;
}

export interface HeatmapPreviewResponse {
    requestedAt: string;
    summary: {
        totalRoutes: number;
        commuteRoutes: number;
        countriesCount: number;
        workoutRoutes: number;
    };
    config: HeatmapPreviewConfig;
    filters: {
        sportTypes: HeatmapPreviewFilterOption[];
        workoutTypes: HeatmapPreviewFilterOption[];
    };
    places: Array<{
        countryCode: string;
        label: string;
        routeCount: number;
    }>;
    routes: HeatmapPreviewRoute[];
}

export async function fetchHeatmapPreview(basePath: string, signal?: AbortSignal): Promise<HeatmapPreviewResponse> {
    try {
        return await fetchJson<HeatmapPreviewResponse>(buildAppPath(basePath, 'react-preview/api/heatmap'), {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
            signal,
        });
    } catch (error) {
        if (error instanceof Error && error.message.startsWith('Request failed with')) {
            throw new Error(`Heatmap preview ${error.message.toLowerCase()}`);
        }

        if (error instanceof Error && error.message === 'Request did not return JSON.') {
            throw new Error('Heatmap preview did not return JSON.');
        }

        throw error;
    }
}