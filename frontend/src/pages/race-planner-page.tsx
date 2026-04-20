import {useCallback, useEffect, useMemo, useState} from 'react';
import {Link, useParams} from 'react-router-dom';
import {StatCard} from '../components/stat-card';
import {TrainingPlanCreateModal} from '../components/training-plan-create-modal';
import {buildAppPath, type ReactPreviewBootstrap} from '../lib/bootstrap';
import {
    fetchRacePlannerPreview,
    regenerateRacePlannerUpcomingSessions,
    saveRacePlannerRecovery,
    setupRacePlannerPlan,
    type RacePlannerPreviewBlock,
    type RacePlannerPreviewRace,
    type RacePlannerPreviewRecommendation,
    type RacePlannerPreviewResponse,
    type RacePlannerPreviewSession,
    type RacePlannerPreviewWarning,
    updateRacePlannerStartDate,
} from '../lib/race-planner-api';
import {useAsyncResource} from '../lib/use-async-resource';

interface RacePlannerPageProps {
    bootstrap: ReactPreviewBootstrap;
}

type PendingAction = 'recovery' | 'regenerate' | 'setup' | 'start-date' | null;

function formatDate(day: string): string {
    return new Intl.DateTimeFormat('en', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(new Date(day));
}

function formatShortDate(day: string): string {
    return new Intl.DateTimeFormat('en', {
        month: 'short',
        day: 'numeric',
    }).format(new Date(day));
}

function formatDateRange(startDay: string, endDay: string): string {
    return `${formatShortDate(startDay)} → ${formatShortDate(endDay)}`;
}

function formatSeconds(totalSeconds: number): string {
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;

    if (hours > 0) {
        return `${hours}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
    }

    return `${Math.floor(totalSeconds / 60)}:${String(seconds).padStart(2, '0')}`;
}

function formatLabel(value: string | null): string | null {
    if (!value) {
        return null;
    }

    return value
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replace(/[-_]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

function buildRacePriorityTone(priority: string): string {
    switch (priority) {
        case 'a':
            return 'bg-rose-100 text-rose-800 dark:bg-rose-950/40 dark:text-rose-100';
        case 'b':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-100';
        default:
            return 'bg-sky-100 text-sky-800 dark:bg-sky-950/40 dark:text-sky-100';
    }
}

function buildWarningTone(item: RacePlannerPreviewRecommendation | RacePlannerPreviewWarning): string {
    switch (item.severity) {
        case 'critical':
            return 'border-rose-200 bg-rose-50/90 text-rose-900 dark:border-rose-900/50 dark:bg-rose-950/30 dark:text-rose-100';
        case 'warning':
            return 'border-amber-200 bg-amber-50/90 text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-100';
        default:
            return 'border-sky-200 bg-sky-50/90 text-sky-900 dark:border-sky-900/50 dark:bg-sky-950/30 dark:text-sky-100';
    }
}

function buildPhaseTone(phase: string): string {
    switch (phase) {
        case 'base':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-100';
        case 'build':
            return 'bg-sky-100 text-sky-800 dark:bg-sky-950/40 dark:text-sky-100';
        case 'peak':
            return 'bg-violet-100 text-violet-800 dark:bg-violet-950/40 dark:text-violet-100';
        case 'taper':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-100';
        default:
            return 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-100';
    }
}

function buildIntensityTone(intensity: RacePlannerPreviewSession['targetIntensity']): string {
    switch (intensity) {
        case 'easy':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-100';
        case 'moderate':
            return 'bg-sky-100 text-sky-800 dark:bg-sky-950/40 dark:text-sky-100';
        case 'hard':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-100';
        default:
            return 'bg-rose-100 text-rose-800 dark:bg-rose-950/40 dark:text-rose-100';
    }
}

function RacePlannerLoadingState() {
    return (
        <section className="glass-panel rounded-[32px] p-6">
            <div className="section-kicker">Loading</div>
            <div className="mt-5 grid gap-4 xl:grid-cols-[1.05fr_0.95fr]">
                <div className="space-y-4">
                    <div className="animate-pulse rounded-[28px] border border-gray-200 bg-white/85 p-6 dark:border-gray-800 dark:bg-gray-900/60">
                        <div className="h-3 w-24 rounded-full bg-gray-200 dark:bg-gray-800" />
                        <div className="mt-5 h-10 w-3/4 rounded-full bg-gray-200 dark:bg-gray-800" />
                        <div className="mt-4 h-5 w-1/2 rounded-full bg-gray-100 dark:bg-gray-800" />
                    </div>
                    <div className="grid gap-4 md:grid-cols-3">
                        {Array.from({length: 3}).map((_, index) => (
                            <div key={index} className="animate-pulse rounded-[28px] border border-gray-200 bg-white/85 p-5 dark:border-gray-800 dark:bg-gray-900/60">
                                <div className="h-3 w-20 rounded-full bg-gray-200 dark:bg-gray-800" />
                                <div className="mt-4 h-8 w-16 rounded-full bg-gray-200 dark:bg-gray-800" />
                            </div>
                        ))}
                    </div>
                </div>
                <div className="animate-pulse rounded-[28px] border border-gray-200 bg-white/85 p-6 dark:border-gray-800 dark:bg-gray-900/60">
                    <div className="h-3 w-24 rounded-full bg-gray-200 dark:bg-gray-800" />
                    <div className="mt-5 space-y-3">
                        {Array.from({length: 5}).map((_, index) => (
                            <div key={index} className="h-14 rounded-2xl bg-gray-100 dark:bg-gray-800" />
                        ))}
                    </div>
                </div>
            </div>
        </section>
    );
}

function EmptyPlannerState({bootstrap}: {bootstrap: ReactPreviewBootstrap}) {
    return (
        <section className="glass-panel rounded-[36px] p-6 md:p-8">
            <div className="mx-auto max-w-3xl rounded-[32px] border border-gray-200 bg-white/90 p-10 text-center shadow-sm dark:border-gray-800 dark:bg-gray-900/70">
                <div className="section-kicker">Race planner</div>
                <h1 className="mt-5 text-4xl font-semibold tracking-tight text-gray-900 dark:text-white md:text-5xl">
                    No upcoming races are available yet.
                </h1>
                <p className="mx-auto mt-5 max-w-2xl text-base leading-8 text-gray-600 dark:text-gray-300 md:text-lg">
                    The planner is ready, but it needs at least one upcoming race event to build a season target and propose
                    the surrounding structure.
                </p>
                <div className="mt-8 flex flex-wrap justify-center gap-3">
                    <a
                        href={buildAppPath(bootstrap.basePath, 'training-plans')}
                        className="inline-flex items-center gap-2 rounded-2xl bg-strava-orange px-5 py-3 text-sm font-semibold text-white transition hover:bg-orange-600"
                    >
                        Open plan manager
                        <span aria-hidden="true">↗</span>
                    </a>
                    <a
                        href={buildAppPath(bootstrap.basePath, 'race-planner')}
                        className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                    >
                        Compare with live route
                        <span aria-hidden="true">↗</span>
                    </a>
                </div>
            </div>
        </section>
    );
}

function RaceCalendar({races}: {races: RacePlannerPreviewRace[]}) {
    if (races.length === 0) {
        return null;
    }

    return (
        <section className="glass-panel rounded-[32px] p-6">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <div className="section-kicker">Upcoming race calendar</div>
                    <h2 className="mt-4 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">What the planner is steering toward</h2>
                </div>
                <div className="rounded-full border border-rose-200 bg-rose-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-rose-700 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-200">
                    {races.length} races
                </div>
            </div>
            <div className="mt-6 space-y-3">
                {races.map((race) => (
                    <div key={race.id} className="rounded-[24px] border border-gray-200 bg-white/85 p-4 dark:border-gray-800 dark:bg-gray-900/60">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <div className="font-semibold text-gray-900 dark:text-white">{race.title}</div>
                                <div className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {formatDate(race.day)}{race.location ? ` · ${race.location}` : ''} · {formatLabel(race.profile)}
                                </div>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <span className={`rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] ${buildRacePriorityTone(race.priority)}`}>
                                    {race.priority.toUpperCase()}
                                </span>
                                {typeof race.countdownDays === 'number' ? (
                                    <span className="rounded-full border border-gray-200 bg-gray-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
                                        {race.countdownDays === 0 ? 'Race day' : `D-${race.countdownDays}`}
                                    </span>
                                ) : null}
                            </div>
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}

function PlannerLegend() {
    return (
        <section className="glass-panel rounded-[32px] p-6">
            <div className="section-kicker">Legend</div>
            <div className="mt-5 grid gap-3 sm:grid-cols-2">
                {[
                    ['Easy', '#6ee7b7'],
                    ['Moderate', '#7dd3fc'],
                    ['Hard', '#fcd34d'],
                    ['Race effort', '#fda4af'],
                ].map(([label, color]) => (
                    <div key={label} className="flex items-center gap-3 rounded-2xl border border-gray-200 bg-white/85 px-4 py-3 dark:border-gray-800 dark:bg-gray-900/60">
                        <span className="h-3 w-3 rounded-sm" style={{backgroundColor: color}} />
                        <span className="text-sm font-medium text-gray-700 dark:text-gray-200">{label}</span>
                    </div>
                ))}
            </div>
        </section>
    );
}

export function RacePlannerPage({bootstrap}: RacePlannerPageProps) {
    const {trainingPlanId} = useParams<{trainingPlanId?: string}>();
    const [editTrainingPlanId, setEditTrainingPlanId] = useState<string | null>(null);
    const [pendingAction, setPendingAction] = useState<PendingAction>(null);
    const [actionError, setActionError] = useState<string | null>(null);
    const [planStartDay, setPlanStartDay] = useState('');

    const loadPlanner = useCallback(
        (signal: AbortSignal): Promise<RacePlannerPreviewResponse> => fetchRacePlannerPreview(bootstrap.basePath, trainingPlanId, signal),
        [bootstrap.basePath, trainingPlanId],
    );

    const {data, loading, error, reload} = useAsyncResource(loadPlanner);

    useEffect(() => {
        setPlanStartDay(data?.planStartDayInputValue ?? '');
    }, [data?.planStartDayInputValue]);

    const summary = useMemo(() => {
        if (!data?.proposal) {
            return null;
        }

        return {
            blocks: data.proposal.blocks.length,
            weeks: data.proposal.totalWeeks,
            sessions: data.proposal.totalProposedSessions,
        };
    }, [data]);

    const targetTitle = data?.linkedTrainingPlan?.title ?? data?.targetRace?.title ?? 'Race planner';
    const targetDateSummary = data?.linkedTrainingPlan
        ? formatDateRange(data.linkedTrainingPlan.startDay, data.linkedTrainingPlan.endDay)
        : data?.targetRace
            ? formatDate(data.targetRace.day)
            : null;

    async function handleAction(action: PendingAction, callback: () => Promise<void>) {
        setPendingAction(action);
        setActionError(null);

        try {
            await callback();
            reload();
        } catch (requestError) {
            setActionError(requestError instanceof Error ? requestError.message : 'Something went sideways while updating the planner.');
        } finally {
            setPendingAction(null);
        }
    }

    if (loading && !data) {
        return <RacePlannerLoadingState />;
    }

    if (!loading && error && !data) {
        return (
            <section className="glass-panel rounded-[36px] p-6 md:p-8">
                <div className="section-kicker">Fetch error</div>
                <h1 className="mt-5 text-4xl font-semibold tracking-tight text-gray-900 dark:text-white md:text-5xl">
                    The React planner route could not load its live data.
                </h1>
                <p className="mt-5 max-w-3xl text-base leading-8 text-gray-600 dark:text-gray-300 md:text-lg">
                    {error}. This usually means the session expired, the backend preview route is unavailable, or the current
                    preview bundle is out of date.
                </p>
                <div className="mt-8 flex flex-wrap gap-3">
                    <button
                        type="button"
                        onClick={reload}
                        className="inline-flex items-center gap-2 rounded-2xl bg-strava-orange px-5 py-3 text-sm font-semibold text-white transition hover:bg-orange-600"
                    >
                        Retry preview fetch
                        <span aria-hidden="true">↻</span>
                    </button>
                    <a
                        href={buildAppPath(bootstrap.basePath, trainingPlanId ? `race-planner/plan-${trainingPlanId}` : 'race-planner')}
                        className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                    >
                        Open the live route
                        <span aria-hidden="true">↗</span>
                    </a>
                </div>
            </section>
        );
    }

    if (!data) {
        return null;
    }

    if (!data.hasUpcomingRaces) {
        return <EmptyPlannerState bootstrap={bootstrap} />;
    }

    return (
        <div className="space-y-8 pb-8">
            <section className="glass-panel rounded-[36px] p-6 md:p-8">
                <div className="grid gap-8 xl:grid-cols-[1.1fr_0.9fr]">
                    <div>
                        <div className="section-kicker">{data.mode === 'plan-preview' ? 'Plan preview route' : 'Route migration'}</div>
                        <h1 className="mt-5 text-4xl font-semibold tracking-tight text-gray-900 dark:text-white md:text-5xl">
                            {targetTitle}
                        </h1>
                        {targetDateSummary ? (
                            <p className="mt-4 text-base leading-8 text-gray-600 dark:text-gray-300 md:text-lg">
                                {targetDateSummary}
                                {data.targetRace?.location ? ` · ${data.targetRace.location}` : ''}
                                {data.targetRace?.profile ? ` · ${formatLabel(data.targetRace.profile)}` : ''}
                            </p>
                        ) : null}
                        <div className="mt-5 flex flex-wrap items-center gap-2">
                            {data.targetRace ? (
                                <span className={`rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] ${buildRacePriorityTone(data.targetRace.priority)}`}>
                                    {data.targetRace.priority.toUpperCase()} race
                                </span>
                            ) : null}
                            {data.linkedTrainingPlan ? (
                                <span className="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                    {data.linkedTrainingPlan.type === 'race' ? 'Race plan' : 'Training plan'}
                                </span>
                            ) : null}
                            {typeof data.countdownDays === 'number' ? (
                                <span className="rounded-full border border-orange-200 bg-orange-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-orange-700 dark:border-orange-900/40 dark:bg-orange-950/40 dark:text-orange-200">
                                    {data.countdownDays === 0 ? 'Race day' : `${data.countdownDays} days to go`}
                                </span>
                            ) : null}
                            {data.linkedTrainingPlanNeedsSync ? (
                                <span className="rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-amber-800 dark:border-amber-900/40 dark:bg-amber-950/40 dark:text-amber-100">
                                    Plan out of sync
                                </span>
                            ) : null}
                        </div>
                        {data.linkedTrainingPlan?.notes ? (
                            <p className="mt-5 max-w-3xl rounded-[24px] border border-gray-200 bg-white/85 p-4 text-sm leading-7 text-gray-700 dark:border-gray-800 dark:bg-gray-900/60 dark:text-gray-200">
                                {data.linkedTrainingPlan.notes}
                            </p>
                        ) : null}
                        <div className="mt-6 flex flex-wrap gap-3">
                            {data.actions.canEditLinkedTrainingPlan && data.linkedTrainingPlan ? (
                                <button
                                    type="button"
                                    onClick={() => setEditTrainingPlanId(data.linkedTrainingPlan?.id ?? null)}
                                    className="inline-flex items-center gap-2 rounded-2xl bg-strava-orange px-5 py-3 text-sm font-semibold text-white transition hover:bg-orange-600"
                                >
                                    Edit plan in React
                                    <span aria-hidden="true">✎</span>
                                </button>
                            ) : null}
                            {data.actions.canSetupPlan && data.targetRace ? (
                                <button
                                    type="button"
                                    onClick={() => handleAction('setup', () => setupRacePlannerPlan(bootstrap.basePath, data.targetRace!.id))}
                                    disabled={pendingAction !== null}
                                    className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                                >
                                    {data.linkedTrainingPlan ? 'Sync plan' : 'Create real plan'}
                                    <span aria-hidden="true">→</span>
                                </button>
                            ) : null}
                            {data.actions.canRegenerateUpcomingSessions && data.targetRace ? (
                                <button
                                    type="button"
                                    onClick={() => handleAction('regenerate', () => regenerateRacePlannerUpcomingSessions(bootstrap.basePath, data.targetRace!.id))}
                                    disabled={pendingAction !== null}
                                    className="inline-flex items-center gap-2 rounded-2xl border border-sky-200 bg-sky-50 px-5 py-3 text-sm font-semibold text-sky-800 transition hover:border-sky-300 hover:bg-sky-100 disabled:cursor-not-allowed disabled:opacity-60 dark:border-sky-900/50 dark:bg-sky-950/40 dark:text-sky-100"
                                >
                                    Regenerate upcoming sessions
                                    <span aria-hidden="true">↻</span>
                                </button>
                            ) : null}
                            {data.actions.canSaveRecovery && data.targetRace ? (
                                <button
                                    type="button"
                                    onClick={() => handleAction('recovery', () => saveRacePlannerRecovery(bootstrap.basePath, data.targetRace!.id))}
                                    disabled={pendingAction !== null}
                                    className="inline-flex items-center gap-2 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-3 text-sm font-semibold text-emerald-800 transition hover:border-emerald-300 hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-60 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-100"
                                >
                                    Save recovery to calendar
                                    <span aria-hidden="true">✓</span>
                                </button>
                            ) : null}
                            <a
                                href={buildAppPath(bootstrap.basePath, data.legacyPlannerPath)}
                                className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                            >
                                Compare with the live route
                                <span aria-hidden="true">↗</span>
                            </a>
                            <Link
                                to="/training-plans"
                                className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                            >
                                Open plan manager
                                <span aria-hidden="true">→</span>
                            </Link>
                        </div>
                        {data.actions.canChangeStartDay ? (
                            <form
                                className="mt-6 flex flex-wrap items-center gap-3 rounded-[24px] border border-gray-200 bg-white/85 p-4 dark:border-gray-800 dark:bg-gray-900/60"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    void handleAction('start-date', () =>
                                        updateRacePlannerStartDate(bootstrap.basePath, {
                                            planStartDay,
                                        }),
                                    );
                                }}
                            >
                                <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Planning window</div>
                                <input
                                    type="date"
                                    value={planStartDay}
                                    max={data.targetRace?.day ?? undefined}
                                    onChange={(event) => setPlanStartDay(event.target.value)}
                                    className="rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-700 shadow-sm outline-none transition focus:border-orange-300 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"
                                />
                                <button
                                    type="submit"
                                    disabled={pendingAction !== null}
                                    className="inline-flex items-center gap-2 rounded-2xl bg-strava-orange px-4 py-3 text-sm font-semibold text-white transition hover:bg-orange-600 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    Update plan
                                    <span aria-hidden="true">→</span>
                                </button>
                                {data.hasCustomPlanStartDay ? (
                                    <button
                                        type="button"
                                        disabled={pendingAction !== null}
                                        onClick={() =>
                                            void handleAction('start-date', () =>
                                                updateRacePlannerStartDate(bootstrap.basePath, {
                                                    resetPlanStartDay: true,
                                                }),
                                            )
                                        }
                                        className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"
                                    >
                                        Reset to today
                                    </button>
                                ) : null}
                            </form>
                        ) : null}
                        {actionError ? (
                            <div className="mt-4 rounded-[24px] border border-rose-200 bg-rose-50/90 px-4 py-3 text-sm leading-7 text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/30 dark:text-rose-100">
                                {actionError}
                            </div>
                        ) : null}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <StatCard
                            label="Planner mode"
                            value={data.mode === 'plan-preview' ? 'Plan preview' : 'Live route'}
                            hint={data.mode === 'plan-preview' ? 'This view targets a specific stored training plan.' : 'This route mirrors the global planner that tracks the next target race.'}
                            tone="orange"
                        />
                        <StatCard
                            label="Forecast"
                            value={data.runningPerformancePrediction ? data.runningPerformancePrediction.projectedThresholdPace : '—'}
                            hint={data.runningPerformancePrediction ? 'Projected threshold pace from the current plan context.' : 'No running forecast is available for this planner state yet.'}
                            tone="emerald"
                        />
                        <StatCard
                            label="Recommendations"
                            value={data.recommendations.length}
                            hint={data.recommendations.length === 0 ? 'No weekly adaptation nudge right now — lovely.' : 'Current-week coaching suggestions derived from live planner context.'}
                            tone="blue"
                        />
                        <StatCard
                            label="Structure"
                            value={summary ? `${summary.blocks} blocks` : '—'}
                            hint={summary ? `${summary.weeks} weeks and ${summary.sessions} proposed sessions in the current planner view.` : 'No structure is available yet.'}
                        />
                    </div>
                </div>
            </section>

            <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Warnings"
                    value={data.warnings.length}
                    hint={data.warnings.length === 0 ? 'No warnings in the current planner view.' : 'Live proposal warnings surfaced from the backend planner logic.'}
                    tone="orange"
                />
                <StatCard
                    label="Displayed races"
                    value={data.displayedUpcomingRaces.length}
                    hint={data.displayedUpcomingRaces.length === 0 ? 'This preview intentionally hides the race calendar.' : 'Races shown alongside the current planner context.'}
                    tone="emerald"
                />
                <StatCard
                    label="Existing blocks"
                    value={data.existingBlocks.length}
                    hint={data.plannerUsesExistingBlocks ? 'The planner is anchoring itself to existing season structure.' : 'The proposal is freer to invent the season shape from scratch.'}
                    tone="blue"
                />
                <StatCard
                    label="Recovery save"
                    value={data.recoverySaveSummary?.missingRecoverySessionCount ?? 0}
                    hint={data.recoverySaveSummary?.hasAnythingToSave ? 'Recovery sessions can be written back to the calendar.' : 'No extra recovery blocks or sessions are waiting to be saved.'}
                />
            </section>

            <div className="grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
                <div className="space-y-6">
                    {data.warnings.length > 0 ? (
                        <section className="glass-panel rounded-[32px] p-6">
                            <div className="section-kicker">Warnings</div>
                            <div className="mt-5 space-y-3">
                                {data.warnings.map((warning) => (
                                    <div key={`${warning.type}-${warning.title}`} className={`rounded-[24px] border p-4 ${buildWarningTone(warning)}`}>
                                        <div className="text-sm font-semibold">{warning.title}</div>
                                        <div className="mt-2 text-sm leading-7 opacity-85">{warning.body}</div>
                                    </div>
                                ))}
                            </div>
                        </section>
                    ) : null}

                    {data.recommendations.length > 0 ? (
                        <section className="glass-panel rounded-[32px] p-6">
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <div className="section-kicker">Recommendations</div>
                                    <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">
                                        Current-week adaptation cues
                                    </h2>
                                </div>
                                <div className="rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-sky-700 dark:border-sky-900/50 dark:bg-sky-950/40 dark:text-sky-100">
                                    {data.recommendations.length} items
                                </div>
                            </div>
                            <div className="mt-6 space-y-3">
                                {data.recommendations.map((recommendation) => (
                                    <div key={`${recommendation.type}-${recommendation.title}`} className={`rounded-[24px] border p-4 ${buildWarningTone(recommendation)}`}>
                                        <div className="text-sm font-semibold">{recommendation.title}</div>
                                        <div className="mt-2 text-sm leading-7 opacity-85">{recommendation.body}</div>
                                        {recommendation.suggestedBlock ? (
                                            <div className="mt-3 rounded-2xl border border-white/70 bg-white/70 px-3 py-2 text-xs font-medium text-gray-700 dark:border-gray-800 dark:bg-gray-950/30 dark:text-gray-200">
                                                Suggested block: {recommendation.suggestedBlock.title} · {recommendation.suggestedBlock.durationInWeeks} weeks
                                            </div>
                                        ) : null}
                                    </div>
                                ))}
                            </div>
                        </section>
                    ) : null}

                    {data.runningPerformancePrediction ? (
                        <section className="glass-panel rounded-[32px] p-6">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <div className="section-kicker">Running forecast</div>
                                    <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">Current baseline versus plan-end potential</h2>
                                </div>
                                <div className="rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-sky-700 dark:border-sky-900/50 dark:bg-sky-950/40 dark:text-sky-100">
                                    {data.runningPerformancePrediction.confidenceLabel}
                                </div>
                            </div>
                            <div className="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                <StatCard label="Current threshold" value={data.runningPerformancePrediction.currentThresholdPace} hint="Current baseline pace." tone="orange" />
                                <StatCard label="Current trajectory" value={data.runningPerformancePrediction.trajectoryThresholdPace ?? '—'} hint={data.runningPerformancePrediction.trajectoryStatusLabel ?? 'No completed run sessions are linked yet.'} tone="blue" />
                                <StatCard label="Projected threshold" value={data.runningPerformancePrediction.projectedThresholdPace} hint="Ideal plan-end potential if the remaining work is executed well." tone="emerald" />
                                <StatCard label="Potential gain" value={data.runningPerformancePrediction.projectedGainLabel} hint={data.runningPerformancePrediction.trajectoryGainLabel ? `Current trajectory: ${data.runningPerformancePrediction.trajectoryGainLabel}` : 'Trajectory gain appears once completed sessions are linked.'} />
                            </div>
                            {data.runningPerformancePrediction.benchmarkPredictions.length > 0 ? (
                                <div className="mt-6 overflow-hidden rounded-[24px] border border-gray-200 dark:border-gray-800">
                                    <table className="w-full text-left text-sm text-gray-600 dark:text-gray-300">
                                        <thead className="bg-gray-50/90 text-xs uppercase tracking-[0.24em] text-gray-500 dark:bg-gray-900/70 dark:text-gray-400">
                                            <tr>
                                                <th className="px-4 py-3 font-semibold">Benchmark</th>
                                                <th className="px-4 py-3 font-semibold">Current</th>
                                                <th className="px-4 py-3 font-semibold">Projected</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {data.runningPerformancePrediction.benchmarkPredictions.map((benchmark) => (
                                                <tr key={benchmark.label} className="border-t border-gray-200 bg-white/70 dark:border-gray-800 dark:bg-gray-950/20">
                                                    <td className="px-4 py-3 font-medium text-gray-900 dark:text-white">{benchmark.label}</td>
                                                    <td className="px-4 py-3">{formatSeconds(benchmark.currentFinishTimeInSeconds)}</td>
                                                    <td className="px-4 py-3 font-semibold text-emerald-700 dark:text-emerald-300">{formatSeconds(benchmark.projectedFinishTimeInSeconds)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : null}
                            {data.runningPerformancePrediction.basisRows.length > 0 ? (
                                <div className="mt-6 rounded-[24px] border border-violet-200 bg-violet-50/80 p-4 dark:border-violet-900/50 dark:bg-violet-950/30">
                                    <div className="text-xs font-semibold uppercase tracking-[0.24em] text-violet-700 dark:text-violet-200">Prediction basis</div>
                                    <dl className="mt-4 grid gap-3 sm:grid-cols-2">
                                        {data.runningPerformancePrediction.basisRows.map((row) => (
                                            <div key={row.label} className="rounded-2xl border border-white/80 bg-white/80 px-4 py-3 dark:border-gray-800 dark:bg-gray-950/30">
                                                <dt className="text-xs font-semibold uppercase tracking-[0.2em] text-violet-700/70 dark:text-violet-200/80">{row.label}</dt>
                                                <dd className="mt-2 text-sm font-medium text-gray-800 dark:text-gray-100">{row.value}</dd>
                                            </div>
                                        ))}
                                    </dl>
                                    <p className="mt-4 text-sm leading-7 text-violet-900/80 dark:text-violet-100/80">{data.runningPerformancePrediction.basisNote}</p>
                                </div>
                            ) : null}
                        </section>
                    ) : null}

                    {data.proposal ? (
                        <section className="glass-panel rounded-[32px] p-6">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <div className="section-kicker">{data.plannerUsesExistingBlocks ? 'Current plan structure' : 'Proposed periodization'}</div>
                                    <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">
                                        The season shape in live React
                                    </h2>
                                    <p className="mt-3 text-base leading-8 text-gray-600 dark:text-gray-300">
                                        {data.proposal.totalWeeks} weeks · {data.proposal.totalProposedSessions} proposed sessions
                                    </p>
                                </div>
                                <div className="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                    {data.plannerUsesExistingBlocks ? 'Anchored to existing blocks' : 'Fresh proposal'}
                                </div>
                            </div>
                            <div className="mt-6 flex h-5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                                {data.proposal.blocks.map((block) => (
                                    <div
                                        key={`${block.startDay}-${block.title}`}
                                        title={`${block.title} (${block.durationInWeeks} weeks)`}
                                        className="flex items-center justify-center text-[10px] font-semibold uppercase tracking-[0.22em] text-white"
                                        style={{
                                            width: `${(block.durationInWeeks / Math.max(1, data.proposal?.totalWeeks ?? 1)) * 100}%`,
                                            backgroundColor:
                                                block.phase === 'base'
                                                    ? '#34d399'
                                                    : block.phase === 'build'
                                                        ? '#38bdf8'
                                                        : block.phase === 'peak'
                                                            ? '#a78bfa'
                                                            : block.phase === 'taper'
                                                                ? '#fbbf24'
                                                                : '#94a3b8',
                                        }}
                                    >
                                        {block.durationInWeeks >= 2 ? block.phaseLabel : ''}
                                    </div>
                                ))}
                            </div>
                            <div className="mt-6 space-y-4">
                                {data.proposal.blocks.map((block: RacePlannerPreviewBlock) => (
                                    <details key={`${block.startDay}-${block.title}`} className="rounded-[28px] border border-gray-200 bg-white/85 p-5 dark:border-gray-800 dark:bg-gray-900/60" open>
                                        <summary className="cursor-pointer list-none">
                                            <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                                <div>
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className={`rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] ${buildPhaseTone(block.phase)}`}>
                                                            {block.phaseLabel}
                                                        </span>
                                                        <span className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-400 dark:text-gray-500">
                                                            {block.durationInWeeks} weeks
                                                        </span>
                                                    </div>
                                                    <h3 className="mt-3 text-2xl font-semibold text-gray-900 dark:text-white">{block.title}</h3>
                                                    <p className="mt-2 text-sm leading-7 text-gray-600 dark:text-gray-300">
                                                        {formatDateRange(block.startDay, block.endDay)}
                                                        {block.focus ? ` · ${block.focus}` : ''}
                                                    </p>
                                                </div>
                                                <div className="rounded-[20px] border border-gray-200 bg-white/80 px-4 py-3 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-950/30 dark:text-gray-200">
                                                    <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-400 dark:text-gray-500">Block summary</div>
                                                    <div className="mt-2 font-medium">{block.totalSessions} sessions across {block.weeks.length} weeks</div>
                                                </div>
                                            </div>
                                        </summary>
                                        <div className="mt-5 space-y-3">
                                            {block.weeks.map((week) => (
                                                <details key={`${block.title}-${week.weekNumber}-${week.startDay}`} className="rounded-[24px] border border-gray-200 bg-gray-50/80 p-4 dark:border-gray-800 dark:bg-gray-950/20" open={week.weekNumber === block.weeks[0]?.weekNumber}>
                                                    <summary className="cursor-pointer list-none">
                                                        <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                                            <div>
                                                                <div className="flex flex-wrap items-center gap-2">
                                                                    <span className="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                                                        W{week.weekNumber}
                                                                    </span>
                                                                    {week.isManuallyPlanned ? <span className="rounded-full border border-slate-200 bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-slate-700 dark:border-slate-900/40 dark:bg-slate-900/40 dark:text-slate-100">Manual</span> : null}
                                                                    {week.isRecoveryWeek ? <span className="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-100">Recovery</span> : null}
                                                                    {week.raceSummaryLabel ? <span className="rounded-full border border-rose-200 bg-rose-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-rose-700 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">{week.raceSummaryLabel}</span> : null}
                                                                </div>
                                                                <div className="mt-3 text-sm leading-7 text-gray-600 dark:text-gray-300">
                                                                    {formatDateRange(week.startDay, week.endDay)} · {week.sessionCount} sessions · Load {week.targetLoadPercentage}%
                                                                </div>
                                                            </div>
                                                            <div className="flex flex-wrap gap-2 text-xs font-medium">
                                                                {week.disciplineDurations.swim ? <span className="rounded-full border border-emerald-200 bg-white px-3 py-1 text-emerald-700 dark:border-emerald-900/50 dark:bg-gray-950 dark:text-emerald-200">Swim {week.disciplineDurations.swim}</span> : null}
                                                                {week.disciplineDurations.bike ? <span className="rounded-full border border-sky-200 bg-white px-3 py-1 text-sky-700 dark:border-sky-900/50 dark:bg-gray-950 dark:text-sky-200">Bike {week.disciplineDurations.bike}</span> : null}
                                                                {week.disciplineDurations.run ? <span className="rounded-full border border-amber-200 bg-white px-3 py-1 text-amber-700 dark:border-amber-900/50 dark:bg-gray-950 dark:text-amber-200">Run {week.disciplineDurations.run}</span> : null}
                                                                {week.projectedThresholdPace ? <span className="rounded-full border border-violet-200 bg-white px-3 py-1 text-violet-700 dark:border-violet-900/50 dark:bg-gray-950 dark:text-violet-200">Threshold {week.projectedThresholdPace}</span> : null}
                                                                {week.doubleRunDayCount > 0 ? <span className="rounded-full border border-orange-200 bg-white px-3 py-1 text-orange-700 dark:border-orange-900/50 dark:bg-gray-950 dark:text-orange-200">Double run {week.doubleRunDayCount}×</span> : null}
                                                            </div>
                                                        </div>
                                                    </summary>
                                                    <div className="mt-4 space-y-3">
                                                        {week.sessions.map((session) => (
                                                            <details key={`${session.day}-${session.title}-${session.activityType}`} className="rounded-[20px] border border-gray-200 bg-white/90 p-4 dark:border-gray-800 dark:bg-gray-900/60">
                                                                <summary className="cursor-pointer list-none">
                                                                    <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                                                        <div>
                                                                            <div className="flex flex-wrap items-center gap-2">
                                                                                <span className="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-gray-600 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200">
                                                                                    {session.dayLabel}
                                                                                </span>
                                                                                <span className={`rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] ${buildIntensityTone(session.targetIntensity)}`}>
                                                                                    {session.targetIntensityLabel}
                                                                                </span>
                                                                                {session.isKeySession ? <span className="rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-amber-700 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-100">Key</span> : null}
                                                                                {session.isBrickSession ? <span className="rounded-full border border-fuchsia-200 bg-fuchsia-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-fuchsia-700 dark:border-fuchsia-900/50 dark:bg-fuchsia-950/40 dark:text-fuchsia-100">Brick</span> : null}
                                                                            </div>
                                                                            <h4 className="mt-3 text-lg font-semibold text-gray-900 dark:text-white">{session.title}</h4>
                                                                            <div className="mt-2 text-sm leading-7 text-gray-600 dark:text-gray-300">
                                                                                {session.activityLabel}
                                                                                {session.durationLabel ? ` · ${session.durationLabel}` : ''}
                                                                                {session.isDoubleRunSession ? ' · Double run day' : ''}
                                                                                {session.isSecondaryRunSession ? ' · 2nd run' : ''}
                                                                            </div>
                                                                        </div>
                                                                        {session.projectedThresholdPace ? (
                                                                            <div className="rounded-[18px] border border-violet-200 bg-violet-50/80 px-4 py-3 text-sm text-violet-900 dark:border-violet-900/50 dark:bg-violet-950/30 dark:text-violet-100">
                                                                                <div className="text-xs font-semibold uppercase tracking-[0.24em] text-violet-700 dark:text-violet-200">
                                                                                    {session.usesWeekForecastCopy ? 'Week forecast' : 'Week threshold'}
                                                                                </div>
                                                                                <div className="mt-2 font-semibold">{session.projectedThresholdPace}</div>
                                                                            </div>
                                                                        ) : null}
                                                                    </div>
                                                                </summary>
                                                                {(session.workoutPreviewRows.length > 0 || session.notes || session.projectedThresholdPace) ? (
                                                                    <div className="mt-4 border-t border-gray-200 pt-4 dark:border-gray-800">
                                                                        {session.projectedThresholdPace ? (
                                                                            <div className="mb-4 rounded-[18px] border border-violet-200 bg-violet-50/80 px-4 py-3 text-sm leading-7 text-violet-900 dark:border-violet-900/50 dark:bg-violet-950/30 dark:text-violet-100">
                                                                                {session.usesWeekForecastCopy
                                                                                    ? 'Forecast shown for context. Manual sessions keep their original targets.'
                                                                                    : 'Targets in this session scale from your projected fitness for this week.'}
                                                                            </div>
                                                                        ) : null}
                                                                        {session.workoutPreviewRows.length > 0 ? (
                                                                            <div className="space-y-2">
                                                                                {session.workoutPreviewRows.map((row, rowIndex) => (
                                                                                    <div key={`${session.title}-${rowIndex}-${row.headline}`} className="rounded-2xl border border-gray-200 bg-gray-50/80 px-4 py-3 text-sm leading-7 text-gray-700 dark:border-gray-800 dark:bg-gray-950/20 dark:text-gray-200" style={{marginLeft: `${row.depth * 18}px`}}>
                                                                                        <div className="font-medium text-gray-900 dark:text-white">{row.headline}</div>
                                                                                        {row.meta ? <div className="mt-1 text-xs font-medium uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">{row.meta}</div> : null}
                                                                                    </div>
                                                                                ))}
                                                                            </div>
                                                                        ) : null}
                                                                        {session.notes ? <p className="mt-4 text-sm leading-7 text-gray-600 dark:text-gray-300">{session.notes}</p> : null}
                                                                    </div>
                                                                ) : null}
                                                            </details>
                                                        ))}
                                                    </div>
                                                </details>
                                            ))}
                                        </div>
                                    </details>
                                ))}
                            </div>
                        </section>
                    ) : null}
                </div>

                <div className="space-y-6">
                    {data.rules ? (
                        <section className="glass-panel rounded-[32px] p-6">
                            <div className="section-kicker">Profile guidelines</div>
                            <div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                                {[
                                    ['Ideal plan', `${data.rules.idealPlanWeeks}w (${data.rules.minimumPlanWeeks}–${data.rules.maximumPlanWeeks})`],
                                    ['Taper', `${data.rules.taperWeeks}w`],
                                    ['Sessions/week', `${data.rules.sessionsPerWeekMinimum}–${data.rules.sessionsPerWeekMaximum}`],
                                    ['Hard/week', `${data.rules.hardSessionsPerWeek}`],
                                    ['Long/week', `${data.rules.longSessionsPerWeek}`],
                                    ['Disciplines', data.rules.disciplines.join(', ') || 'Mixed'],
                                ].map(([label, value]) => (
                                    <div key={label} className="rounded-[22px] border border-gray-200 bg-white/85 px-4 py-3 dark:border-gray-800 dark:bg-gray-900/60">
                                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">{label}</div>
                                        <div className="mt-2 text-sm font-medium text-gray-900 dark:text-white">{value}</div>
                                    </div>
                                ))}
                            </div>
                        </section>
                    ) : null}

                    <RaceCalendar races={data.displayedUpcomingRaces} />

                    {data.existingBlocks.length > 0 ? (
                        <section className="glass-panel rounded-[32px] p-6">
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <div className="section-kicker">Existing season blocks</div>
                                    <h2 className="mt-4 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Current blocks in the planning window</h2>
                                </div>
                                <div className="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                    {data.existingBlocks.length}
                                </div>
                            </div>
                            <div className="mt-6 space-y-3">
                                {data.existingBlocks.map((block) => (
                                    <div key={block.id} className="rounded-[24px] border border-gray-200 bg-white/85 p-4 dark:border-gray-800 dark:bg-gray-900/60">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className={`rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] ${buildPhaseTone(block.phase)}`}>
                                                {block.phaseLabel}
                                            </span>
                                        </div>
                                        <div className="mt-3 text-lg font-semibold text-gray-900 dark:text-white">{block.title ?? block.phaseLabel}</div>
                                        <div className="mt-2 text-sm leading-7 text-gray-600 dark:text-gray-300">
                                            {formatDateRange(block.startDay, block.endDay)}{block.focus ? ` · ${block.focus}` : ''}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </section>
                    ) : null}

                    <PlannerLegend />
                </div>
            </div>

            <TrainingPlanCreateModal
                basePath={bootstrap.basePath}
                isOpen={null !== editTrainingPlanId}
                trainingPlanId={editTrainingPlanId ?? undefined}
                onClose={() => setEditTrainingPlanId(null)}
                onSaved={() => {
                    setEditTrainingPlanId(null);
                    reload();
                }}
            />
        </div>
    );
}
