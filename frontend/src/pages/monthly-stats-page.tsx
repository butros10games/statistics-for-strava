import {useCallback} from 'react';
import {Link, useParams} from 'react-router-dom';
import {buildAppPath, type ReactPreviewBootstrap} from '../lib/bootstrap';
import {
    fetchMonthlyStatsPreview,
    type MonthlyStatsPreviewActivity,
    type MonthlyStatsPreviewDay,
    type MonthlyStatsPreviewPlannedSession,
    type MonthlyStatsPreviewRaceEvent,
    type MonthlyStatsPreviewResponse,
    type MonthlyStatsPreviewTrainingBlock,
} from '../lib/monthly-stats-preview-api';
import {buildPlannedSessionEditorPath as buildPlannedSessionEditorPreviewPath} from '../lib/planned-session-preview-api';
import {useAsyncResource} from '../lib/use-async-resource';

interface MonthlyStatsPageProps {
    bootstrap: ReactPreviewBootstrap;
}

const toolbarButtonClass = 'inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-[13px] font-medium text-gray-700 shadow-xs transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600';
const toolbarPrimaryButtonClass = 'inline-flex items-center gap-2 rounded-lg bg-strava-orange px-2.5 py-1.5 text-[13px] font-semibold text-white shadow-sm transition hover:bg-orange-600';

function normaliseMonthId(monthId?: string): string | undefined {
    return monthId?.replace(/^month-/, '');
}

function formatNumber(value: number, maximumFractionDigits = 0): string {
    return new Intl.NumberFormat('en-US', {
        maximumFractionDigits,
        minimumFractionDigits: 0,
    }).format(value);
}

function formatShortDate(value: string): string {
    return new Intl.DateTimeFormat('en', {
        month: 'short',
        day: 'numeric',
    }).format(new Date(value));
}

function formatDateRange(from: string, to: string): string {
    return `${formatShortDate(from)} → ${formatShortDate(to)}`;
}

function formatRequestedAt(value: string): string {
    return new Intl.DateTimeFormat('en', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function formatDuration(totalSeconds: number): string {
    if (totalSeconds <= 0) {
        return '0m';
    }

    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.round((totalSeconds % 3600) / 60);

    if (hours === 0) {
        return `${minutes}m`;
    }

    if (minutes === 0) {
        return `${hours}h`;
    }

    return `${hours}h ${minutes}m`;
}

function buildActivityTone(activityType: string): string {
    switch (activityType) {
        case 'Ride':
            return 'border-emerald-200 bg-emerald-50/85 text-emerald-900 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-100';
        case 'Run':
            return 'border-orange-200 bg-orange-50/85 text-orange-900 dark:border-orange-900/50 dark:bg-orange-950/30 dark:text-orange-100';
        case 'WaterSports':
            return 'border-sky-200 bg-sky-50/85 text-sky-900 dark:border-sky-900/50 dark:bg-sky-950/30 dark:text-sky-100';
        default:
            return 'border-gray-200 bg-white/85 text-gray-800 dark:border-gray-800 dark:bg-gray-900/40 dark:text-gray-100';
    }
}

function buildPriorityTone(priority: string | null): string {
    switch (priority) {
        case 'a':
            return 'border-rose-200 bg-rose-50/90 text-rose-900 dark:border-rose-900/50 dark:bg-rose-950/30 dark:text-rose-100';
        case 'b':
            return 'border-amber-200 bg-amber-50/90 text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-100';
        case 'c':
            return 'border-sky-200 bg-sky-50/90 text-sky-900 dark:border-sky-900/50 dark:bg-sky-950/30 dark:text-sky-100';
        default:
            return 'border-gray-200 bg-white/85 text-gray-800 dark:border-gray-800 dark:bg-gray-900/40 dark:text-gray-100';
    }
}

function buildPhaseTone(phase: string | null): string {
    switch (phase) {
        case 'base':
            return 'border-emerald-200 bg-emerald-50/90 text-emerald-900 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-100';
        case 'build':
            return 'border-sky-200 bg-sky-50/90 text-sky-900 dark:border-sky-900/50 dark:bg-sky-950/30 dark:text-sky-100';
        case 'peak':
            return 'border-violet-200 bg-violet-50/90 text-violet-900 dark:border-violet-900/50 dark:bg-violet-950/30 dark:text-violet-100';
        case 'taper':
            return 'border-amber-200 bg-amber-50/90 text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-100';
        case 'recovery':
            return 'border-slate-200 bg-slate-50/90 text-slate-900 dark:border-slate-800 dark:bg-slate-900/40 dark:text-slate-100';
        default:
            return 'border-gray-200 bg-white/85 text-gray-800 dark:border-gray-800 dark:bg-gray-900/40 dark:text-gray-100';
    }
}

function buildCueTone(tone: string): string {
    switch (tone) {
        case 'positive':
            return 'border-emerald-200 bg-emerald-50/90 text-emerald-900 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-100';
        case 'warning':
            return 'border-amber-200 bg-amber-50/90 text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-100';
        default:
            return 'border-sky-200 bg-sky-50/90 text-sky-900 dark:border-sky-900/50 dark:bg-sky-950/30 dark:text-sky-100';
    }
}

function buildMonthPreviewPath(monthId: string): string {
    return `/monthly-stats/${monthId}`;
}

function ActivityRow({activity, distanceSymbol, elevationSymbol}: {activity: MonthlyStatsPreviewActivity; distanceSymbol: string; elevationSymbol: string}) {
    return (
        <div className={`rounded-lg border px-2.5 py-1.5 ${buildActivityTone(activity.activityType)}`}>
            <div className="text-[13px] font-medium leading-5">{activity.name}</div>
            <div className="mt-0.5 text-[11px] leading-4 opacity-80">
                {activity.label} · {formatNumber(activity.distance, 1)} {distanceSymbol} · {formatNumber(activity.elevation)} {elevationSymbol}
            </div>
        </div>
    );
}

function PlannedSessionRow({session, day}: {session: MonthlyStatsPreviewPlannedSession; day: string}) {
    const accentClass = session.isKeySession
        ? 'border-orange-200 bg-orange-50/90 text-orange-900 dark:border-orange-900/50 dark:bg-orange-950/30 dark:text-orange-100'
        : session.isBrickSession
            ? 'border-fuchsia-200 bg-fuchsia-50/90 text-fuchsia-900 dark:border-fuchsia-900/50 dark:bg-fuchsia-950/30 dark:text-fuchsia-100'
            : 'border-gray-200 bg-white/85 text-gray-800 dark:border-gray-800 dark:bg-gray-900/40 dark:text-gray-100';

    return (
        <Link to={buildPlannedSessionEditorPreviewPath({plannedSessionId: session.id, day})} className={`block rounded-lg border px-2.5 py-1.5 transition hover:translate-y-[-1px] ${accentClass}`}>
            <div className="flex items-center justify-between gap-2">
                <div className="text-[13px] font-medium leading-5">{session.title}</div>
                {session.targetIntensityLabel ? (
                    <span className="rounded-full border border-white/70 bg-white/70 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wider dark:border-gray-800 dark:bg-gray-950/40">
                        {session.targetIntensityLabel}
                    </span>
                ) : null}
            </div>
            <div className="mt-0.5 text-[11px] leading-4 opacity-80">
                {session.label}
                {session.durationInSeconds ? ` · ${formatDuration(session.durationInSeconds)}` : ''}
                {typeof session.estimatedLoad === 'number' ? ` · Load ${formatNumber(session.estimatedLoad, 1)}` : ''}
            </div>
        </Link>
    );
}

function RaceEventRow({raceEvent}: {raceEvent: MonthlyStatsPreviewRaceEvent}) {
    return (
        <div className={`rounded-lg border px-2.5 py-1.5 ${buildPriorityTone(raceEvent.priority)}`}>
            <div className="flex items-center justify-between gap-2">
                <div className="text-[13px] font-medium leading-5">{raceEvent.title}</div>
                <span className="rounded-full border border-white/70 bg-white/70 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wider dark:border-gray-800 dark:bg-gray-950/40">
                    {raceEvent.priority.toUpperCase()}
                </span>
            </div>
            <div className="mt-0.5 text-[11px] leading-4 opacity-80">
                {raceEvent.profileLabel}
                {raceEvent.location ? ` · ${raceEvent.location}` : ''}
                {typeof raceEvent.countdownDays === 'number' ? ` · D-${Math.max(0, raceEvent.countdownDays)}` : ''}
            </div>
        </div>
    );
}

function TrainingBlockRow({block}: {block: MonthlyStatsPreviewTrainingBlock}) {
    return (
        <div className={`rounded-lg border px-2.5 py-1.5 ${buildPhaseTone(block.phase)}`}>
            <div className="text-[13px] font-medium leading-5">{block.title}</div>
            <div className="mt-0.5 text-[11px] leading-4 opacity-80">
                {formatDateRange(block.startDay, block.endDay)}
                {block.focus ? ` · ${block.focus}` : ''}
            </div>
        </div>
    );
}

function CalendarDayCard({
    day,
    distanceSymbol,
    elevationSymbol,
}: {
    day: MonthlyStatsPreviewDay;
    distanceSymbol: string;
    elevationSymbol: string;
}) {
    return (
        <article className={`min-h-[13rem] rounded-lg border p-2.5 ${day.isCurrentMonth ? 'border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950' : 'border-gray-200/70 bg-gray-50/75 text-gray-400 dark:border-gray-800/60 dark:bg-gray-900/25 dark:text-gray-500'}`}>
            <div className="flex items-start justify-between gap-2">
                <div>
                    <div className={`inline-flex h-7 min-w-7 items-center justify-center rounded-full px-2 text-[13px] font-semibold ${day.isToday ? 'bg-strava-orange text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-100'}`}>
                        {day.dayNumber}
                    </div>
                    <div className="mt-1.5 text-[10px] uppercase tracking-wider">
                        {new Date(day.date).toLocaleDateString('en', {weekday: 'short'})}
                    </div>
                </div>
                <div className="flex flex-wrap justify-end gap-1">
                    <Link
                        to={buildPlannedSessionEditorPreviewPath({day: day.date})}
                        className="rounded-lg border border-gray-200 bg-white px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                    >
                        Plan
                    </Link>
                    {day.trainingBlockPhase ? (
                        <span className={`rounded-full border px-2 py-1 text-[10px] font-semibold uppercase tracking-wider ${buildPhaseTone(day.trainingBlockPhase)}`}>
                            {day.trainingBlockPhase}
                        </span>
                    ) : null}
                    {day.racePriority ? (
                        <span className={`rounded-full border px-2 py-1 text-[10px] font-semibold uppercase tracking-wider ${buildPriorityTone(day.racePriority)}`}>
                            {day.racePriority.toUpperCase()} race
                        </span>
                    ) : null}
                </div>
            </div>

            <div className="mt-2.5 space-y-1.5">
                {day.raceEvents.map((raceEvent) => (
                    <RaceEventRow key={raceEvent.id} raceEvent={raceEvent} />
                ))}
                {day.plannedSessions.map((session) => (
                    <PlannedSessionRow key={session.id} session={session} day={day.date} />
                ))}
                {day.activities.map((activity) => (
                    <ActivityRow key={activity.id} activity={activity} distanceSymbol={distanceSymbol} elevationSymbol={elevationSymbol} />
                ))}
                {day.trainingBlocks
                    .filter((block) => !day.trainingBlockPhase || block.phase !== day.trainingBlockPhase)
                    .map((block) => (
                        <TrainingBlockRow key={block.id} block={block} />
                    ))}
            </div>
        </article>
    );
}

function SummaryPill({label, value}: {label: string; value: string}) {
    return (
        <div className="rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-1.5 text-[13px] dark:border-gray-800 dark:bg-gray-900/60">
            <span className="font-semibold text-gray-900 dark:text-white">{value}</span>
            <span className="ml-2 text-gray-500 dark:text-gray-400">{label}</span>
        </div>
    );
}

export function MonthlyStatsPage({bootstrap}: MonthlyStatsPageProps) {
    const {monthId} = useParams<{monthId?: string}>();
    const selectedMonthId = normaliseMonthId(monthId);
    const unitSystem = window.statisticsForStrava.unitSystem;

    const loadMonthlyStats = useCallback(
        (signal: AbortSignal): Promise<MonthlyStatsPreviewResponse> => fetchMonthlyStatsPreview(bootstrap.basePath, selectedMonthId, signal),
        [bootstrap.basePath, selectedMonthId],
    );

    const {data, loading, error, reload} = useAsyncResource(loadMonthlyStats);

    return (
        <div className="space-y-6 pb-6">
            <section className="ui-section">
                <div className="flex flex-col gap-2.5 xl:flex-row xl:items-start xl:justify-between">
                    <div className="min-w-0">
                        <h1 className="text-[1.8rem] font-bold tracking-tight text-gray-900 dark:text-white">Monthly stats</h1>
                        <p className="mt-1 max-w-3xl text-[13px] text-gray-500 dark:text-gray-400">Calendar-first month view with planned sessions, races, blocks, and completed activities in one place.</p>
                    </div>
                    <div className="flex flex-col items-start gap-1.5 xl:max-w-[42rem] xl:items-end">
                        <div className="flex flex-wrap items-center gap-2 xl:justify-end">
                            <a href={buildAppPath(bootstrap.basePath, data?.navigation.legacyPath ?? 'monthly-stats')} className={toolbarPrimaryButtonClass}>
                                Open classic page
                            </a>
                            <Link to={buildPlannedSessionEditorPreviewPath({day: `${selectedMonthId ?? new Date().toISOString().slice(0, 7)}-01`})} className={toolbarButtonClass}>Plan a session</Link>
                            <button type="button" onClick={reload} className={toolbarButtonClass}>Refresh data</button>
                        </div>
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px] font-medium text-gray-500 dark:text-gray-400 xl:justify-end">
                            <Link to="/monthly-stats" className="transition hover:text-gray-900 dark:hover:text-white">Jump to this month</Link>
                            <Link to="/training-blocks" className="transition hover:text-gray-900 dark:hover:text-white">Manage training blocks</Link>
                        </div>
                    </div>
                </div>

                <div className="mt-3 border-t border-gray-100 pt-3 dark:border-gray-800">
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div className="flex flex-wrap items-center gap-3">
                            {data?.navigation.previousMonthId ? (
                                <Link
                                    to={buildMonthPreviewPath(data.navigation.previousMonthId)}
                                    className="inline-flex items-center justify-center rounded-lg border border-gray-200 bg-white p-1.5 text-gray-500 transition hover:bg-gray-50 hover:text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white"
                                >
                                    ←
                                </Link>
                            ) : null}
                            <div>
                                <h2 className="text-[1.15rem] font-semibold text-gray-900 dark:text-white">{data?.navigation.currentMonthLabel ?? 'Loading month…'}</h2>
                                <p className="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">
                                    {data ? `Refreshed ${formatRequestedAt(data.requestedAt)}.` : 'Loading month context.'}
                                </p>
                            </div>
                            {data?.navigation.nextMonthId ? (
                                <Link
                                    to={buildMonthPreviewPath(data.navigation.nextMonthId)}
                                    className="inline-flex items-center justify-center rounded-lg border border-gray-200 bg-white p-1.5 text-gray-500 transition hover:bg-gray-50 hover:text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white"
                                >
                                    →
                                </Link>
                            ) : null}
                        </div>
                        <div className="flex flex-wrap gap-3 text-sm text-gray-500 dark:text-gray-400">
                            {data?.navigation.previousMonthLabel ? <span>{data.navigation.previousMonthLabel}</span> : null}
                            {data?.navigation.nextMonthLabel ? <span>{data.navigation.nextMonthLabel}</span> : null}
                        </div>
                    </div>

                    <div className="mt-3 flex flex-wrap gap-2">
                        <SummaryPill label="activities" value={formatNumber(data?.summary.totalActivities ?? 0)} />
                        <SummaryPill label={`distance (${unitSystem.distanceSymbol})`} value={formatNumber(data?.summary.totalDistance ?? 0, 1)} />
                        <SummaryPill label="planned load" value={formatNumber(data?.summary.estimatedPlannedLoad ?? 0, 1)} />
                        <SummaryPill label="race targets" value={formatNumber(data?.summary.raceEventCount ?? 0)} />
                        <SummaryPill label="planned sessions" value={formatNumber(data?.summary.plannedSessionCount ?? 0)} />
                        <SummaryPill label="linked" value={formatNumber(data?.summary.linkedPlannedSessionCount ?? 0)} />
                    </div>
                </div>
            </section>

            {loading && !data ? (
                <section className="ui-section text-sm text-gray-600 dark:text-gray-300">
                    Loading monthly stats…
                </section>
            ) : null}

            {error ? (
                <section className="rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                    {error}
                </section>
            ) : null}

            {data ? (
                <>
                    {data.month.activityTypeBreakdown.length > 0 ? (
                        <section className="ui-section">
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Activity mix</h2>
                                    <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">How the month is distributed across activity families.</p>
                                </div>
                                <div className="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold uppercase tracking-wider text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                    {formatNumber(data.month.activityTypeBreakdown.length)} families
                                </div>
                            </div>
                            <div className="mt-3.5 grid gap-2.5 lg:grid-cols-3">
                                {data.month.activityTypeBreakdown.map((entry) => (
                                    <div key={entry.activityType} className={`rounded-lg border p-3 ${buildActivityTone(entry.activityType)}`}>
                                        <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                            <div>
                                                <div className="text-[10px] font-semibold uppercase tracking-[0.18em] opacity-70">{entry.label}</div>
                                                <div className="mt-1 flex items-baseline gap-2">
                                                    <span className="text-[1.65rem] font-semibold tracking-tight">{formatNumber(entry.count)}</span>
                                                    <span className="text-[11px] font-medium uppercase tracking-[0.18em] opacity-60">sessions</span>
                                                </div>
                                            </div>
                                            <div className="space-y-0.5 text-[12px] opacity-85 sm:text-right">
                                                <div>{formatNumber(entry.distance, 1)} {unitSystem.distanceSymbol}</div>
                                                <div>{formatNumber(entry.elevation)} {unitSystem.elevationSymbol}</div>
                                                <div>{formatDuration(entry.movingTime)}</div>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </section>
                    ) : null}

                    <section className="grid gap-6 xl:grid-cols-[minmax(0,1.18fr)_minmax(320px,0.82fr)]">
                        <div className="space-y-6">
                            <section className="ui-section">
                                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                    <div>
                                        <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Calendar</h2>
                                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Selected month at a glance.</p>
                                    </div>
                                    <div className="text-sm text-gray-500 dark:text-gray-400">
                                        {formatNumber(data.summary.plannedSessionCount)} planned · {formatNumber(data.summary.linkedPlannedSessionCount)} linked · {formatNumber(data.summary.trainingBlockCount)} blocks
                                    </div>
                                </div>
                                <div className="mt-6 overflow-x-auto">
                                    <div className="grid min-w-[980px] grid-cols-7 gap-3 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                        {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((label) => (
                                            <div key={label} className="px-2">{label}</div>
                                        ))}
                                    </div>
                                    <div className="mt-3 min-w-[980px] space-y-3">
                                        {data.calendar.weeks.map((week) => (
                                            <div key={week.id} className="grid grid-cols-7 gap-3">
                                                {week.days.map((day) => (
                                                    <CalendarDayCard
                                                        key={day.date}
                                                        day={day}
                                                        distanceSymbol={unitSystem.distanceSymbol}
                                                        elevationSymbol={unitSystem.elevationSymbol}
                                                    />
                                                ))}
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </section>
                        </div>

                        <div className="space-y-6 xl:sticky xl:top-28 xl:self-start">
                            <section className="ui-section">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Current week</h2>
                                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Coach context for the active microcycle.</p>
                                    </div>
                                    <div className="rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-sky-700 dark:border-sky-900/50 dark:bg-sky-950/40 dark:text-sky-100">
                                        {formatDateRange(data.currentWeek.from, data.currentWeek.to)}
                                    </div>
                                </div>
                                <div className="mt-3.5 grid grid-cols-3 gap-2">
                                    <div className="rounded-lg border border-gray-200 bg-gray-50 p-2.5 dark:border-gray-800 dark:bg-gray-900">
                                        <div className="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Estimated load</div>
                                        <div className="mt-1 text-[1.45rem] font-semibold tracking-tight text-gray-900 dark:text-white">{formatNumber(data.currentWeek.estimatedLoad, 1)}</div>
                                    </div>
                                    <div className="rounded-lg border border-gray-200 bg-gray-50 p-2.5 dark:border-gray-800 dark:bg-gray-900">
                                        <div className="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Sessions</div>
                                        <div className="mt-1 text-[1.45rem] font-semibold tracking-tight text-gray-900 dark:text-white">{formatNumber(data.currentWeek.plannedSessionCount)}</div>
                                    </div>
                                    <div className="rounded-lg border border-gray-200 bg-gray-50 p-2.5 dark:border-gray-800 dark:bg-gray-900">
                                        <div className="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Race targets</div>
                                        <div className="mt-1 text-[1.45rem] font-semibold tracking-tight text-gray-900 dark:text-white">{formatNumber(data.currentWeek.raceEventCount)}</div>
                                    </div>
                                </div>
                                {data.currentWeek.activityTypeSummaries.length > 0 ? (
                                    <div className="mt-3 flex flex-wrap gap-1.5">
                                        {data.currentWeek.activityTypeSummaries.map((entry) => (
                                            <span key={entry.activityType} className="rounded-full border border-gray-200 bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                                {entry.label} · {formatNumber(entry.count)}
                                            </span>
                                        ))}
                                    </div>
                                ) : null}
                                {data.currentWeek.raceIntent ? (
                                    <div className={`mt-3 rounded-lg border p-2.5 ${buildCueTone(data.currentWeek.raceIntent.tone)}`}>
                                        <div className="text-[10px] font-semibold uppercase tracking-[0.18em] opacity-75">{data.currentWeek.raceIntent.label}</div>
                                        <div className="mt-1 font-semibold">{data.currentWeek.raceIntent.title}</div>
                                        <p className="mt-1 text-[13px] leading-5 opacity-85">{data.currentWeek.raceIntent.body}</p>
                                    </div>
                                ) : null}
                                {data.currentWeek.coachCues.length > 0 ? (
                                    <div className="mt-3 space-y-2">
                                        {data.currentWeek.coachCues.map((cue) => (
                                            <div key={`${cue.title}-${cue.body}`} className={`rounded-lg border p-2.5 ${buildCueTone(cue.tone)}`}>
                                                <div className="font-semibold">{cue.title}</div>
                                                <p className="mt-1 text-[13px] leading-5 opacity-85">{cue.body}</p>
                                            </div>
                                        ))}
                                    </div>
                                ) : null}
                            </section>

                            <section className="ui-section">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Upcoming races</h2>
                                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Targets that anchor the next planning blocks.</p>
                                    </div>
                                    <div className="rounded-full border border-rose-200 bg-rose-50 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-rose-700 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                                        {formatNumber(data.upcomingRaceEvents.length)} races
                                    </div>
                                </div>
                                <div className="mt-5 space-y-3">
                                    {data.upcomingRaceEvents.length === 0 ? (
                                        <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm leading-7 text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                                            No upcoming race targets yet. The calendar still renders cleanly, but there is no future race anchor to highlight.
                                        </div>
                                    ) : (
                                        data.upcomingRaceEvents.map((raceEvent) => <RaceEventRow key={raceEvent.id} raceEvent={raceEvent} />)
                                    )}
                                </div>
                            </section>

                            <section className="ui-section">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Training blocks</h2>
                                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Current and upcoming season structure.</p>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <div className="rounded-full border border-violet-200 bg-violet-50 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-violet-700 dark:border-violet-900/50 dark:bg-violet-950/40 dark:text-violet-100">
                                            {formatNumber((data.trainingBlocks.current ? 1 : 0) + data.trainingBlocks.upcoming.length)} blocks
                                        </div>
                                        <Link
                                            to="/training-blocks"
                                            className="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wider text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                                        >
                                            Manage
                                            <span aria-hidden="true">→</span>
                                        </Link>
                                    </div>
                                </div>
                                <div className="mt-5 space-y-3">
                                    {data.trainingBlocks.current ? (
                                        <div>
                                            <div className="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Current</div>
                                            <TrainingBlockRow block={data.trainingBlocks.current} />
                                        </div>
                                    ) : null}
                                    {data.trainingBlocks.upcoming.length > 0 ? (
                                        <div>
                                            <div className="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Upcoming</div>
                                            <div className="space-y-3">
                                                {data.trainingBlocks.upcoming.map((block) => (
                                                    <TrainingBlockRow key={block.id} block={block} />
                                                ))}
                                            </div>
                                        </div>
                                    ) : null}
                                    {!data.trainingBlocks.current && data.trainingBlocks.upcoming.length === 0 ? (
                                        <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm leading-7 text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                                            No training blocks are queued yet for the current planning window.
                                        </div>
                                    ) : null}
                                </div>
                            </section>
                        </div>
                    </section>
                </>
            ) : null}
        </div>
    );
}
