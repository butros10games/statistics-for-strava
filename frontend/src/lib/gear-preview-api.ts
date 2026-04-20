import {buildAppPath} from './bootstrap';
import {fetchJson} from './http';

export interface GearPreviewMoney {
    amountInCents: number;
    currency: string;
}

export interface GearPreviewMeasurement {
    value: number;
    symbol: string;
}

export interface GearPreviewRow {
    id: string;
    name: string;
    imageSrc: string | null;
    isRetired: boolean;
    numberOfActivities: number;
    distance: GearPreviewMeasurement;
    averageDistance: GearPreviewMeasurement;
    elevation: GearPreviewMeasurement;
    movingTime: {
        formatted: string;
        hours: number;
    };
    averageSpeed: GearPreviewMeasurement;
    totalCalories: number;
    purchasePrice: GearPreviewMoney | null;
    relativeCostPerHour: GearPreviewMoney | null;
    relativeCostPerWorkout: GearPreviewMoney | null;
    relativeCostPerDistanceUnit: GearPreviewMoney | null;
}

export interface GearPreviewResponse {
    requestedAt: string;
    customGearEnabled: boolean;
    maintenanceTaskIsDue: boolean;
    unitSystem: {
        value: string;
        label: string;
        distanceSymbol: string;
        elevationSymbol: string;
        speedSymbol: string;
    };
    summary: {
        activeGearCount: number;
        retiredGearCount: number;
        totalActivities: number;
        totalDistance: number;
    };
    activeGear: GearPreviewRow[];
    retiredGear: GearPreviewRow[];
    charts: {
        distancePerMonthPerGear: Record<string, unknown>;
        distanceOverTimePerGear: Record<string, unknown>;
    };
}

export async function fetchGearPreview(basePath: string, signal?: AbortSignal): Promise<GearPreviewResponse> {
    try {
        return await fetchJson<GearPreviewResponse>(buildAppPath(basePath, 'react-preview/api/gear'), {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
            signal,
        });
    } catch (error) {
        if (error instanceof Error && error.message.startsWith('Request failed with')) {
            throw new Error(`Gear preview ${error.message.toLowerCase()}`);
        }

        if (error instanceof Error && error.message === 'Request did not return JSON.') {
            throw new Error('Gear preview did not return JSON.');
        }

        throw error;
    }
}