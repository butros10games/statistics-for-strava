import {buildAppPath} from './bootstrap';
import {fetchJson} from './http';

export interface BadgePreviewItem {
    id: string;
    name: string;
    category: string;
    imageUrl: string;
    absoluteUrl: string;
    embedCode: string;
}

export interface BadgePreviewSection {
    id: string;
    label: string;
    description: string;
    badgesCount: number;
    badges: BadgePreviewItem[];
}

export interface BadgesPreviewResponse {
    requestedAt: string;
    summary: {
        totalBadges: number;
        categoryCount: number;
        personalBestBadgeCount: number;
        hasZwiftBadge: boolean;
    };
    sections: BadgePreviewSection[];
}

export async function fetchBadgesPreview(basePath: string, signal?: AbortSignal): Promise<BadgesPreviewResponse> {
    try {
        return await fetchJson<BadgesPreviewResponse>(buildAppPath(basePath, 'react-preview/api/badges'), {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
            signal,
        });
    } catch (error) {
        if (error instanceof Error && error.message.startsWith('Request failed with')) {
            throw new Error(`Badges preview ${error.message.toLowerCase()}`);
        }

        if (error instanceof Error && error.message === 'Request did not return JSON.') {
            throw new Error('Badges preview did not return JSON.');
        }

        throw error;
    }
}