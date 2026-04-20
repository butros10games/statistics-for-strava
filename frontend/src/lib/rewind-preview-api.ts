import {buildAppPath} from './bootstrap';
import {fetchJson} from './http';

export interface RewindPreviewMetric {
    value: number;
    label: string;
}

export interface RewindPreviewOption {
    value: string;
    label: string;
    isAllTime: boolean;
}

export interface RewindPreviewMapBoundsPoint {
    lat: number;
    lng: number;
}

export interface RewindPreviewMap {
    polylineUrl: string;
    tileLayer: string | null;
    overlayImageUrl: string | null;
    bounds: RewindPreviewMapBoundsPoint[];
    minZoom: number;
    maxZoom: number;
    backgroundColor: string;
    label: string;
}

export interface RewindPreviewActivity {
    id: string;
    name: string;
    activityUrl: string;
    externalUrl: string;
    distanceLabel: string;
    elevationLabel: string;
    movingTimeLabel: string;
    map: RewindPreviewMap | null;
}

export interface RewindPreviewPhoto {
    imageUrl: string;
    placeholderUrl: string;
    orientation: 'PORTRAIT' | 'LANDSCAPE';
    activityName: string;
    activityDateLabel: string;
    activityUrl: string;
}

export interface RewindPreviewBaseItem {
    id: string;
    kind: 'chart' | 'hero-activity' | 'socials' | 'streaks' | 'carbon-saved' | 'photo';
    icon: string;
    title: string;
    subTitle: string | null;
    totalMetric: RewindPreviewMetric | null;
}

export interface RewindPreviewChartItem extends RewindPreviewBaseItem {
    kind: 'chart';
    chartOptions: Record<string, unknown>;
}

export interface RewindPreviewHeroActivityItem extends RewindPreviewBaseItem {
    kind: 'hero-activity';
    activity: RewindPreviewActivity;
}

export interface RewindPreviewSocialsItem extends RewindPreviewBaseItem {
    kind: 'socials';
    socials: {
        kudoCount: number;
        commentCount: number;
    };
}

export interface RewindPreviewStreaksItem extends RewindPreviewBaseItem {
    kind: 'streaks';
    streaks: {
        dayStreak: number;
        weekStreak: number;
        monthStreak: number;
    };
}

export interface RewindPreviewCarbonSavedItem extends RewindPreviewBaseItem {
    kind: 'carbon-saved';
    carbonSaved: {
        kilograms: number;
        petBottlesProduced: number;
        googleSearches: number;
    };
}

export interface RewindPreviewPhotoItem extends RewindPreviewBaseItem {
    kind: 'photo';
    photo: RewindPreviewPhoto;
}

export type RewindPreviewItem =
    | RewindPreviewChartItem
    | RewindPreviewHeroActivityItem
    | RewindPreviewSocialsItem
    | RewindPreviewStreaksItem
    | RewindPreviewCarbonSavedItem
    | RewindPreviewPhotoItem;

export interface RewindPreviewResponse {
    requestedAt: string;
    summary: {
        optionCount: number;
        yearOptionCount: number;
        comparisonAvailable: boolean;
    };
    options: RewindPreviewOption[];
    selectedOption: {
        value: string;
        label: string;
        isAllTime: boolean;
        totalActivities: number;
        cardsCount: number;
        chartCardsCount: number;
        hasWorldMap: boolean;
        hasPhoto: boolean;
    };
    items: RewindPreviewItem[];
}

export async function fetchRewindPreview(basePath: string, option?: string | null, signal?: AbortSignal): Promise<RewindPreviewResponse> {
    const url = new URL(buildAppPath(basePath, 'react-preview/api/rewind'), window.location.origin);

    if (option) {
        url.searchParams.set('option', option);
    }

    try {
        return await fetchJson<RewindPreviewResponse>(url, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
            signal,
        });
    } catch (error) {
        if (error instanceof Error && error.message.startsWith('Request failed with')) {
            throw new Error(`Rewind preview ${error.message.toLowerCase()}`);
        }

        if (error instanceof Error && error.message === 'Request did not return JSON.') {
            throw new Error('Rewind preview did not return JSON.');
        }

        throw error;
    }
}