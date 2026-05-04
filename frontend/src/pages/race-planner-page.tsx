import {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {Link, useNavigate, useParams} from 'react-router-dom';
import {StatCard} from '../components/stat-card';
import {buildAppPath, buildRouterPath, type ReactPreviewBootstrap} from '../lib/bootstrap';
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
import {buildTrainingPlanAnalysisPrompt, copyTextToClipboard} from '../lib/training-plan-analysis-prompt';
import {useAsyncResource} from '../lib/use-async-resource';

interface RacePlannerPageProps {
    bootstrap: ReactPreviewBootstrap;
}

type PendingAction = 'recovery' | 'regenerate' | 'setup' | 'start-date' | null;
type PlannerTab = 'content' | 'sidebar';

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
        <section className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div className="grid gap-4 xl:grid-cols-[1.05fr_0.95fr]">
                <div className="space-y-4">
                    <div className="animate-pulse rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
                        <div className="h-3 w-24 rounded-full bg-gray-200 dark:bg-gray-800" />
                        <div className="mt-4 h-8 w-3/4 rounded-full bg-gray-200 dark:bg-gray-800" />
                        <div className="mt-3 h-4 w-1/2 rounded-full bg-gray-100 dark:bg-gray-800" />
                    </div>
                    <div className="grid gap-4 md:grid-cols-3">
                        {Array.from({length: 3}).map((_, index) => (
                            <div key={index} className="animate-pulse rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                                <div className="h-3 w-20 rounded-full bg-gray-200 dark:bg-gray-800" />
                                <div className="mt-4 h-8 w-16 rounded-full bg-gray-200 dark:bg-gray-800" />
                            </div>
                        ))}
                    </div>
                </div>
                <div className="animate-pulse rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
                    <div className="h-3 w-24 rounded-full bg-gray-200 dark:bg-gray-800" />
                    <div className="mt-5 space-y-3">
                        {Array.from({length: 5}).map((_, index) => (
                            <div key={index} className="h-14 rounded-xl bg-gray-100 dark:bg-gray-800" />
                        ))}
                    </div>
                </div>
            </div>
        </section>
    );
}

function EmptyPlannerState({bootstrap}: {bootstrap: ReactPreviewBootstrap}) {
    return (
        <section className="rounded-lg border border-gray-200 bg-white py-16 text-center shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div className="mx-auto max-w-sm px-6">
                <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                    <svg className="h-8 w-8 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5" aria-hidden="true">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M3 3v1.5M3 21v-6m0 0 2.77-.693a9 9 0 0 1 6.208.682l.108.054a9 9 0 0 0 6.086.71l3.114-.732a48.524 48.524 0 0 1-.005-10.499l-3.11.732a9 9 0 0 1-6.085-.711l-.108-.054a9 9 0 0 0-6.208-.682L3 4.5M3 15V4.5" />
                    </svg>
                </div>
                <h1 className="text-base font-semibold text-gray-800 dark:text-white">No upcoming races</h1>
                <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">Add a race event from the training calendar to get started with race planning.</p>
                <div className="mt-6 flex flex-wrap justify-center gap-2">
                    <a href={buildAppPath(bootstrap.basePath, 'training-plans')} className="ui-button ui-button-primary">
                        Open plan manager
                    </a>
                    <a href={buildAppPath(bootstrap.basePath, 'race-planner')} className="ui-button">
                        Open classic planner
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

    function buildPriorityAccent(priority: string): string {
        switch (priority) {
            case 'a':
                return 'border-l-rose-500';
            case 'b':
                return 'border-l-amber-500';
            default:
                return 'border-l-sky-500';
        }
    }

    return (
        <section className="rounded-lg border border-gray-200 bg-white p-3.5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Race calendar</h2>
                </div>
                <div className="rounded-full bg-rose-50 px-2.5 py-1 text-[10px] font-semibold text-rose-700 dark:bg-rose-950/40 dark:text-rose-200">
                    {races.length} races
                </div>
            </div>
            <div className="mt-2.5 space-y-1.5">
                {races.map((race) => (
                    <div key={race.id} className={`rounded-lg border border-gray-100 border-l-2 px-2.5 py-1.5 dark:border-gray-800 ${buildPriorityAccent(race.priority)}`}>
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div className="text-[13px] font-medium text-gray-900 dark:text-white">{race.title}</div>
                                <div className="mt-0.5 text-[10px] text-gray-500 dark:text-gray-400">
                                    {formatDate(race.day)}{race.location ? ` · ${race.location}` : ''} · {formatLabel(race.profile)}
                                </div>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase ${buildRacePriorityTone(race.priority)}`}>
                                    {race.priority.toUpperCase()}
                                </span>
                                {typeof race.countdownDays === 'number' ? (
                                    <span className="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-200">
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
        <section className="rounded-lg border border-gray-200 bg-white px-3.5 py-2.5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Legend</h2>
            <div className="mt-2 grid gap-x-3 gap-y-1.5 sm:grid-cols-2">
                {[
                    ['Easy', '#6ee7b7'],
                    ['Moderate', '#7dd3fc'],
                    ['Hard', '#fcd34d'],
                    ['Race effort', '#fda4af'],
                ].map(([label, color]) => (
                    <div key={label} className="flex items-center gap-1.5 text-[10px] text-gray-500 dark:text-gray-400">
                        <span className="h-2.5 w-2.5 rounded-sm" style={{backgroundColor: color}} />
                        <span>{label}</span>
                    </div>
                ))}
            </div>
        </section>
    );
}

type PlannerSummaryTone = 'slate' | 'orange' | 'emerald' | 'blue';

const plannerSummaryToneClasses: Record<PlannerSummaryTone, string> = {
    slate: 'border-gray-200 bg-white text-gray-900 dark:border-gray-800 dark:bg-gray-950/40 dark:text-gray-100',
    orange: 'border-orange-200 bg-orange-50/70 text-orange-950 dark:border-orange-800/60 dark:bg-orange-950/30 dark:text-orange-100',
    emerald: 'border-emerald-200 bg-emerald-50/70 text-emerald-950 dark:border-emerald-800/60 dark:bg-emerald-950/30 dark:text-emerald-100',
    blue: 'border-sky-200 bg-sky-50/70 text-sky-950 dark:border-sky-800/60 dark:bg-sky-950/30 dark:text-sky-100',
};

function PlannerSummaryPill({
    label,
    value,
    hint,
    tone = 'slate',
}: {
    label: string;
    value: string | number;
    hint: string;
    tone?: PlannerSummaryTone;
}) {
    return (
        <div className={`rounded-lg border px-3 py-2 ${plannerSummaryToneClasses[tone]}`}>
            <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <span className="text-[10px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">{label}</span>
                <span className="text-[1.1rem] font-semibold leading-none tracking-tight">{value}</span>
                <span className="text-[11px] leading-4 text-gray-600 dark:text-gray-300">{hint}</span>
            </div>
        </div>
    );
}

function TrainingPlanAnalysisPromptButton({
    exportUrl,
    planTitle,
    plannerUrl,
}: {
    exportUrl: string;
    planTitle: string;
    plannerUrl: string;
}) {
    const [state, setState] = useState<'default' | 'success' | 'error'>('default');
    const [label, setLabel] = useState('Copy AI review prompt');
    const resetTimeoutRef = useRef<number | null>(null);

    useEffect(() => () => {
        if (resetTimeoutRef.current) {
            window.clearTimeout(resetTimeoutRef.current);
        }
    }, []);

    async function handleCopyPrompt() {
        const copied = await copyTextToClipboard(buildTrainingPlanAnalysisPrompt({
            planTitle,
            exportUrl,
            plannerUrl,
        }));

        if (resetTimeoutRef.current) {
            window.clearTimeout(resetTimeoutRef.current);
        }

        setState(copied ? 'success' : 'error');
        setLabel(copied ? 'Prompt copied' : 'Copy failed');

        resetTimeoutRef.current = window.setTimeout(() => {
            setState('default');
            setLabel('Copy AI review prompt');
        }, 2200);
    }

    const toneClasses = state === 'success'
        ? 'text-emerald-700 dark:text-emerald-200'
        : state === 'error'
            ? 'text-rose-700 dark:text-rose-200'
            : 'text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-100';

    return (
        <button
            type="button"
            onClick={() => void handleCopyPrompt()}
            className={`inline-flex items-center gap-1 rounded-md px-0 py-0 text-sm font-medium transition ${toneClasses}`}
            title={label}
            aria-label={label}
        >
            <span>{label}</span>
            <span aria-hidden="true">⎘</span>
        </button>
    );
}

export function RacePlannerPage({bootstrap}: RacePlannerPageProps) {
    const isPreviewExperience = bootstrap.experience === 'preview';
    const {trainingPlanId} = useParams<{trainingPlanId?: string}>();
    const navigate = useNavigate();
    const [pendingAction, setPendingAction] = useState<PendingAction>(null);
    const [actionError, setActionError] = useState<string | null>(null);
    const [planStartDay, setPlanStartDay] = useState('');
    const [activePlannerTab, setActivePlannerTab] = useState<PlannerTab>('content');
    const [isWidePlannerLayout, setIsWidePlannerLayout] = useState(() => window.matchMedia('(min-width: 1280px)').matches);

    const loadPlanner = useCallback(
        (signal: AbortSignal): Promise<RacePlannerPreviewResponse> => fetchRacePlannerPreview(bootstrap.basePath, trainingPlanId, signal),
        [bootstrap.basePath, trainingPlanId],
    );

    const {data, loading, error, reload} = useAsyncResource(loadPlanner);

    useEffect(() => {
        setPlanStartDay(data?.planStartDayInputValue ?? '');
    }, [data?.planStartDayInputValue]);

    useEffect(() => {
        const mediaQuery = window.matchMedia('(min-width: 1280px)');
        const handleChange = (event: MediaQueryListEvent) => {
            setIsWidePlannerLayout(event.matches);
        };

        setIsWidePlannerLayout(mediaQuery.matches);
        mediaQuery.addEventListener('change', handleChange);

        return () => mediaQuery.removeEventListener('change', handleChange);
    }, []);

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
    const trainingPlanExportUrl = data?.linkedTrainingPlan?.exportPath
        ? buildAppPath(bootstrap.basePath, data.linkedTrainingPlan.exportPath)
        : null;
    const plannerPromptUrl = typeof window === 'undefined'
        ? buildRouterPath(bootstrap.routerBasePath, trainingPlanId ? `race-planner/plan/${trainingPlanId}` : 'race-planner')
        : window.location.href;

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
            <section className="rounded-xl border border-rose-200 bg-rose-50 p-5 shadow-sm dark:border-rose-900/50 dark:bg-rose-950/30">
                <h1 className="text-lg font-semibold text-rose-900 dark:text-rose-100">
                    The planner could not load its live data.
                </h1>
                <p className="mt-2 max-w-3xl text-sm leading-7 text-rose-800 dark:text-rose-100">
                    {error}. This usually means the session expired, the backend route is unavailable, or the current
                    frontend bundle is out of date.
                </p>
                <div className="mt-4 flex flex-wrap gap-2">
                    <button type="button" onClick={reload} className="ui-button ui-button-primary">Retry loading</button>
                    <a href={buildAppPath(bootstrap.basePath, trainingPlanId ? `race-planner/plan-${trainingPlanId}` : 'race-planner')} className="ui-button">Open the live route</a>
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
        <div className="space-y-6 pb-6">
            <section className="rounded-lg border border-gray-200 bg-white p-3.5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div>
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-[1.45rem] font-semibold leading-tight text-gray-900 dark:text-white">
                                    {targetTitle}
                                </h1>
                                {data.targetRace ? (
                                    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold ${buildRacePriorityTone(data.targetRace.priority)}`}>
                                        {data.targetRace.priority.toUpperCase()}-Race
                                    </span>
                                ) : null}
                                {data.linkedTrainingPlan ? (
                                    <span className="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-bold text-gray-600 dark:bg-gray-800 dark:text-gray-200">
                                        {data.linkedTrainingPlan.type === 'race' ? 'Race plan' : 'Training plan'}
                                    </span>
                                ) : null}
                                {data.linkedTrainingPlanNeedsSync ? (
                                    <span className="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-700 dark:bg-amber-950/40 dark:text-amber-100">
                                        Plan out of sync
                                    </span>
                                ) : null}
                            </div>
                            <div className="mt-1 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-[13px] text-gray-500 dark:text-gray-400">
                                {targetDateSummary ? <span>{targetDateSummary}</span> : null}
                                {data.targetRace?.location ? <><span className="text-gray-300 dark:text-gray-600">·</span><span>{data.targetRace.location}</span></> : null}
                                {data.targetRace?.profile ? <><span className="text-gray-300 dark:text-gray-600">·</span><span>{formatLabel(data.targetRace.profile)}</span></> : null}
                            </div>
                            {data.linkedTrainingPlan?.notes ? (
                                <p className="mt-3 max-w-3xl text-sm leading-7 text-gray-600 dark:text-gray-300">
                                    {data.linkedTrainingPlan.notes}
                                </p>
                            ) : null}
                        </div>
                        {typeof data.countdownDays === 'number' ? (
                            <div className="shrink-0 sm:text-right">
                                <div className="inline-flex items-baseline gap-1 rounded-lg bg-orange-50 px-2.5 py-1.5 text-strava-orange dark:bg-orange-950/30">
                                    <span className="text-[1.7rem] font-bold tabular-nums">{data.countdownDays === 0 ? '0' : data.countdownDays}</span>
                                    <span className="text-[11px] font-medium text-gray-500 dark:text-gray-400">{data.countdownDays === 0 ? 'Race day' : 'days to go'}</span>
                                </div>
                            </div>
                        ) : null}
                    </div>
                        <div className="mt-4 flex flex-wrap items-center gap-2">
                            {data.actions.canEditLinkedTrainingPlan && data.linkedTrainingPlan ? (
                                <button
                                    type="button"
                                    onClick={() => navigate(`/training-plan-editor?trainingPlanId=${data.linkedTrainingPlan?.id ?? ''}`)}
                                    className="inline-flex items-center rounded-lg border border-gray-300 bg-white px-2.5 py-1.5 text-[13px] font-medium text-gray-700 shadow-xs transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                                >
                                    Edit plan
                                </button>
                            ) : null}
                            {data.actions.canSetupPlan && data.targetRace ? (
                                <button
                                    type="button"
                                    onClick={() => handleAction('setup', () => setupRacePlannerPlan(bootstrap.basePath, data.targetRace!.id))}
                                    disabled={pendingAction !== null}
                                    className="inline-flex items-center rounded-lg bg-strava-orange px-2.5 py-1.5 text-[13px] font-semibold text-white shadow-sm transition hover:bg-orange-600 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {data.linkedTrainingPlan ? 'Sync plan' : 'Create real plan'}
                                </button>
                            ) : null}
                            {data.actions.canRegenerateUpcomingSessions && data.targetRace ? (
                                <button
                                    type="button"
                                    onClick={() => handleAction('regenerate', () => regenerateRacePlannerUpcomingSessions(bootstrap.basePath, data.targetRace!.id))}
                                    disabled={pendingAction !== null}
                                    className="inline-flex items-center rounded-lg border border-sky-300 bg-sky-50 px-2.5 py-1.5 text-[13px] font-medium text-sky-700 shadow-xs transition hover:bg-sky-100 disabled:cursor-not-allowed disabled:opacity-60 dark:border-sky-700 dark:bg-sky-950/30 dark:text-sky-200"
                                >
                                    Regenerate upcoming sessions
                                </button>
                            ) : null}
                        </div>
                        <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] font-medium">
                            <a
                                href={buildAppPath(bootstrap.basePath, data.legacyPlannerPath)}
                                className="text-gray-500 transition hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"
                            >
                                Open classic planner
                            </a>
                            <Link to="/training-plans" className="text-gray-500 transition hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">Plan manager</Link>
                            <Link to="/race-events" className="text-gray-500 transition hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">Race events</Link>
                            <Link to="/training-blocks" className="text-gray-500 transition hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">Training blocks</Link>
                            {data.mode === 'plan-preview' && data.plannerSupportsRaceActions ? (
                                <Link to="/race-planner" className="text-gray-500 transition hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">Global race planner</Link>
                            ) : null}
                            {trainingPlanExportUrl ? (
                                <a
                                    href={trainingPlanExportUrl}
                                    download={`training-plan-${data.linkedTrainingPlan?.id ?? 'preview'}.json`}
                                    className="text-gray-500 transition hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"
                                >
                                    Export JSON
                                </a>
                            ) : null}
                            {trainingPlanExportUrl ? (
                                <TrainingPlanAnalysisPromptButton
                                    exportUrl={trainingPlanExportUrl}
                                    planTitle={targetTitle}
                                    plannerUrl={plannerPromptUrl}
                                />
                            ) : null}
                        </div>
                        {data.actions.canChangeStartDay ? (
                            <form
                                className="mt-3 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3 dark:border-gray-800"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    void handleAction('start-date', () =>
                                        updateRacePlannerStartDate(bootstrap.basePath, {
                                            planStartDay,
                                        }),
                                    );
                                }}
                            >
                                <span className="text-xs font-medium text-gray-500 dark:text-gray-400">Planning window</span>
                                <input
                                    type="date"
                                    value={planStartDay}
                                    max={data.targetRace?.day ?? undefined}
                                    onChange={(event) => setPlanStartDay(event.target.value)}
                                    className="ui-input"
                                />
                                <button type="submit" disabled={pendingAction !== null} className="ui-button ui-button-primary disabled:cursor-not-allowed disabled:opacity-60">
                                    Update plan
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
                                        className="ui-button disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        Reset to today
                                    </button>
                                ) : null}
                            </form>
                        ) : null}
                        {actionError ? (
                            <div className="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/30 dark:text-rose-100">
                                {actionError}
                            </div>
                        ) : null}
                </div>

                <dl className="mt-3 grid gap-2.5 border-t border-gray-100 pt-3 dark:border-gray-800 md:grid-cols-2 xl:grid-cols-4">
                    {[
                        {
                            label: 'Planner mode',
                            value: data.mode === 'plan-preview' ? 'Plan detail' : 'Race planner',
                            hint: data.mode === 'plan-preview' ? 'Specific stored training plan.' : 'Next target race and season shape.',
                            toneClassName: 'border-orange-200 bg-orange-50/70 dark:border-orange-800/60 dark:bg-orange-950/20',
                        },
                        {
                            label: 'Forecast',
                            value: data.runningPerformancePrediction ? data.runningPerformancePrediction.projectedThresholdPace : '—',
                            hint: data.runningPerformancePrediction ? 'Projected threshold from current plan.' : 'No running forecast yet.',
                            toneClassName: 'border-emerald-200 bg-emerald-50/70 dark:border-emerald-800/60 dark:bg-emerald-950/20',
                        },
                        {
                            label: 'Recommendations',
                            value: data.recommendations.length,
                            hint: data.recommendations.length === 0 ? 'No weekly nudge right now.' : 'Current-week coaching suggestions.',
                            toneClassName: 'border-sky-200 bg-sky-50/70 dark:border-sky-800/60 dark:bg-sky-950/20',
                        },
                        {
                            label: 'Structure',
                            value: summary ? `${summary.blocks} blocks` : '—',
                            hint: summary ? `${summary.weeks} weeks and ${summary.sessions} proposed sessions.` : 'No structure available yet.',
                            toneClassName: 'border-gray-200 bg-gray-50/80 dark:border-gray-800 dark:bg-gray-950/30',
                        },
                    ].map((item) => (
                        <div key={item.label} className={`rounded-lg border px-3 py-2.5 ${item.toneClassName}`}>
                            <dt className="text-[10px] font-semibold uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">{item.label}</dt>
                            <dd className="mt-1 text-[1.05rem] font-semibold leading-tight text-gray-950 dark:text-gray-100">{item.value}</dd>
                            <div className="mt-1 text-[11px] leading-4 text-gray-600 dark:text-gray-300">{item.hint}</div>
                        </div>
                    ))}
                </dl>
            </section>

            <section className="grid gap-2 md:grid-cols-2 xl:grid-cols-4">
                <PlannerSummaryPill
                    label="Warnings"
                    value={data.warnings.length}
                    hint={data.warnings.length === 0 ? 'No warnings' : 'Proposal warnings'}
                    tone="orange"
                />
                <PlannerSummaryPill
                    label="Displayed races"
                    value={data.displayedUpcomingRaces.length}
                    hint={data.displayedUpcomingRaces.length === 0 ? 'Calendar hidden' : 'Shown here'}
                    tone="emerald"
                />
                <PlannerSummaryPill
                    label="Existing blocks"
                    value={data.existingBlocks.length}
                    hint={data.plannerUsesExistingBlocks ? 'Season anchored' : 'Fresh structure'}
                    tone="blue"
                />
                <PlannerSummaryPill
                    label="Recovery save"
                    value={data.recoverySaveSummary?.missingRecoverySessionCount ?? 0}
                    hint={data.recoverySaveSummary?.hasAnythingToSave ? 'Can write back' : 'Nothing waiting'}
                />
            </section>

            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_18rem]">
                <div className="col-span-full flex rounded-lg border border-gray-200 bg-gray-100 p-0.5 text-sm font-medium xl:hidden" role="tablist" aria-label="Planner sections">
                    <button
                        type="button"
                        role="tab"
                        aria-selected={activePlannerTab === 'content'}
                        onClick={() => setActivePlannerTab('content')}
                        className={`flex-1 rounded-md px-3 py-2 text-center transition ${activePlannerTab === 'content' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'}`}
                    >
                        Plan
                    </button>
                    <button
                        type="button"
                        role="tab"
                        aria-selected={activePlannerTab === 'sidebar'}
                        onClick={() => setActivePlannerTab('sidebar')}
                        className={`flex-1 rounded-md px-3 py-2 text-center transition ${activePlannerTab === 'sidebar' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'}`}
                    >
                        Details
                    </button>
                </div>

                <div className={`${isWidePlannerLayout || activePlannerTab === 'content' ? 'block' : 'hidden'} space-y-6 xl:block`}>
                    {data.warnings.length > 0 ? (
                        <section className="rounded-lg border border-gray-200 bg-white p-3.5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                            <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Warnings</h2>
                            <div className="mt-2.5 space-y-2">
                                {data.warnings.map((warning) => (
                                    <div key={`${warning.type}-${warning.title}`} className={`rounded-lg border p-4 ${buildWarningTone(warning)}`}>
                                        <div className="text-sm font-semibold">{warning.title}</div>
                                        <div className="mt-2 text-sm leading-7 opacity-85">{warning.body}</div>
                                    </div>
                                ))}
                            </div>
                        </section>
                    ) : null}

                    {data.recommendations.length > 0 ? (
                        <section className="rounded-lg border border-gray-200 bg-white p-3.5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Recommendations</h2>
                                </div>
                                <div className="rounded-full bg-sky-50 px-2.5 py-1 text-[10px] font-semibold text-sky-700 dark:bg-sky-950/40 dark:text-sky-100">
                                    {data.recommendations.length} items
                                </div>
                            </div>
                            <div className="mt-2.5 space-y-2">
                                {data.recommendations.map((recommendation) => (
                                    <div key={`${recommendation.type}-${recommendation.title}`} className={`rounded-lg border p-4 ${buildWarningTone(recommendation)}`}>
                                        <div className="text-sm font-semibold">{recommendation.title}</div>
                                        <div className="mt-2 text-sm leading-7 opacity-85">{recommendation.body}</div>
                                        {recommendation.suggestedBlock ? (
                                            <div className="mt-3 rounded-lg border border-white/70 bg-white/70 px-3 py-2 text-xs font-medium text-gray-700 dark:border-gray-800 dark:bg-gray-950/30 dark:text-gray-200">
                                                Suggested block: {recommendation.suggestedBlock.title} · {recommendation.suggestedBlock.durationInWeeks} weeks
                                            </div>
                                        ) : null}
                                    </div>
                                ))}
                            </div>
                        </section>
                    ) : null}

                    {data.runningPerformancePrediction ? (
                        <section className="rounded-lg border border-gray-200 bg-white p-3.5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Running forecast</h2>
                                    <div className="mt-1 text-[11px] text-gray-500 dark:text-gray-400">Current baseline, current trajectory, and ideal plan-end running potential.</div>
                                </div>
                                <div className="rounded-full bg-sky-50 px-2.5 py-1 text-[10px] font-semibold text-sky-700 dark:bg-sky-950/40 dark:text-sky-100">
                                    {data.runningPerformancePrediction.confidenceLabel}
                                </div>
                            </div>
                            <dl className="mt-4 grid gap-2 md:grid-cols-2 xl:grid-cols-4">
                                {[
                                    {
                                        label: 'Current threshold',
                                        value: data.runningPerformancePrediction.currentThresholdPace,
                                        hint: 'Current baseline.',
                                        valueClassName: 'text-orange-950 dark:text-orange-100',
                                        toneClassName: 'border-orange-200 bg-orange-50/70 dark:border-orange-800/60 dark:bg-orange-950/20',
                                    },
                                    {
                                        label: 'Current trajectory',
                                        value: data.runningPerformancePrediction.trajectoryThresholdPace ?? '—',
                                        hint: data.runningPerformancePrediction.trajectoryStatusLabel ?? 'No linked completed runs yet.',
                                        valueClassName: 'text-sky-950 dark:text-sky-100',
                                        toneClassName: 'border-sky-200 bg-sky-50/70 dark:border-sky-800/60 dark:bg-sky-950/20',
                                    },
                                    {
                                        label: 'Projected threshold',
                                        value: data.runningPerformancePrediction.projectedThresholdPace,
                                        hint: 'Ideal end-of-plan threshold.',
                                        valueClassName: 'text-emerald-950 dark:text-emerald-100',
                                        toneClassName: 'border-emerald-200 bg-emerald-50/70 dark:border-emerald-800/60 dark:bg-emerald-950/20',
                                    },
                                    {
                                        label: 'Potential gain',
                                        value: data.runningPerformancePrediction.projectedGainLabel,
                                        hint: data.runningPerformancePrediction.trajectoryGainLabel ? `Trajectory: ${data.runningPerformancePrediction.trajectoryGainLabel}` : 'Trajectory gain appears once sessions link.',
                                        valueClassName: 'text-gray-950 dark:text-gray-100',
                                        toneClassName: 'border-gray-200 bg-gray-50/80 dark:border-gray-800 dark:bg-gray-950/30',
                                    },
                                ].map((item) => (
                                    <div key={item.label} className={`rounded-lg border px-3 py-2 ${item.toneClassName}`}>
                                        <dt className="text-[10px] font-semibold uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">{item.label}</dt>
                                        <dd className={`mt-1 text-[1.05rem] font-semibold leading-tight ${item.valueClassName}`}>{item.value}</dd>
                                        <div className="mt-1 text-[11px] leading-4 text-gray-600 dark:text-gray-300">{item.hint}</div>
                                    </div>
                                ))}
                            </dl>
                            {data.runningPerformancePrediction.benchmarkPredictions.length > 0 ? (
                                <div className="mt-4 overflow-hidden rounded-[18px] border border-gray-200 dark:border-gray-800">
                                    <table className="w-full text-left text-[13px] text-gray-600 dark:text-gray-300">
                                        <thead className="bg-gray-50/90 text-[10px] uppercase tracking-[0.22em] text-gray-500 dark:bg-gray-900/70 dark:text-gray-400">
                                            <tr>
                                                <th className="px-3 py-2.5 font-semibold">Benchmark</th>
                                                <th className="px-3 py-2.5 font-semibold">Current</th>
                                                <th className="px-3 py-2.5 font-semibold">Projected</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {data.runningPerformancePrediction.benchmarkPredictions.map((benchmark) => (
                                                <tr key={benchmark.label} className="border-t border-gray-200 bg-white/70 dark:border-gray-800 dark:bg-gray-950/20">
                                                    <td className="px-3 py-2.5 font-medium text-gray-900 dark:text-white">{benchmark.label}</td>
                                                    <td className="px-3 py-2.5">{formatSeconds(benchmark.currentFinishTimeInSeconds)}</td>
                                                    <td className="px-3 py-2.5 font-semibold text-emerald-700 dark:text-emerald-300">{formatSeconds(benchmark.projectedFinishTimeInSeconds)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : null}
                        </section>
                    ) : null}

                    {data.proposal ? (
                        <section className="rounded-lg border border-gray-200 bg-white p-3.5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">{data.plannerUsesExistingBlocks ? 'Current plan structure' : 'Proposed periodization'}</h2>
                                    <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                        {data.proposal.totalWeeks} weeks · {data.proposal.totalProposedSessions} proposed sessions
                                    </p>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    {data.actions.canSaveRecovery && data.targetRace && data.recoverySaveSummary?.hasAnythingToSave ? (
                                        <>
                                            <button
                                                type="button"
                                                onClick={() => handleAction('recovery', () => saveRacePlannerRecovery(bootstrap.basePath, data.targetRace!.id))}
                                                disabled={pendingAction !== null}
                                                className="inline-flex items-center rounded-lg border border-emerald-300 bg-emerald-50 px-2.5 py-1.5 text-[12px] font-medium text-emerald-700 shadow-xs transition hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-60 dark:border-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-200"
                                            >
                                                Save recovery to calendar
                                            </button>
                                            <span className="text-[10px] text-gray-400 dark:text-gray-500">
                                                {(data.recoverySaveSummary?.missingRecoveryBlockCount ?? 0)} block{(data.recoverySaveSummary?.missingRecoveryBlockCount ?? 0) === 1 ? '' : 's'} · {(data.recoverySaveSummary?.missingRecoverySessionCount ?? 0)} workout{(data.recoverySaveSummary?.missingRecoverySessionCount ?? 0) === 1 ? '' : 's'}
                                            </span>
                                        </>
                                    ) : null}
                                    <div className="rounded-full bg-gray-100 px-2.5 py-1 text-[10px] font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-200">
                                        {data.plannerUsesExistingBlocks ? 'Anchored to existing blocks' : 'Fresh proposal'}
                                    </div>
                                </div>
                            </div>
                            <div className="mt-4 flex h-4.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                                {data.proposal.blocks.map((block) => (
                                    <div
                                        key={`${block.startDay}-${block.title}`}
                                        title={`${block.title} (${block.durationInWeeks} weeks)`}
                                        className="flex items-center justify-center text-[9px] font-semibold uppercase tracking-[0.2em] text-white"
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
                            <div className="mt-3 space-y-2">
                                {data.proposal.blocks.map((block: RacePlannerPreviewBlock) => (
                                    <details key={`${block.startDay}-${block.title}`} className="rounded-lg border border-gray-200 bg-white/90 dark:border-gray-800 dark:bg-gray-900/60">
                                        <summary className="cursor-pointer list-none px-3.5 py-2.5">
                                            <div className="flex flex-col gap-1.5 md:flex-row md:items-start md:justify-between">
                                                <div>
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className={`rounded-full px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-[0.18em] ${buildPhaseTone(block.phase)}`}>
                                                            {block.phaseLabel}
                                                        </span>
                                                        <span className="text-[10px] font-semibold uppercase tracking-[0.18em] text-gray-400 dark:text-gray-500">
                                                            {block.durationInWeeks} weeks
                                                        </span>
                                                    </div>
                                                    <h3 className="mt-1 text-[0.95rem] font-semibold text-gray-900 dark:text-white">{block.title}</h3>
                                                    <p className="mt-0.5 text-[11px] leading-5 text-gray-600 dark:text-gray-300">
                                                        {formatDateRange(block.startDay, block.endDay)}
                                                        {block.focus ? ` · ${block.focus}` : ''}
                                                    </p>
                                                </div>
                                                <div className="flex flex-wrap items-center gap-1.5 text-[10px] font-medium text-gray-500 dark:text-gray-400 md:justify-end">
                                                    <span>{block.totalSessions} sessions</span>
                                                    <span className="text-gray-300 dark:text-gray-600">·</span>
                                                    <span>{block.weeks.length} weeks</span>
                                                    <span className="text-gray-300 dark:text-gray-600">·</span>
                                                    <span>{formatShortDate(block.startDay)}–{formatShortDate(block.endDay)}</span>
                                                </div>
                                            </div>
                                        </summary>
                                        <div className="px-4 pb-4 pt-0 space-y-2">
                                            {block.weeks.map((week) => (
                                                <details key={`${block.title}-${week.weekNumber}-${week.startDay}`} className="rounded-[18px] border border-gray-200 bg-gray-50/80 p-3 dark:border-gray-800 dark:bg-gray-950/20" open={week.weekNumber === block.weeks[0]?.weekNumber}>
                                                    <summary className="cursor-pointer list-none">
                                                        <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                                            <div>
                                                                <div className="flex flex-wrap items-center gap-2">
                                                                    <span className="rounded-full border border-gray-200 bg-white px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                                                        W{week.weekNumber}
                                                                    </span>
                                                                    {week.isManuallyPlanned ? <span className="rounded-full border border-slate-200 bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-700 dark:border-slate-900/40 dark:bg-slate-900/40 dark:text-slate-100">Manual</span> : null}
                                                                    {week.isRecoveryWeek ? <span className="rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-[0.2em] text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-100">Recovery</span> : null}
                                                                    {week.raceSummaryLabel ? <span className="rounded-full border border-rose-200 bg-rose-50 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-[0.2em] text-rose-700 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">{week.raceSummaryLabel}</span> : null}
                                                                </div>
                                                                <div className="mt-2 text-[13px] leading-5 text-gray-600 dark:text-gray-300">
                                                                    {formatDateRange(week.startDay, week.endDay)} · {week.sessionCount} sessions · Load {week.targetLoadPercentage}%
                                                                </div>
                                                            </div>
                                                            <div className="flex flex-wrap gap-1.5 text-[11px] font-medium">
                                                                {week.disciplineDurations.swim ? <span className="rounded-full border border-emerald-200 bg-white px-2.5 py-0.5 text-emerald-700 dark:border-emerald-900/50 dark:bg-gray-950 dark:text-emerald-200">Swim {week.disciplineDurations.swim}</span> : null}
                                                                {week.disciplineDurations.bike ? <span className="rounded-full border border-sky-200 bg-white px-2.5 py-0.5 text-sky-700 dark:border-sky-900/50 dark:bg-gray-950 dark:text-sky-200">Bike {week.disciplineDurations.bike}</span> : null}
                                                                {week.disciplineDurations.run ? <span className="rounded-full border border-amber-200 bg-white px-2.5 py-0.5 text-amber-700 dark:border-amber-900/50 dark:bg-gray-950 dark:text-amber-200">Run {week.disciplineDurations.run}</span> : null}
                                                                {week.projectedThresholdPace ? <span className="rounded-full border border-violet-200 bg-white px-2.5 py-0.5 text-violet-700 dark:border-violet-900/50 dark:bg-gray-950 dark:text-violet-200">Threshold {week.projectedThresholdPace}</span> : null}
                                                                {week.doubleRunDayCount > 0 ? <span className="rounded-full border border-orange-200 bg-white px-2.5 py-0.5 text-orange-700 dark:border-orange-900/50 dark:bg-gray-950 dark:text-orange-200">Double run {week.doubleRunDayCount}×</span> : null}
                                                            </div>
                                                        </div>
                                                    </summary>
                                                    <div className="mt-3 space-y-2">
                                                        {week.sessions.map((session) => (
                                                            <details key={`${session.day}-${session.title}-${session.activityType}`} className="rounded-[16px] border border-gray-200 bg-white/90 p-3 dark:border-gray-800 dark:bg-gray-900/60">
                                                                <summary className="cursor-pointer list-none">
                                                                    <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                                                        <div>
                                                                            <div className="flex flex-wrap items-center gap-2">
                                                                                <span className="rounded-full border border-gray-200 bg-white px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-[0.2em] text-gray-600 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200">
                                                                                    {session.dayLabel}
                                                                                </span>
                                                                                <span className={`rounded-full px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-[0.2em] ${buildIntensityTone(session.targetIntensity)}`}>
                                                                                    {session.targetIntensityLabel}
                                                                                </span>
                                                                                {session.isKeySession ? <span className="rounded-full border border-amber-200 bg-amber-50 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-[0.2em] text-amber-700 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-100">Key</span> : null}
                                                                                {session.isBrickSession ? <span className="rounded-full border border-fuchsia-200 bg-fuchsia-50 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-[0.2em] text-fuchsia-700 dark:border-fuchsia-900/50 dark:bg-fuchsia-950/40 dark:text-fuchsia-100">Brick</span> : null}
                                                                            </div>
                                                                            <h4 className="mt-2 text-[1rem] font-semibold text-gray-900 dark:text-white">{session.title}</h4>
                                                                            <div className="mt-1.5 text-[13px] leading-5 text-gray-600 dark:text-gray-300">
                                                                                {session.activityLabel}
                                                                                {session.durationLabel ? ` · ${session.durationLabel}` : ''}
                                                                                {session.isDoubleRunSession ? ' · Double run day' : ''}
                                                                                {session.isSecondaryRunSession ? ' · 2nd run' : ''}
                                                                            </div>
                                                                        </div>
                                                                        {session.projectedThresholdPace ? (
                                                                            <div className="rounded-[14px] border border-violet-200 bg-violet-50/80 px-3 py-2 text-[13px] text-violet-900 dark:border-violet-900/50 dark:bg-violet-950/30 dark:text-violet-100">
                                                                                <div className="text-[11px] font-semibold uppercase tracking-[0.2em] text-violet-700 dark:text-violet-200">
                                                                                    {session.usesWeekForecastCopy ? 'Week forecast' : 'Week threshold'}
                                                                                </div>
                                                                                <div className="mt-1.5 font-semibold">{session.projectedThresholdPace}</div>
                                                                            </div>
                                                                        ) : null}
                                                                    </div>
                                                                </summary>
                                                                {(session.workoutPreviewRows.length > 0 || session.notes || session.projectedThresholdPace) ? (
                                                                    <div className="mt-3 border-t border-gray-200 pt-3 dark:border-gray-800">
                                                                        {session.projectedThresholdPace ? (
                                                                            <div className="mb-3 rounded-[14px] border border-violet-200 bg-violet-50/80 px-3 py-2 text-[13px] leading-5 text-violet-900 dark:border-violet-900/50 dark:bg-violet-950/30 dark:text-violet-100">
                                                                                {session.usesWeekForecastCopy
                                                                                    ? 'Forecast shown for context. Manual sessions keep their original targets.'
                                                                                    : 'Targets in this session scale from your projected fitness for this week.'}
                                                                            </div>
                                                                        ) : null}
                                                                        {session.workoutPreviewRows.length > 0 ? (
                                                                            <div className="space-y-1.5">
                                                                                {session.workoutPreviewRows.map((row, rowIndex) => (
                                                                                    <div key={`${session.title}-${rowIndex}-${row.headline}`} className="rounded-[14px] border border-gray-200 bg-gray-50/80 px-3 py-2 text-[13px] leading-5 text-gray-700 dark:border-gray-800 dark:bg-gray-950/20 dark:text-gray-200" style={{marginLeft: `${row.depth * 16}px`}}>
                                                                                        <div className="font-medium text-gray-900 dark:text-white">{row.headline}</div>
                                                                                        {row.meta ? <div className="mt-1 text-[11px] font-medium uppercase tracking-[0.16em] text-gray-500 dark:text-gray-400">{row.meta}</div> : null}
                                                                                    </div>
                                                                                ))}
                                                                            </div>
                                                                        ) : null}
                                                                        {session.notes ? <p className="mt-3 text-[13px] leading-5 text-gray-600 dark:text-gray-300">{session.notes}</p> : null}
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

                <div className={`${isWidePlannerLayout || activePlannerTab === 'sidebar' ? 'block' : 'hidden'} space-y-6 xl:block`}>
                    {data.rules ? (
                        <section className="rounded-lg border border-gray-200 bg-white p-3.5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                            <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Profile guidelines</h2>
                            <div className="mt-2.5 space-y-1.5 text-[11px]">
                                {[
                                    ['Ideal plan', `${data.rules.idealPlanWeeks}w (${data.rules.minimumPlanWeeks}–${data.rules.maximumPlanWeeks})`],
                                    ['Taper', `${data.rules.taperWeeks}w`],
                                    ['Sessions/week', `${data.rules.sessionsPerWeekMinimum}–${data.rules.sessionsPerWeekMaximum}`],
                                    ['Hard/week', `${data.rules.hardSessionsPerWeek}`],
                                    ['Long/week', `${data.rules.longSessionsPerWeek}`],
                                    ['Disciplines', data.rules.disciplines.join(', ') || 'Mixed'],
                                ].map(([label, value]) => (
                                    <div key={label} className="flex items-start justify-between gap-3 border-b border-gray-100 py-2 last:border-b-0 dark:border-gray-800">
                                        <div className="text-[10px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">{label}</div>
                                        <div className="text-right text-[13px] font-medium text-gray-900 dark:text-white">{value}</div>
                                    </div>
                                ))}
                            </div>
                        </section>
                    ) : null}

                    {data.runningPerformancePrediction?.basisRows.length ? (
                        <section className="rounded-lg border border-violet-200 bg-violet-50/80 p-3 shadow-sm dark:border-violet-900/50 dark:bg-violet-950/30">
                            <div className="flex items-center justify-between gap-2">
                                <h2 className="text-sm font-semibold text-violet-900 dark:text-violet-100">Prediction basis</h2>
                                <div className="text-[10px] font-semibold uppercase tracking-[0.18em] text-violet-700/80 dark:text-violet-200/80">
                                    Running detail
                                </div>
                            </div>
                            <dl className="mt-2.5 space-y-1.5 text-[11px]">
                                {data.runningPerformancePrediction.basisRows.map((row) => (
                                    <div key={row.label} className="flex items-start justify-between gap-3 border-b border-violet-200/60 py-2 last:border-b-0 dark:border-violet-800/40">
                                        <dt className="text-[10px] font-semibold uppercase tracking-[0.18em] text-violet-700/80 dark:text-violet-200/80">{row.label}</dt>
                                        <dd className="text-right text-[13px] font-medium text-violet-950 dark:text-violet-100">{row.value}</dd>
                                    </div>
                                ))}
                            </dl>
                            <p className="mt-2 text-[11px] leading-5 text-violet-900/80 dark:text-violet-100/80">{data.runningPerformancePrediction.basisNote}</p>
                        </section>
                    ) : null}

                    <RaceCalendar races={data.displayedUpcomingRaces} />

                    {data.existingBlocks.length > 0 ? (
                        <section className="rounded-lg border border-gray-200 bg-white p-3.5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Current blocks</h2>
                                </div>
                                <div className="rounded-full bg-gray-100 px-2.5 py-1 text-[10px] font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-200">
                                    {data.existingBlocks.length}
                                </div>
                            </div>
                            <div className="mt-2 space-y-1.5">
                                {data.existingBlocks.map((block) => (
                                    <div key={block.id} className="rounded-lg border border-gray-100 border-l-2 px-2.5 py-1.5 dark:border-gray-800" style={{borderLeftColor: block.phase === 'base' ? '#34d399' : block.phase === 'build' ? '#38bdf8' : block.phase === 'peak' ? '#a78bfa' : block.phase === 'taper' ? '#fbbf24' : '#94a3b8'}}>
                                        <div className="flex flex-wrap items-center gap-1.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-gray-500 dark:text-gray-400">
                                            <span className={block.phase === 'base' ? 'text-emerald-700 dark:text-emerald-300' : block.phase === 'build' ? 'text-sky-700 dark:text-sky-300' : block.phase === 'peak' ? 'text-violet-700 dark:text-violet-300' : block.phase === 'taper' ? 'text-amber-700 dark:text-amber-300' : 'text-gray-500 dark:text-gray-300'}>
                                                {block.phaseLabel}
                                            </span>
                                        </div>
                                        <div className="mt-1 text-[13px] font-medium text-gray-900 dark:text-white">{block.title ?? block.phaseLabel}</div>
                                        <div className="mt-0.5 text-[11px] leading-4 text-gray-500 dark:text-gray-400">
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
        </div>
    );
}
