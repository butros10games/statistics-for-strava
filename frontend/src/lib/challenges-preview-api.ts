import {buildAppPath} from './bootstrap';
import {fetchJson} from './http';

export interface ChallengePreviewGroup {
    monthId: string;
    monthLabel: string;
    year: number;
    count: number;
    challenges: ChallengePreviewChallenge[];
}

export interface ChallengePreviewChallenge {
    id: string;
    name: string;
    logoUrl: string | null;
    externalUrl: string;
    completedDate: string;
    hasLocalLogo: boolean;
}

export interface ChallengesPreviewResponse {
    requestedAt: string;
    summary: {
        totalChallenges: number;
        monthsCount: number;
        localLogoCount: number;
        remoteLogoCount: number;
    };
    filters: {
        years: Array<{
            value: string;
            label: string;
            count: number;
        }>;
    };
    groups: ChallengePreviewGroup[];
}

export async function fetchChallengesPreview(basePath: string, signal?: AbortSignal): Promise<ChallengesPreviewResponse> {
    try {
        return await fetchJson<ChallengesPreviewResponse>(buildAppPath(basePath, 'react-preview/api/challenges'), {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
            signal,
        });
    } catch (error) {
        if (error instanceof Error && error.message.startsWith('Request failed with')) {
            throw new Error(`Challenges preview ${error.message.toLowerCase()}`);
        }

        if (error instanceof Error && error.message === 'Request did not return JSON.') {
            throw new Error('Challenges preview did not return JSON.');
        }

        throw error;
    }
}