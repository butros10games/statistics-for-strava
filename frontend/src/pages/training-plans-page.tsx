import {useCallback, useMemo} from 'react';
import {Link, useNavigate} from 'react-router-dom';
import {buildAppPath, type ReactPreviewBootstrap} from '../lib/bootstrap';
import {
    fetchTrainingPlansPreview,
    type TrainingPlansPreviewPlan,
    type TrainingPlansPreviewRace,
    type TrainingPlansPreviewResponse,
} from '../lib/training-plans-api';
import {useAsyncResource} from '../lib/use-async-resource';

interface TrainingPlansPageProps {
    bootstrap: ReactPreviewBootstrap;
}

function formatDateRange(startDay: string, endDay: string): string {
    const formatter = new Intl.DateTimeFormat('en', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });

    return `${formatter.format(new Date(startDay))} → ${formatter.format(new Date(endDay))}`;
}

function formatShortDate(day: string): string {
    return new Intl.DateTimeFormat('en', {
        month: 'short',
        day: 'numeric',
    }).format(new Date(day));
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

function resolveRaceTitle(race: TrainingPlansPreviewRace): string {
    const formattedProfile = formatLabel(race.profile);

    return race.title || formattedProfile || 'Race event';
}

function resolvePlanTitle(plan: TrainingPlansPreviewPlan): string {
    if (plan.title.trim()) {
        return plan.title;
    }

    if (plan.linkedRace) {
        return resolveRaceTitle(plan.linkedRace);
    }

    return plan.type === 'race' ? 'Race plan' : 'Training plan';
}

function buildStatusBadge(plan: TrainingPlansPreviewPlan): string {
    return plan.status === 'current' ? 'Current' : plan.status === 'upcoming' ? 'Upcoming' : 'Completed';
}

function buildStatusTone(plan: TrainingPlansPreviewPlan): string {
    return plan.status === 'current'
        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-100'
        : plan.status === 'upcoming'
            ? 'bg-sky-100 text-sky-800 dark:bg-sky-950/40 dark:text-sky-100'
            : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-100';
}

function buildTypeTone(plan: TrainingPlansPreviewPlan): string {
    return plan.type === 'race'
        ? 'bg-rose-100 text-rose-700 dark:bg-rose-950/40 dark:text-rose-100'
        : 'bg-violet-100 text-violet-700 dark:bg-violet-950/40 dark:text-violet-100';
}

function buildRacePriorityTone(race: TrainingPlansPreviewRace): string {
    switch (race.priority) {
        case 'a':
            return 'bg-rose-100 text-rose-800 dark:bg-rose-950/40 dark:text-rose-100';
        case 'b':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-100';
        default:
            return 'bg-sky-100 text-sky-800 dark:bg-sky-950/40 dark:text-sky-100';
    }
}

function buildPlanCardTone(plan: TrainingPlansPreviewPlan): string {
    return plan.status === 'current'
        ? 'border-emerald-200 ring-1 ring-emerald-100 dark:border-emerald-700 dark:ring-emerald-900/60'
        : plan.status === 'upcoming'
            ? 'border-sky-200 dark:border-sky-700'
            : 'border-gray-200 dark:border-gray-800';
}

function buildTimelineNodeTone(plan: TrainingPlansPreviewPlan): string {
    return plan.status === 'current'
        ? 'border-emerald-500 bg-emerald-50 text-emerald-600 dark:border-emerald-400 dark:bg-emerald-950/40 dark:text-emerald-300'
        : plan.status === 'upcoming'
            ? 'border-sky-400 bg-sky-50 text-sky-600 dark:border-sky-500 dark:bg-sky-950/40 dark:text-sky-300'
            : 'border-gray-300 bg-gray-50 text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400';
}

function buildContinuityFooterTone(plan: TrainingPlansPreviewPlan): string {
    if (!plan.continuity) {
        return '';
    }

    switch (plan.continuity.kind) {
        case 'gap':
            return 'border-amber-200 bg-amber-50/60 dark:border-amber-800/70 dark:bg-amber-950/30';
        case 'overlap':
            return 'border-rose-200 bg-rose-50/60 dark:border-rose-800/70 dark:bg-rose-950/30';
        default:
            return 'border-emerald-200 bg-emerald-50/60 dark:border-emerald-800/70 dark:bg-emerald-950/30';
    }
}

function buildContinuitySummary(plan: TrainingPlansPreviewPlan): string {
    if (!plan.continuity) {
        return '';
    }

    if (plan.continuity.kind === 'gap') {
        return `Gap of ${plan.continuity.days} day${plan.continuity.days === 1 ? '' : 's'} before ${plan.continuity.nextPlanTitle}`;
    }

    if (plan.continuity.kind === 'overlap') {
        return `Overlap of ${plan.continuity.days} day${plan.continuity.days === 1 ? '' : 's'} with ${plan.continuity.nextPlanTitle}`;
    }

    return `Perfect handoff → ${plan.continuity.nextPlanTitle}`;
}

function buildPlannerRoute(path: string): string {
    return path.startsWith('/') ? path : `/${path}`;
}

function TrainingPlansLoadingState() {
    return (
        <section className="ui-section">
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {Array.from({length: 6}).map((_, index) => (
                    <div key={index} className="animate-pulse rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                        <div className="h-3 w-16 rounded-full bg-gray-200 dark:bg-gray-800" />
                        <div className="mt-4 h-6 w-2/3 rounded-full bg-gray-200 dark:bg-gray-800" />
                        <div className="mt-3 h-4 w-1/2 rounded-full bg-gray-200 dark:bg-gray-800" />
                        <div className="mt-5 space-y-2">
                            <div className="h-4 rounded-full bg-gray-100 dark:bg-gray-800" />
                            <div className="h-4 rounded-full bg-gray-100 dark:bg-gray-800" />
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}

export function TrainingPlansPage({bootstrap}: TrainingPlansPageProps) {
    const isPreview = bootstrap.experience === 'preview';
    const navigate = useNavigate();
    const loadTrainingPlans = useCallback(
        (signal: AbortSignal): Promise<TrainingPlansPreviewResponse> => fetchTrainingPlansPreview(bootstrap.basePath, signal),
        [bootstrap.basePath],
    );

    const {data, loading, error, reload} = useAsyncResource(loadTrainingPlans);

    const activePlan = useMemo(
        () => data?.plans.find((plan) => plan.id === data.activePlanId) ?? null,
        [data],
    );
    const hasContinuityIssues = (data?.stats.gapCount ?? 0) + (data?.stats.overlapCount ?? 0) > 0;

    return (
        <div className="space-y-6 pb-6">
            <section className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="min-w-0">
                    <h1 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">Plan manager</h1>
                    <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Chain training blocks, anchor races, and keep your season on track.</p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        onClick={() => navigate('/training-plan-editor')}
                        className="ui-button ui-button-primary"
                    >
                        <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        New plan
                    </button>
                    <Link to="/race-planner" className="ui-button">
                        <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" d="M3 3v1.5M3 21v-6m0 0 2.77-.693a9 9 0 0 1 6.208.682l.108.054a9 9 0 0 0 6.086.71l3.114-.732a48.524 48.524 0 0 1-.005-10.499l-3.11.732a9 9 0 0 1-6.085-.711l-.108-.054a9 9 0 0 0-6.208-.682L3 4.5M3 15V4.5" />
                        </svg>
                        Race planner
                    </Link>
                    <Link to="/race-events" className="ui-button">
                        <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 0 1-.982-3.172M9.497 14.25a7.454 7.454 0 0 0 .981-3.172" />
                        </svg>
                        Race events
                    </Link>
                    {isPreview ? (
                        <a href={buildAppPath(bootstrap.basePath, 'training-plans')} className="ui-button">
                            <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" d="M13.5 6H7.5A2.25 2.25 0 0 0 5.25 8.25v8.25A2.25 2.25 0 0 0 7.5 18.75h8.25A2.25 2.25 0 0 0 18 16.5v-6" />
                                <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" d="M15 5.25h3.75V9m0-3.75-8.25 8.25" />
                            </svg>
                            Classic page
                        </a>
                    ) : null}
                </div>
            </section>

            {loading ? <TrainingPlansLoadingState /> : null}

            {!loading && error ? (
                <section className="ui-section">
                    <h2 className="text-lg font-semibold text-gray-900 dark:text-white">This route could not load training plans.</h2>
                    <p className="mt-2 max-w-3xl text-sm leading-7 text-gray-600 dark:text-gray-300">
                        {error}. This is usually a sign that the session expired, the backend route is unavailable, or the frontend build is out of date.
                    </p>
                    <div className="mt-4 flex flex-wrap gap-2">
                        <a
                            href={buildAppPath(bootstrap.basePath, 'training-plans')}
                            className="ui-button"
                        >
                            Open the live route
                        </a>
                        <button
                            type="button"
                            onClick={reload}
                            className="ui-button"
                        >
                            Retry loading
                        </button>
                    </div>
                </section>
            ) : null}

            {!loading && !error && data ? (
                <>
                    {data.stats.totalPlans === 0 ? (
                        <section className="relative overflow-hidden rounded-xl border border-dashed border-gray-300 bg-gradient-to-br from-gray-50 to-white px-6 py-16 text-center dark:border-gray-700 dark:from-gray-900 dark:to-gray-950">
                            <div className="relative mx-auto max-w-md">
                                <div className="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-2xl bg-orange-50 text-strava-orange shadow-sm dark:bg-orange-950/40">
                                    <svg className="h-8 w-8" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" d="M8 7V3m8 4V3m-9 8h10m-12 9h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2Z" />
                                    </svg>
                                </div>
                                <h2 className="text-lg font-bold text-gray-900 dark:text-white">Start your season plan</h2>
                                <p className="mx-auto mt-2 max-w-sm text-sm text-gray-500 dark:text-gray-400">Create your first training block or race build. Stack them together to map out the months ahead.</p>
                                <div className="mt-6 flex justify-center">
                                    <button type="button" onClick={() => navigate('/training-plan-editor')} className="ui-button ui-button-primary">
                                        <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4.5v15m7.5-7.5h-15" />
                                        </svg>
                                        Create first plan
                                    </button>
                                </div>
                            </div>
                        </section>
                    ) : (
                        <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <article className="group relative overflow-hidden rounded-xl border border-gray-200 bg-white p-5 shadow-sm transition hover:shadow-md dark:border-gray-800 dark:bg-gray-900">
                                <div className="absolute -right-6 -top-6 h-24 w-24 rounded-full bg-gradient-to-br from-emerald-100 to-transparent opacity-50 dark:from-emerald-900/50" />
                                <div className="relative">
                                    <div className="flex items-center gap-2">
                                        <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-100 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-300">
                                            <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3.75 13.5 14.25 2.25 12 10.5h8.25L9.75 21.75 12 13.5H3.75Z" />
                                            </svg>
                                        </div>
                                        <h2 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Focus plan</h2>
                                    </div>
                                    {activePlan ? (
                                        <>
                                            <p className="mt-3 text-lg font-bold text-gray-900 dark:text-white">{resolvePlanTitle(activePlan)}</p>
                                            <p className="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{formatDateRange(activePlan.startDay, activePlan.endDay)}</p>
                                            <div className="mt-3">
                                                <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${buildStatusTone(activePlan)}`}>
                                                    {activePlan.status === 'current' ? <span className="mr-1.5 h-1.5 w-1.5 rounded-full bg-current opacity-80" /> : null}
                                                    {activePlan.status === 'current' ? 'In progress' : activePlan.status === 'upcoming' ? 'Next up' : 'Most recent'}
                                                </span>
                                            </div>
                                        </>
                                    ) : (
                                        <p className="mt-3 text-sm text-gray-400 dark:text-gray-500">No active plan</p>
                                    )}
                                </div>
                            </article>

                            <article className="group relative overflow-hidden rounded-xl border border-gray-200 bg-white p-5 shadow-sm transition hover:shadow-md dark:border-gray-800 dark:bg-gray-900">
                                <div className={`absolute -right-6 -top-6 h-24 w-24 rounded-full bg-gradient-to-br ${hasContinuityIssues ? 'from-amber-100 dark:from-amber-900/50' : 'from-emerald-100 dark:from-emerald-900/50'} to-transparent opacity-50`} />
                                <div className="relative">
                                    <div className="flex items-center gap-2">
                                        <div className={`flex h-8 w-8 items-center justify-center rounded-lg ${hasContinuityIssues ? 'bg-amber-100 text-amber-600 dark:bg-amber-950/50 dark:text-amber-300' : 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-300'}`}>
                                            <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
                                            </svg>
                                        </div>
                                        <h2 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Continuity</h2>
                                    </div>
                                    <p className={`mt-3 text-3xl font-bold ${hasContinuityIssues ? 'text-amber-600 dark:text-amber-300' : 'text-emerald-600 dark:text-emerald-300'}`}>
                                        {hasContinuityIssues ? data.stats.gapCount + data.stats.overlapCount : '✓'}
                                    </p>
                                    <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                        {hasContinuityIssues ? `${data.stats.gapCount} gaps, ${data.stats.overlapCount} overlaps` : 'No gaps or overlaps'}
                                    </p>
                                    {data.stats.nextSuggestedStartDay ? (
                                        <p className="mt-2 text-xs text-gray-400 dark:text-gray-500">Next start: <span className="font-medium text-gray-700 dark:text-gray-300">{data.stats.nextSuggestedStartDay}</span></p>
                                    ) : null}
                                </div>
                            </article>

                            <article className="group relative overflow-hidden rounded-xl border border-gray-200 bg-white p-5 shadow-sm transition hover:shadow-md sm:col-span-2 lg:col-span-1 dark:border-gray-800 dark:bg-gray-900">
                                <div className="absolute -right-6 -top-6 h-24 w-24 rounded-full bg-gradient-to-br from-violet-100 to-transparent opacity-50 dark:from-violet-900/50" />
                                <div className="relative">
                                    <div className="flex items-center gap-2">
                                        <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-violet-100 text-violet-600 dark:bg-violet-950/50 dark:text-violet-300">
                                            <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6Zm0 9.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25A2.25 2.25 0 0 1 13.5 8.25V6Zm0 9.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
                                            </svg>
                                        </div>
                                        <h2 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Plan mix</h2>
                                    </div>
                                    <div className="mt-4 flex items-baseline gap-6">
                                        <div className="text-center">
                                            <p className="text-2xl font-bold text-gray-900 dark:text-white">{data.stats.racePlans}</p>
                                            <p className="text-xs text-gray-500 dark:text-gray-400">Race</p>
                                        </div>
                                        <div className="text-center">
                                            <p className="text-2xl font-bold text-gray-900 dark:text-white">{data.stats.trainingPlans}</p>
                                            <p className="text-xs text-gray-500 dark:text-gray-400">Training</p>
                                        </div>
                                        {data.unassignedUpcomingRaces.length > 0 ? (
                                            <div className="text-center">
                                                <p className="text-2xl font-bold text-amber-600 dark:text-amber-300">{data.unassignedUpcomingRaces.length}</p>
                                                <p className="text-xs text-gray-500 dark:text-gray-400">Unlinked</p>
                                            </div>
                                        ) : null}
                                    </div>
                                </div>
                            </article>
                        </section>
                    )}

                    {data.unassignedUpcomingRaces.length > 0 ? (
                        <section className="rounded-xl border border-amber-200 bg-gradient-to-r from-amber-50 to-white p-4 dark:border-amber-800/50 dark:from-amber-950/30 dark:to-gray-950">
                            <div className="flex items-start gap-3">
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-300">
                                    <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" d="M12 9v3.75m0 3h.008v.008H12v-.008Zm-9.303.368c-.866 1.5.217 3.382 1.948 3.382h14.71c1.73 0 2.813-1.882 1.948-3.382L13.949 3.382c-.866-1.5-3.032-1.5-3.898 0L2.697 16.118Z" />
                                    </svg>
                                </div>
                                <div className="min-w-0 flex-1">
                                    <h2 className="text-sm font-semibold text-amber-900 dark:text-amber-200">Races without a plan</h2>
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        {data.unassignedUpcomingRaces.map((race) => (
                                            <button
                                                key={race.id}
                                                type="button"
                                                onClick={() => navigate(`/training-plan-editor?targetRaceEventId=${race.id}`)}
                                                className="group inline-flex items-center gap-2 rounded-lg border border-amber-200 bg-white px-3 py-2 text-sm transition hover:border-amber-300 hover:bg-amber-50 dark:border-amber-700 dark:bg-gray-900 dark:hover:bg-amber-950/30"
                                            >
                                                <span className="font-medium text-gray-900 dark:text-white">{resolveRaceTitle(race)}</span>
                                                <span className="text-xs text-gray-500 dark:text-gray-400">{formatShortDate(race.day)}</span>
                                                <span className={`rounded px-1 py-px text-[10px] font-semibold ${buildRacePriorityTone(race)}`}>{race.priority.toUpperCase()}</span>
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        </section>
                    ) : null}

                    {data.plans.length > 0 ? (
                        <section className="relative">
                            <div className="absolute left-[23px] top-0 hidden h-full w-px bg-gradient-to-b from-gray-200 via-gray-200 to-transparent lg:block dark:from-gray-700 dark:via-gray-700" />

                            <div className="space-y-4">
                                {data.plans.map((plan) => {
                                    const showWindowRaces = plan.windowRaces.length > 1 || (!plan.linkedRace && plan.windowRaces.length > 0);

                                    return (
                                        <div key={plan.id} className="group relative flex gap-4">
                                            <div className="relative z-10 hidden shrink-0 lg:block">
                                                <div className={`flex h-12 w-12 items-center justify-center rounded-xl border-2 shadow-sm transition group-hover:shadow-md ${buildTimelineNodeTone(plan)}`}>
                                                    {plan.type === 'race' ? (
                                                        <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                            <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.5" d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 0 1-.982-3.172M9.497 14.25a7.454 7.454 0 0 0 .981-3.172" />
                                                        </svg>
                                                    ) : (
                                                        <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                            <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.5" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                                                        </svg>
                                                    )}
                                                </div>
                                            </div>

                                            <article className={`min-w-0 flex-1 overflow-hidden rounded-xl border bg-white shadow-sm transition hover:shadow-md dark:bg-gray-900 ${buildPlanCardTone(plan)}`}>
                                                <div className="flex flex-col gap-3 p-4 lg:flex-row lg:items-start lg:justify-between lg:gap-5">
                                                    <div className="min-w-0 flex-1 space-y-2">
                                                        <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                                            <h3 className="text-base font-bold text-gray-900 dark:text-white">{resolvePlanTitle(plan)}</h3>
                                                            <div className="flex items-center gap-1.5">
                                                                <span className={`inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold ${buildStatusTone(plan)}`}>{buildStatusBadge(plan)}</span>
                                                                <span className={`inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold ${buildTypeTone(plan)}`}>{plan.type === 'race' ? 'Race' : 'Training'}</span>
                                                            </div>
                                                        </div>

                                                        <p className="text-sm text-gray-500 dark:text-gray-400">
                                                            {formatDateRange(plan.startDay, plan.endDay)}
                                                            <span className="mx-1 text-gray-300 dark:text-gray-600">·</span>
                                                            {plan.durationWeeks}w
                                                            {plan.linkedRace ? (
                                                                <>
                                                                    <span className="mx-1 text-gray-300 dark:text-gray-600">·</span>
                                                                    {formatShortDate(plan.linkedRace.day)}
                                                                </>
                                                            ) : null}
                                                        </p>

                                                        {plan.discipline || plan.objective ? (
                                                            <div className="flex flex-wrap items-center gap-1.5">
                                                                {plan.discipline ? <span className="rounded border border-gray-200 bg-gray-50 px-1.5 py-0.5 text-[11px] font-medium text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">{formatLabel(plan.discipline)}</span> : null}
                                                                {plan.objective ? <span className="rounded border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[11px] font-medium text-amber-700 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">{formatLabel(plan.objective)}</span> : null}
                                                            </div>
                                                        ) : null}

                                                        {plan.scheduleHighlights.length > 0 || plan.performanceHighlights.length > 0 || showWindowRaces ? (
                                                            <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-[11px] text-gray-500 dark:text-gray-400">
                                                                {plan.scheduleHighlights.length > 0 ? (
                                                                    <span className="inline-flex items-center gap-1">
                                                                        <span className="font-semibold text-gray-400 dark:text-gray-500">Schedule</span>
                                                                        {plan.scheduleHighlights.map((highlight) => (
                                                                            <span key={highlight} className="rounded bg-sky-100 px-1 py-px font-medium text-sky-700 dark:bg-sky-950/40 dark:text-sky-200">{highlight}</span>
                                                                        ))}
                                                                    </span>
                                                                ) : null}
                                                                {plan.performanceHighlights.length > 0 ? (
                                                                    <span className="inline-flex items-center gap-1">
                                                                        <span className="font-semibold text-gray-400 dark:text-gray-500">Metrics</span>
                                                                        {plan.performanceHighlights.map((highlight) => (
                                                                            <span key={highlight} className="rounded bg-emerald-100 px-1 py-px font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-200">{highlight}</span>
                                                                        ))}
                                                                    </span>
                                                                ) : null}
                                                                {showWindowRaces ? (
                                                                    <span className="inline-flex items-center gap-1">
                                                                        <span className="font-semibold text-gray-400 dark:text-gray-500">Races</span>
                                                                        {plan.windowRaces.map((race) => (
                                                                            <span key={race.id} className={`rounded px-1 py-px font-medium ${buildRacePriorityTone(race)}`}>
                                                                                {race.priority.toUpperCase()} {resolveRaceTitle(race)}
                                                                            </span>
                                                                        ))}
                                                                    </span>
                                                                ) : null}
                                                            </div>
                                                        ) : null}

                                                        {plan.linkedRaceState === 'missing' ? (
                                                            <p className="flex items-center gap-1 text-xs font-medium text-red-600 dark:text-red-400">
                                                                <svg className="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                                    <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v3.75m0 3h.008v.008H12v-.008Zm9-3.758a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                                                </svg>
                                                                Linked race no longer exists
                                                            </p>
                                                        ) : null}

                                                        {plan.linkedRaceState === 'outside-window' && plan.linkedRace ? (
                                                            <p className="flex items-center gap-1 text-xs font-medium text-amber-600 dark:text-amber-400">
                                                                <svg className="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                                    <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v3.75m0 3h.008v.008H12v-.008Zm9-3.758a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                                                </svg>
                                                                Race falls outside plan window
                                                            </p>
                                                        ) : null}

                                                        {plan.notes ? (
                                                            <p className="whitespace-pre-line text-sm leading-relaxed text-gray-600 dark:text-gray-400">{plan.notes}</p>
                                                        ) : null}
                                                    </div>

                                                    <div className="flex shrink-0 items-center gap-1.5 lg:flex-col lg:gap-1">
                                                        <Link
                                                            to={buildPlannerRoute(plan.racePlannerPath)}
                                                            className="flex h-10 w-10 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-500 transition hover:border-gray-300 hover:bg-gray-50 hover:text-gray-700 lg:h-9 lg:w-9 dark:border-gray-700 dark:bg-gray-900 dark:hover:border-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                                                            title="Open in race planner"
                                                        >
                                                            <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                                <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.5" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                                            </svg>
                                                        </Link>
                                                        <button
                                                            type="button"
                                                            onClick={() => navigate(`/training-plan-editor?trainingPlanId=${plan.id}`)}
                                                            className="flex h-10 w-10 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-500 transition hover:border-gray-300 hover:bg-gray-50 hover:text-gray-700 lg:h-9 lg:w-9 dark:border-gray-700 dark:bg-gray-900 dark:hover:border-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                                                            title="Edit plan"
                                                        >
                                                            <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                                <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.5" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                                                            </svg>
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => navigate(`/training-plan-editor?afterTrainingPlanId=${plan.id}`)}
                                                            className="flex h-10 w-10 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-500 transition hover:border-gray-300 hover:bg-gray-50 hover:text-gray-700 lg:h-9 lg:w-9 dark:border-gray-700 dark:bg-gray-900 dark:hover:border-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                                                            title="Create next plan"
                                                        >
                                                            <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                                <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.5" d="M12 4.5v15m7.5-7.5h-15" />
                                                            </svg>
                                                        </button>
                                                    </div>
                                                </div>

                                                {plan.continuity ? (
                                                    <div className={`border-t px-4 py-2 lg:px-5 ${buildContinuityFooterTone(plan)}`}>
                                                        <p className="text-xs font-medium">
                                                            <span className={plan.continuity.kind === 'gap' ? 'text-amber-700 dark:text-amber-300' : plan.continuity.kind === 'overlap' ? 'text-rose-700 dark:text-rose-300' : 'text-emerald-700 dark:text-emerald-300'}>
                                                                {buildContinuitySummary(plan)}
                                                            </span>
                                                        </p>
                                                    </div>
                                                ) : null}
                                            </article>
                                        </div>
                                    );
                                })}
                            </div>
                        </section>
                    ) : null}
                </>
            ) : null}
        </div>
    );
}
