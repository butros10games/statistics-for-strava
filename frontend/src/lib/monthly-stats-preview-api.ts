import {buildAppPath} from './bootstrap';
import {fetchJson} from './http';

export interface MonthlyStatsPreviewActivity {
    id: string;
    name: string;
    activityType: string;
    label: string;
    distance: number;
    elevation: number;
    movingTime: number;
}

export interface MonthlyStatsPreviewPlannedSession {
    id: string;
    title: string;
    day: string;
    activityType: string;
    label: string;
    targetIntensity: string | null;
    targetIntensityLabel: string | null;
    linkStatus: string;
    estimatedLoad: number | null;
    durationInSeconds: number | null;
    isKeySession: boolean;
    isBrickSession: boolean;
}

export interface MonthlyStatsPreviewRaceEvent {
    id: string;
    title: string;
    day: string;
    profile: string;
    profileLabel: string;
    priority: string;
    priorityLabel: string;
    location: string | null;
    countdownDays: number | null;
}

export interface MonthlyStatsPreviewTrainingBlock {
    id: string;
    title: string;
    startDay: string;
    endDay: string;
    phase: string;
    phaseLabel: string;
    focus: string | null;
}

export interface MonthlyStatsPreviewDay {
    date: string;
    dayNumber: number;
    isCurrentMonth: boolean;
    isToday: boolean;
    trainingBlockPhase: string | null;
    racePriority: string | null;
    activities: MonthlyStatsPreviewActivity[];
    plannedSessions: MonthlyStatsPreviewPlannedSession[];
    raceEvents: MonthlyStatsPreviewRaceEvent[];
    trainingBlocks: MonthlyStatsPreviewTrainingBlock[];
}

export interface MonthlyStatsPreviewWeek {
    id: string;
    days: MonthlyStatsPreviewDay[];
}

export interface MonthlyStatsPreviewResponse {
    requestedAt: string;
    summary: {
        monthCount: number;
        totalActivities: number;
        totalDistance: number;
        totalElevation: number;
        totalMovingTime: number;
        totalCalories: number;
        plannedSessionCount: number;
        linkedPlannedSessionCount: number;
        raceEventCount: number;
        trainingBlockCount: number;
        estimatedPlannedLoad: number;
    };
    navigation: {
        currentMonthId: string;
        currentMonthLabel: string;
        previousMonthId: string | null;
        previousMonthLabel: string | null;
        nextMonthId: string | null;
        nextMonthLabel: string | null;
        hasPrevious: boolean;
        hasNext: boolean;
        legacyPath: string;
    };
    month: {
        id: string;
        label: string;
        isCurrentMonth: boolean;
        legacyPath: string;
        activityTypeBreakdown: Array<{
            activityType: string;
            label: string;
            color: string;
            count: number;
            distance: number;
            elevation: number;
            movingTime: number;
            calories: number;
        }>;
    };
    currentWeek: {
        from: string;
        to: string;
        estimatedLoad: number;
        plannedSessionCount: number;
        raceEventCount: number;
        trainingBlockCount: number;
        keySessionIds: string[];
        brickSessionIds: string[];
        activityTypeSummaries: Array<{
            activityType: string;
            label: string;
            count: number;
        }>;
        raceIntent: {
            label: string;
            tone: string;
            title: string;
            body: string;
        } | null;
        coachCues: Array<{
            tone: string;
            title: string;
            body: string;
        }>;
        plannedSessions: MonthlyStatsPreviewPlannedSession[];
        raceEvents: MonthlyStatsPreviewRaceEvent[];
        trainingBlocks: MonthlyStatsPreviewTrainingBlock[];
    };
    upcomingRaceEvents: MonthlyStatsPreviewRaceEvent[];
    trainingBlocks: {
        current: MonthlyStatsPreviewTrainingBlock | null;
        upcoming: MonthlyStatsPreviewTrainingBlock[];
    };
    calendar: {
        weeks: MonthlyStatsPreviewWeek[];
    };
}

export async function fetchMonthlyStatsPreview(basePath: string, monthId?: string, signal?: AbortSignal): Promise<MonthlyStatsPreviewResponse> {
    const normalizedMonthId = monthId?.replace(/^month-/, '');
    const url = new URL(buildAppPath(basePath, 'react-preview/api/monthly-stats'), window.location.origin);

    if (normalizedMonthId) {
        url.searchParams.set('month', normalizedMonthId);
    }

    try {
        return await fetchJson<MonthlyStatsPreviewResponse>(url, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
            signal,
        });
    } catch (error) {
        if (error instanceof Error && error.message.startsWith('Request failed with')) {
            throw new Error(`Monthly stats preview ${error.message.toLowerCase()}`);
        }

        if (error instanceof Error && error.message === 'Request did not return JSON.') {
            throw new Error('Monthly stats preview did not return JSON.');
        }

        throw error;
    }
}
