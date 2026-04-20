import {buildAppPath} from './bootstrap';
import {fetchJson} from './http';

export interface MilestonePreviewFilterOption {
    value: string;
    label: string;
    count: number;
}

export interface MilestonePreviewGroupFilter extends MilestonePreviewFilterOption {
    icon: string;
}

export interface MilestonePreviewMilestone {
    id: string;
    achievedOn: string;
    year: number;
    category: string;
    title: string;
    details: string[];
    filterGroup: {
        value: string;
        label: string;
        icon: string;
    };
    sportType: {
        value: string;
        label: string;
    } | null;
    country: {
        code: string;
        label: string;
    } | null;
    activity: {
        id: string | null;
        name: string;
        url: string | null;
        achievedOn: string | null;
    } | null;
    previous: {
        id: string;
        threshold: string;
        achievedOn: string;
    } | null;
}

export interface MilestonesPreviewResponse {
    requestedAt: string;
    summary: {
        totalMilestones: number;
        groupsCount: number;
        yearsCount: number;
        linkedActivitiesCount: number;
        achievedThisYear: number;
    };
    filters: {
        groups: MilestonePreviewGroupFilter[];
        sportTypes: MilestonePreviewFilterOption[];
        years: Array<{
            value: number;
            label: string;
            count: number;
        }>;
    };
    milestones: MilestonePreviewMilestone[];
}

export async function fetchMilestonesPreview(basePath: string, signal?: AbortSignal): Promise<MilestonesPreviewResponse> {
    try {
        return await fetchJson<MilestonesPreviewResponse>(buildAppPath(basePath, 'react-preview/api/milestones'), {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
            signal,
        });
    } catch (error) {
        if (error instanceof Error && error.message.startsWith('Request failed with')) {
            throw new Error(`Milestones preview ${error.message.toLowerCase()}`);
        }

        if (error instanceof Error && error.message === 'Request did not return JSON.') {
            throw new Error('Milestones preview did not return JSON.');
        }

        throw error;
    }
}