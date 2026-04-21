import {buildAppPath} from './bootstrap';
import {fetchJson} from './http';

export interface PlannedSessionFormWorkoutStep {
    itemId: string;
    parentBlockId: string | null;
    type: string;
    label: string;
    repetitions: string;
    targetType: string;
    conditionType: string;
    durationInMinutes: string;
    durationInSecondsPart: string;
    distanceInMeters: string;
    targetPace: string;
    targetPower: string;
    targetHeartRate: string;
}

export interface PlannedSessionTemplateActivity {
    activityId: string;
    activityType: string;
    activityTypeLabel: string;
    name: string;
    day: string;
    movingTimeLabel: string;
    movingTimeInSeconds: number;
    estimatedLoad: number | null;
}

export interface PlannedSessionRecommendation {
    trainingSessionId: string;
    activityType: string;
    activityTypeLabel: string;
    title: string | null;
    notes: string | null;
    targetLoad: number | null;
    targetDurationInMinutes: number | null;
    targetDurationInSecondsPart: number | null;
    targetDurationLabel: string | null;
    targetIntensity: string | null;
    targetIntensityLabel: string | null;
    templateActivityId: string | null;
    estimationSource: string;
    estimationSourceLabel: string;
    manualTargetLoadOverride: boolean;
    lastPlannedOn: string | null;
    lastPlannedOnLabel: string | null;
    workoutSteps: PlannedSessionFormWorkoutStep[];
}

export interface PlannedSessionEditorBootstrapResponse {
    requestedAt: string;
    mode: 'create' | 'edit';
    legacyPath: string;
    context: {
        plannedSession: {
            id: string;
            day: string;
            activityType: string;
            activityTypeLabel: string;
            title: string | null;
            linkStatus: string;
            linkStatusLabel: string;
        } | null;
        latestPlannedSession: {
            id: string;
            day: string;
            activityType: string;
            activityTypeLabel: string;
            title: string | null;
            linkStatus: string;
            linkStatusLabel: string;
        } | null;
        matchedActivity: {
            activityId: string;
            name: string;
            activityType: string;
            activityTypeLabel: string;
            movingTime: number;
        } | null;
        matchStatus: string | null;
    };
    defaults: {
        day: string;
        title: string;
        activityType: string;
        notes: string;
        targetLoad: number | null;
        manualTargetLoadOverride: boolean;
        targetDurationInMinutes: number | null;
        targetDurationInSecondsPart: number | null;
        targetIntensity: string | null;
        templateActivityId: string | null;
        workoutSteps: PlannedSessionFormWorkoutStep[];
    };
    estimatedLoad: number | null;
    estimatedSourceLabel: string | null;
    options: {
        activityTypes: Array<{
            value: string;
            label: string;
            supportsPower: boolean;
        }>;
        intensities: Array<{
            value: string;
            label: string;
        }>;
        stepTypes: Array<{
            value: string;
            label: string;
            isContainer: boolean;
        }>;
        targetTypes: Array<{
            value: string;
            label: string;
        }>;
        conditionTypes: Array<{
            value: string;
            label: string;
        }>;
        templateActivities: PlannedSessionTemplateActivity[];
        recommendations: Record<string, PlannedSessionRecommendation[]>;
    };
    plannerOutlook: {
        horizon: number;
        currentDayProjectedLoad: number;
        totalProjectedLoad: number;
        projectedDayCount: number;
        projectedLoads: Array<{
            dayOffset: number;
            load: number;
        }>;
    };
}

export interface PlannedSessionFormSubmitPayload {
    plannedSessionId?: string;
    day: string;
    title: string;
    activityType: string;
    notes?: string;
    targetLoad?: string;
    manualTargetLoadOverride?: '1' | '0';
    targetDurationInMinutes?: string;
    targetDurationInSecondsPart?: string;
    targetIntensity?: string;
    templateActivityId?: string;
    workoutSteps?: PlannedSessionFormWorkoutStep[];
}

interface FetchPlannedSessionPreviewOptions {
    plannedSessionId?: string;
    day?: string;
    signal?: AbortSignal;
}

export function buildPlannedSessionEditorPath(query: {plannedSessionId?: string; day?: string} = {}): string {
    const searchParams = new URLSearchParams();

    if (query.plannedSessionId) {
        searchParams.set('plannedSessionId', query.plannedSessionId);
    }

    if (query.day) {
        searchParams.set('day', query.day);
    }

    return `/planned-session-editor${searchParams.size > 0 ? `?${searchParams.toString()}` : ''}`;
}

export async function fetchPlannedSessionPreview(basePath: string, options: FetchPlannedSessionPreviewOptions = {}): Promise<PlannedSessionEditorBootstrapResponse> {
    const searchParams = new URLSearchParams();

    if (options.plannedSessionId) {
        searchParams.set('plannedSessionId', options.plannedSessionId);
    }

    if (options.day) {
        searchParams.set('day', options.day);
    }

    const path = buildAppPath(basePath, `react-preview/api/planned-session${searchParams.size > 0 ? `?${searchParams.toString()}` : ''}`);

    return fetchJson<PlannedSessionEditorBootstrapResponse>(path, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
        },
        signal: options.signal,
    });
}

export async function savePlannedSessionPreview(basePath: string, payload: PlannedSessionFormSubmitPayload): Promise<{ok: true}> {
    return fetchJson<{ok: true}>(buildAppPath(basePath, 'react-preview/api/planned-session'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(payload),
    });
}

export async function deletePlannedSessionPreview(basePath: string, plannedSessionId: string): Promise<{ok: true}> {
    return fetchJson<{ok: true}>(buildAppPath(basePath, `react-preview/api/planned-session/${plannedSessionId}`), {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
        },
    });
}

export async function confirmPlannedSessionLinkPreview(basePath: string, plannedSessionId: string, linkedActivityId: string): Promise<{ok: true}> {
    return fetchJson<{ok: true}>(buildAppPath(basePath, `react-preview/api/planned-session/${plannedSessionId}/confirm-link`), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({linkedActivityId}),
    });
}

export async function unlinkPlannedSessionPreview(basePath: string, plannedSessionId: string): Promise<{ok: true}> {
    return fetchJson<{ok: true}>(buildAppPath(basePath, `react-preview/api/planned-session/${plannedSessionId}/unlink`), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
        },
    });
}
