import {buildAppPath} from './bootstrap';
import {fetchJson} from './http';

interface PhotoPreviewFilterOption {
    value: string;
    label: string;
}

interface PhotoPreviewSportType {
    value: string;
    label: string;
}

interface PhotoPreviewCountry {
    value: string;
    label: string;
}

export interface PhotoPreviewImage {
    id: string;
    imageUrl: string;
    activityId: string;
    activityName: string;
    activityDate: string | null;
    activityUrl: string | null;
    sportType: PhotoPreviewSportType;
    orientation: 'LANDSCAPE' | 'PORTRAIT';
    countries: PhotoPreviewCountry[];
}

export interface PhotosPreviewResponse {
    requestedAt: string;
    summary: {
        totalImages: number;
        portraitImages: number;
        landscapeImages: number;
        countriesCount: number;
    };
    filters: {
        sportTypes: PhotoPreviewFilterOption[];
        countries: PhotoPreviewFilterOption[];
    };
    defaultEnabledFilters: {
        sportTypes: string[];
        countryCode: string | null;
    };
    images: PhotoPreviewImage[];
}

export async function fetchPhotosPreview(basePath: string, signal?: AbortSignal): Promise<PhotosPreviewResponse> {
    try {
        return await fetchJson<PhotosPreviewResponse>(buildAppPath(basePath, 'react-preview/api/photos'), {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
            signal,
        });
    } catch (error) {
        if (error instanceof Error && error.message.startsWith('Request failed with')) {
            throw new Error(`Photos preview ${error.message.toLowerCase()}`);
        }

        if (error instanceof Error && error.message === 'Request did not return JSON.') {
            throw new Error('Photos preview did not return JSON.');
        }

        throw error;
    }
}