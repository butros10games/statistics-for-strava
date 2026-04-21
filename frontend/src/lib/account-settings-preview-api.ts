import {buildAppPath} from './bootstrap';
import {fetchJson} from './http';

export interface AccountSettingsPreviewResponse {
    requestedAt: string;
    legacyPath: string;
    disconnectedStrava: boolean;
    summary: {
        connectedServices: number;
        manualSyncProviders: number;
        garminLastImportedDay: string | null;
    };
    account: {
        email: string;
        emailVerified: boolean;
        emailVerificationStatusLabel: string;
        verifyEmailPath: string | null;
    };
    strava: {
        connected: boolean;
        statusLabel: string;
        athleteId: string | null;
        scopes: string[];
        scopeLabel: string | null;
        canSync: boolean;
        tokenRefreshedAt: string | null;
    };
    garmin: {
        enabled: boolean;
        configured: boolean;
        canSync: boolean;
        connectionMode: string | null;
        connectionModeLabel: string;
        bridgeSourcePath: string;
        lastImportedDay: string | null;
    };
    actions: {
        backToAppPath: string;
        logoutPath: string;
        connectStravaPath: string;
        disconnectStravaPath: string;
        syncStravaPath: string;
        syncGarminPath: string;
    };
}

export interface ManualSyncResult {
    message: string;
    output: string;
    durationInSeconds?: number;
    lastImportedDay?: string;
}

export async function fetchAccountSettingsPreview(basePath: string, signal?: AbortSignal): Promise<AccountSettingsPreviewResponse> {
    return fetchJson<AccountSettingsPreviewResponse>(buildAppPath(basePath, 'react-preview/api/account-settings'), {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
        },
        signal,
    });
}

export async function disconnectStravaPreview(basePath: string): Promise<AccountSettingsPreviewResponse> {
    return fetchJson<AccountSettingsPreviewResponse>(buildAppPath(basePath, 'react-preview/api/account-settings/strava-disconnect'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
        },
    });
}

export async function runManualSyncAction(basePath: string, actionPath: string): Promise<ManualSyncResult> {
    const response = await fetch(buildAppPath(basePath, actionPath), {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    const payload = await response.json().catch(() => ({
        message: 'The sync finished, but the server returned an unexpected response.',
        output: '',
    })) as ManualSyncResult;

    if (!response.ok) {
        const error = new Error(payload.message || `Request failed with ${response.status}`) as Error & {output?: string};
        error.output = payload.output || '';
        throw error;
    }

    return payload;
}
