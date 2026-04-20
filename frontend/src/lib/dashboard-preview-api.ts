import {buildAppPath} from './bootstrap';
import {fetchJson} from './http';

export interface DashboardPreviewWidget {
    id: string;
    width: number;
    html: string;
}

export interface DashboardPreviewSection {
    id: string;
    label: string;
    widgets: DashboardPreviewWidget[];
}

export interface DashboardPreviewResponse {
    requestedAt: string;
    summary: {
        totalWidgets: number;
        sectionCount: number;
    };
    sections: DashboardPreviewSection[];
}

export async function fetchDashboardPreview(basePath: string, signal?: AbortSignal): Promise<DashboardPreviewResponse> {
    try {
        return await fetchJson<DashboardPreviewResponse>(buildAppPath(basePath, 'react-preview/api/dashboard'), {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
            signal,
        });
    } catch (error) {
        if (error instanceof Error && error.message.startsWith('Request failed with')) {
            throw new Error(`Dashboard preview ${error.message.toLowerCase()}`);
        }

        if (error instanceof Error && error.message === 'Request did not return JSON.') {
            throw new Error('Dashboard preview did not return JSON.');
        }

        throw error;
    }
}
