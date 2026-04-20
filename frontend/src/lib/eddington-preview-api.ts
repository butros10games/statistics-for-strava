import {buildAppPath} from './bootstrap';
import {fetchJson} from './http';

export type EddingtonUnitSystemValue = 'metric' | 'imperial';

export interface EddingtonPreviewItem {
    id: string;
    label: string;
    number: number;
    longestDistanceInADay: number;
    nextNumber: number;
    daysToNextNumber: number | null;
    historyLength: number;
    chartOptions: Record<string, unknown>;
    historyChartOptions: Record<string, unknown>;
}

export interface EddingtonPreviewUnitSystem {
    value: EddingtonUnitSystemValue;
    label: string;
    distanceSymbol: string;
    eddingtons: EddingtonPreviewItem[];
}

export interface EddingtonPreviewResponse {
    requestedAt: string;
    activeUnitSystem: EddingtonUnitSystemValue;
    unitSystems: EddingtonPreviewUnitSystem[];
}

export async function fetchEddingtonPreview(basePath: string, signal?: AbortSignal): Promise<EddingtonPreviewResponse> {
    try {
        return await fetchJson<EddingtonPreviewResponse>(buildAppPath(basePath, 'react-preview/api/eddington'), {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
            signal,
        });
    } catch (error) {
        if (error instanceof Error && error.message.startsWith('Request failed with')) {
            throw new Error(`Eddington preview ${error.message.toLowerCase()}`);
        }

        if (error instanceof Error && error.message === 'Request did not return JSON.') {
            throw new Error('Eddington preview did not return JSON.');
        }

        throw error;
    }
}