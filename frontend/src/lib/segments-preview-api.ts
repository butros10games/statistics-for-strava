import {buildAppPath} from './bootstrap';
import {fetchJson} from './http';

interface SegmentMeasurement {
    value: number;
    symbol: string;
}

interface SegmentSportType {
    value: string;
    label: string;
}

interface SegmentBestEffort {
    elapsedTimeFormatted: string;
    averageSpeed: SegmentMeasurement;
    averageWatts: number | null;
}

export interface SegmentPreviewRow {
    id: string;
    name: string;
    displayName: string;
    url: string;
    sportType: SegmentSportType;
    countryCode: string | null;
    countryName: string | null;
    distance: SegmentMeasurement;
    averageGradient: number | null;
    maxGradient: number;
    numberOfTimesRidden: number;
    lastEffortDate: string | null;
    isFavourite: boolean;
    isKom: boolean;
    bestEffort: SegmentBestEffort | null;
}

export interface SegmentPreviewFilters {
    sportTypes: Array<{value: string; label: string}>;
    countries: Array<{value: string; label: string}>;
}

export interface SegmentsPreviewResponse {
    requestedAt: string;
    summary: {
        totalSegments: number;
        favouriteSegments: number;
        komSegments: number;
        countriesCount: number;
    };
    filters: SegmentPreviewFilters;
    segments: SegmentPreviewRow[];
}

export interface SegmentEffortRow {
    id: string;
    ranking: number;
    activityId: string;
    activityName: string;
    activityUrl: string | null;
    startDate: string;
    elapsedTimeFormatted: string;
    averageSpeed: SegmentMeasurement;
    averageHeartRate: number | null;
    averageWatts: number | null;
    gearName: string | null;
}

export interface SegmentDetailResponse {
    requestedAt: string;
    segment: SegmentPreviewRow;
    effortCount: number;
    topEfforts: SegmentEffortRow[];
    charts: {
        history: Record<string, unknown>;
        effortVsHeartRate: Record<string, unknown> | null;
    };
}

export async function fetchSegmentsPreview(basePath: string, signal?: AbortSignal): Promise<SegmentsPreviewResponse> {
    try {
        return await fetchJson<SegmentsPreviewResponse>(buildAppPath(basePath, 'react-preview/api/segments'), {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
            signal,
        });
    } catch (error) {
        if (error instanceof Error && error.message.startsWith('Request failed with')) {
            throw new Error(`Segments preview ${error.message.toLowerCase()}`);
        }

        if (error instanceof Error && error.message === 'Request did not return JSON.') {
            throw new Error('Segments preview did not return JSON.');
        }

        throw error;
    }
}

export async function fetchSegmentDetail(basePath: string, segmentId: string, signal?: AbortSignal): Promise<SegmentDetailResponse> {
    try {
        return await fetchJson<SegmentDetailResponse>(buildAppPath(basePath, `react-preview/api/segments/${segmentId}`), {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
            signal,
        });
    } catch (error) {
        if (error instanceof Error && error.message.startsWith('Request failed with')) {
            throw new Error(`Segment detail ${error.message.toLowerCase()}`);
        }

        if (error instanceof Error && error.message === 'Request did not return JSON.') {
            throw new Error('Segment detail did not return JSON.');
        }

        throw error;
    }
}