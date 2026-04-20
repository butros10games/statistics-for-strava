import {useCallback} from 'react';
import {Link, useParams} from 'react-router-dom';
import {StatCard} from '../components/stat-card';
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
import {useAsyncResource} from '../lib/use-async-resource';

interface MonthlyStatsPageProps {
    bootstrap: ReactPreviewBootstrap;
}

function normaliseMonthId(monthId?: string): string | undefined {
    return monthId?.replace(/^month-/, '');
}

function formatNumber(value: number, maximumFractionDigits = 0): string {
    return new Intl.NumberFormat('en-US', {
        maximumFractionDigits,
        minimumFractionDigits: 0,
    }).format(value);
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('en', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(new Date(value));
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
        <div className={`rounded-2xl border px-3 py-2 ${buildActivityTone(activity.activityType)}`}>
            <div className="font-medium">{activity.name}</div>
            <div className="mt-1 text-xs opacity-80">
                {activity.label} · {formatNumber(activity.distance, 1)} {distanceSymbol} · {formatNumber(activity.elevation)} {elevationSymbol}
            </div>
        </div>
    );
}

function PlannedSessionRow({session}: {session: MonthlyStatsPreviewPlannedSession}) {
    const accentClass = session.isKeySession
        ? 'border-orange-200 bg-orange-50/90 text-orange-900 dark:border-orange-900/50 dark:bg-orange-950/30 dark:text-orange-100'
        : session.isBrickSession
            ? 'border-fuchsia-200 bg-fuchsia-50/90 text-fuchsia-900 dark:border-fuchsia-900/50 dark:bg-fuchsia-950/30 dark:text-fuchsia-100'
            : 'border-gray-200 bg-white/85 text-gray-800 dark:border-gray-800 dark:bg-gray-900/40 dark:text-gray-100';

    return (
        <div className={`rounded-2xl border px-3 py-2 ${accentClass}`}>
            <div className="flex items-center justify-between gap-2">
                <div className="font-medium">{session.title}</div>
                {session.targetIntensityLabel ? (
                    <span className="rounded-full border border-white/70 bg-white/70 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-[0.18em] dark:border-gray-800 dark:bg-gray-950/40">
                        {session.targetIntensityLabel}
                    </span>
                ) : null}
            </div>
            <div className="mt-1 text-xs opacity-80">
                {session.label}
                {session.durationInSeconds ? ` · ${formatDuration(session.durationInSeconds)}` : ''}
                {typeof session.estimatedLoad === 'number' ? ` · Load ${formatNumber(session.estimatedLoad, 1)}` : ''}
            </div>
        </div>
    );
}

function RaceEventRow({raceEvent}: {raceEvent: MonthlyStatsPreviewRaceEvent}) {
    return (
        <div className={`rounded-2xl border px-3 py-2 ${buildPriorityTone(raceEvent.priority)}`}>
            <div className="flex items-center justify-between gap-2">
                <div className="font-medium">{raceEvent.title}</div>
                <span className="rounded-full border border-white/70 bg-white/70 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-[0.18em] dark:border-gray-800 dark:bg-gray-950/40">
                    {raceEvent.priority.toUpperCase()}
                </span>
            </div>
            <div className="mt-1 text-xs opacity-80">
                {raceEvent.profileLabel}
                {raceEvent.location ? ` · ${raceEvent.location}` : ''}
                {typeof raceEvent.countdownDays === 'number' ? ` · D-${Math.max(0, raceEvent.countdownDays)}` : ''}
            </div>
        </div>
    );
}

function TrainingBlockRow({block}: {block: MonthlyStatsPreviewTrainingBlock}) {
    return (
        <div className={`rounded-2xl border px-3 py-2 ${buildPhaseTone(block.phase)}`}>
            <div className="font-medium">{block.title}</div>
            <div className="mt-1 text-xs opacity-80">
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
        <article className={`min-h-[16rem] rounded-[24px] border p-3 ${day.isCurrentMonth ? 'border-gray-200 bg-white/92 dark:border-gray-800 dark:bg-gray-950/45' : 'border-gray-200/70 bg-gray-50/75 text-gray-400 dark:border-gray-800/60 dark:bg-gray-900/25 dark:text-gray-500'}`}>
            <div className="flex items-start justify-between gap-2">
                <div>
                    <div className={`inline-flex h-8 min-w-8 items-center justify-center rounded-full px-2 text-sm font-semibold ${day.isToday ? 'bg-strava-orange text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-100'}`}>
                        {day.dayNumber}
                    </div>
                    <div className="mt-2 text-[11px] uppercase tracking-[0.18em]">
                        {new Date(day.date).toLocaleDateString('en', {weekday: 'short'})}
                    </div>
                </div>
                <div className="flex flex-wrap justify-end gap-1">
                    {day.trainingBlockPhase ? (
                        <span className={`rounded-full border px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.18em] ${buildPhaseTone(day.trainingBlockPhase)}`}>
                            {day.trainingBlockPhase}
                        </span>
                    ) : null}
                    {day.racePriority ? (
                        <span className={`rounded-full border px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.18em] ${buildPriorityTone(day.racePriority)}`}>
                            {day.racePriority.toUpperCase()} race
                        </span>
                    ) : null}
                </div>
            </div>

            <div className="mt-3 space-y-2">
                {day.raceEvents.map((raceEvent) => (
                    <RaceEventRow key={raceEvent.id} raceEvent={raceEvent} />
                ))}
                {day.plannedSessions.map((session) => (
                    <PlannedSessionRow key={session.id} session={session} />
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
        <div className="space-y-8 pb-8">
            <section className="glass-panel rounded-[36px] p-6 md:p-8">
                <div className="grid gap-8 xl:grid-cols-[1.08fr_0.92fr]">
                    <div>
                        <div className="section-kicker">Monthly stats preview</div>
                        <h1 className="mt-5 max-w-4xl text-4xl font-semibold tracking-tight text-gray-900 dark:text-white md:text-5xl">
                            The calendar cockpit is now a route-sized React preview, with real month totals, live planner context, and the week-coach sidecar intact.
                        </h1>
                        <p className="mt-5 max-w-3xl text-base leading-8 text-gray-600 dark:text-gray-300 md:text-lg">
                            This slice takes one of the app’s richest overview surfaces and turns it into a shareable preview route. It stays read-only, but still carries the feel of the real monthly command center: navigation, training context, race anchors, and a day-by-day story.
                        </p>
                        <div className="mt-6 flex flex-wrap gap-3">
                            <a
                                href={buildAppPath(bootstrap.basePath, data?.navigation.legacyPath ?? 'monthly-stats')}
                                className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                            >
                                Compare with the live route
                                <span aria-hidden="true">↗</span>
                            </a>
                            <Link
                                to="/monthly-stats"
                                className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                            >
                                Jump to this month
                                <span aria-hidden="true">◎</span>
                            </Link>
                            <button
                                type="button"
                                onClick={reload}
                                className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                            >
                                Refresh preview data
                                <span aria-hidden="true">↻</span>
                            </button>
                        </div>
                    </div>
                    <div className="rounded-[32px] border border-cyan-200 bg-[linear-gradient(135deg,rgba(255,255,255,0.96),rgba(236,254,255,0.96))] p-5 shadow-[0_45px_120px_-45px_rgba(15,23,42,0.65)] dark:border-cyan-900/40 dark:bg-[linear-gradient(135deg,rgba(17,24,39,0.94),rgba(8,47,73,0.55))]">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-700 dark:text-cyan-200">Why this seam matters</div>
                        <div className="mt-4 space-y-3 text-sm leading-7 text-gray-700 dark:text-gray-200">
                            {[
                                'It is a real route, not a modal or a narrow edge page, so it proves the preview can absorb a major navigation hub.',
                                'The backend already centralizes its state assembly, which makes the migration safer than the sheer UI surface suggests.',
                                'It also bridges several adjacent domains at once: activities, planner sessions, race targets, and training blocks.',
                            ].map((item) => (
                                <div key={item} className="rounded-2xl border border-white/80 bg-white/80 p-4 dark:border-gray-800 dark:bg-gray-950/40">
                                    {item}
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </section>

            <section className="glass-panel rounded-[32px] p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div className="section-kicker">Month navigation</div>
                        <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">{data?.navigation.currentMonthLabel ?? 'Loading month…'}</h2>
                        <p className="mt-3 text-sm leading-7 text-gray-600 dark:text-gray-300">
                            {data ? `Preview data refreshed ${formatRequestedAt(data.requestedAt)}.` : 'Selecting the month and assembling the calendar context.'}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-3">
                        {data?.navigation.previousMonthId ? (
                            <Link
                                to={buildMonthPreviewPath(data.navigation.previousMonthId)}
                                className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                            >
                                ← {data.navigation.previousMonthLabel}
                            </Link>
                        ) : null}
                        {data?.navigation.nextMonthId ? (
                            <Link
                                to={buildMonthPreviewPath(data.navigation.nextMonthId)}
                                className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                            >
                                {data.navigation.nextMonthLabel} →
                            </Link>
                        ) : null}
                    </div>
                </div>
            </section>

            <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Activities" value={formatNumber(data?.summary.totalActivities ?? 0)} hint="Completed workouts inside the selected month." tone="orange" />
                <StatCard label="Distance" value={`${formatNumber(data?.summary.totalDistance ?? 0, 1)} ${unitSystem.distanceSymbol}`} hint="Summed from the same monthly stats query used by the live route." tone="blue" />
                <StatCard label="Planned load" value={formatNumber(data?.summary.estimatedPlannedLoad ?? 0, 1)} hint="Estimated training load from the month’s planned sessions." tone="emerald" />
                <StatCard label="Race targets" value={formatNumber(data?.summary.raceEventCount ?? 0)} hint="Race events overlapping the selected month." tone="slate" />
            </section>

            {loading && !data ? (
                <section className="glass-panel rounded-[32px] p-6 text-sm text-gray-600 dark:text-gray-300">
                    Loading monthly stats preview… unfolding the calendar one week at a time.
                </section>
            ) : null}

            {error ? (
                <section className="rounded-[32px] border border-rose-200 bg-rose-50/90 p-6 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                    {error}
                </section>
            ) : null}

            {data ? (
                <>
                    {data.month.activityTypeBreakdown.length > 0 ? (
                        <section className="glass-panel rounded-[32px] p-6">
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <div className="section-kicker">Activity mix</div>
                                    <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">How the month is distributed</h2>
                                </div>
                                <div className="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                    {formatNumber(data.month.activityTypeBreakdown.length)} families
                                </div>
                            </div>
                            <div className="mt-6 grid gap-4 xl:grid-cols-4">
                                {data.month.activityTypeBreakdown.map((entry) => (
                                    <div key={entry.activityType} className={`rounded-[28px] border p-5 ${buildActivityTone(entry.activityType)}`}>
                                        <div className="text-xs font-semibold uppercase tracking-[0.24em] opacity-70">{entry.label}</div>
                                        <div className="mt-3 text-3xl font-semibold tracking-tight">{formatNumber(entry.count)}</div>
                                        <div className="mt-4 space-y-1 text-sm opacity-85">
                                            <div>{formatNumber(entry.distance, 1)} {unitSystem.distanceSymbol}</div>
                                            <div>{formatNumber(entry.elevation)} {unitSystem.elevationSymbol}</div>
                                            <div>{formatDuration(entry.movingTime)}</div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </section>
                    ) : null}

                    <section className="grid gap-6 xl:grid-cols-[minmax(0,1.18fr)_minmax(320px,0.82fr)]">
                        <div className="space-y-6">
                            <section className="glass-panel rounded-[32px] p-6">
                                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                    <div>
                                        <div className="section-kicker">Calendar view</div>
                                        <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">Selected month at a glance</h2>
                                    </div>
                                    <div className="text-sm text-gray-500 dark:text-gray-400">
                                        {formatNumber(data.summary.plannedSessionCount)} planned · {formatNumber(data.summary.linkedPlannedSessionCount)} linked · {formatNumber(data.summary.trainingBlockCount)} blocks
                                    </div>
                                </div>
                                <div className="mt-6 overflow-x-auto">
                                    <div className="grid min-w-[980px] grid-cols-7 gap-3 text-xs font-semibold uppercase tracking-[0.22em] text-gray-500 dark:text-gray-400">
                                        {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((label) => (
                                            <div key={label} className="px-2">{label}</div>
                                        ))}
                                    </div>
                                    <div className="mt-3 space-y-3 min-w-[980px]">
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
                            <section className="glass-panel rounded-[32px] p-6">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <div className="section-kicker">Current week</div>
                                        <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">Coach context</h2>
                                    </div>
                                    <div className="rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-sky-700 dark:border-sky-900/50 dark:bg-sky-950/40 dark:text-sky-100">
                                        {formatDateRange(data.currentWeek.from, data.currentWeek.to)}
                                    </div>
                                </div>
                                <div className="mt-6 grid gap-4 sm:grid-cols-3 xl:grid-cols-1">
                                    <div className="rounded-[24px] border border-gray-200 bg-white/85 p-4 dark:border-gray-800 dark:bg-gray-900/40">
                                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Estimated load</div>
                                        <div className="mt-2 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">{formatNumber(data.currentWeek.estimatedLoad, 1)}</div>
                                    </div>
                                    <div className="rounded-[24px] border border-gray-200 bg-white/85 p-4 dark:border-gray-800 dark:bg-gray-900/40">
                                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Sessions</div>
                                        <div className="mt-2 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">{formatNumber(data.currentWeek.plannedSessionCount)}</div>
                                    </div>
                                    <div className="rounded-[24px] border border-gray-200 bg-white/85 p-4 dark:border-gray-800 dark:bg-gray-900/40">
                                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Race targets</div>
                                        <div className="mt-2 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">{formatNumber(data.currentWeek.raceEventCount)}</div>
                                    </div>
                                </div>
                                {data.currentWeek.activityTypeSummaries.length > 0 ? (
                                    <div className="mt-5 flex flex-wrap gap-2">
                                        {data.currentWeek.activityTypeSummaries.map((entry) => (
                                            <span key={entry.activityType} className="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                                {entry.label} · {formatNumber(entry.count)}
                                            </span>
                                        ))}
                                    </div>
                                ) : null}
                                {data.currentWeek.raceIntent ? (
                                    <div className={`mt-5 rounded-[24px] border p-4 ${buildCueTone(data.currentWeek.raceIntent.tone)}`}>
                                        <div className="text-xs font-semibold uppercase tracking-[0.24em] opacity-75">{data.currentWeek.raceIntent.label}</div>
                                        <div className="mt-2 font-semibold">{data.currentWeek.raceIntent.title}</div>
                                        <p className="mt-2 text-sm leading-7 opacity-85">{data.currentWeek.raceIntent.body}</p>
                                    </div>
                                ) : null}
                                {data.currentWeek.coachCues.length > 0 ? (
                                    <div className="mt-5 space-y-3">
                                        {data.currentWeek.coachCues.map((cue) => (
                                            <div key={`${cue.title}-${cue.body}`} className={`rounded-[24px] border p-4 ${buildCueTone(cue.tone)}`}>
                                                <div className="font-semibold">{cue.title}</div>
                                                <p className="mt-2 text-sm leading-7 opacity-85">{cue.body}</p>
                                            </div>
                                        ))}
                                    </div>
                                ) : null}
                            </section>

                            <section className="glass-panel rounded-[32px] p-6">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <div className="section-kicker">Upcoming races</div>
                                        <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">Anchors ahead</h2>
                                    </div>
                                    <div className="rounded-full border border-rose-200 bg-rose-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-rose-700 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                                        {formatNumber(data.upcomingRaceEvents.length)} races
                                    </div>
                                </div>
                                <div className="mt-5 space-y-3">
                                    {data.upcomingRaceEvents.length === 0 ? (
                                        <div className="rounded-[24px] border border-gray-200 bg-white/85 p-4 text-sm leading-7 text-gray-600 dark:border-gray-800 dark:bg-gray-900/40 dark:text-gray-300">
                                            No upcoming race targets yet. The preview still renders the calendar cleanly, but there is no future race anchor to highlight.
                                        </div>
                                    ) : (
                                        data.upcomingRaceEvents.map((raceEvent) => <RaceEventRow key={raceEvent.id} raceEvent={raceEvent} />)
                                    )}
                                </div>
                            </section>

                            <section className="glass-panel rounded-[32px] p-6">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <div className="section-kicker">Training blocks</div>
                                        <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">Season structure</h2>
                                    </div>
                                    <div className="rounded-full border border-violet-200 bg-violet-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-violet-700 dark:border-violet-900/50 dark:bg-violet-950/40 dark:text-violet-100">
                                        {formatNumber((data.trainingBlocks.current ? 1 : 0) + data.trainingBlocks.upcoming.length)} blocks
                                    </div>
                                </div>
                                <div className="mt-5 space-y-3">
                                    {data.trainingBlocks.current ? (
                                        <div>
                                            <div className="mb-2 text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Current</div>
                                            <TrainingBlockRow block={data.trainingBlocks.current} />
                                        </div>
                                    ) : null}
                                    {data.trainingBlocks.upcoming.length > 0 ? (
                                        <div>
                                            <div className="mb-2 text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Upcoming</div>
                                            <div className="space-y-3">
                                                {data.trainingBlocks.upcoming.map((block) => (
                                                    <TrainingBlockRow key={block.id} block={block} />
                                                ))}
                                            </div>
                                        </div>
                                    ) : null}
                                    {!data.trainingBlocks.current && data.trainingBlocks.upcoming.length === 0 ? (
                                        <div className="rounded-[24px] border border-gray-200 bg-white/85 p-4 text-sm leading-7 text-gray-600 dark:border-gray-800 dark:bg-gray-900/40 dark:text-gray-300">
                                            No training blocks are queued yet for the current planning window.
                                        </div>
                                    ) : null}
                                </div>
                            </section>
                        </div>
                    </section>

                    <section className="glass-panel rounded-[32px] p-6">
                        <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <div className="section-kicker">Migration note</div>
                                <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">A proper route-sized calendar preview</h2>
                            </div>
                            <Link
                                to="/roadmap"
                                className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                            >
                                Open the migration roadmap
                                <span aria-hidden="true">→</span>
                            </Link>
                        </div>
                        <div className="mt-5 space-y-3 text-sm leading-7 text-gray-600 dark:text-gray-300">
                            {[
                                'Monthly stats is a meaningful milestone because it turns a navigation hub, not just a detail slice, into a React preview route.',
                                'The read-only version already captures the most important value: month navigation, live planner context, upcoming races, training blocks, and a richly populated calendar grid.',
                                'That leaves future write-capable planner interactions as a follow-up layer rather than a prerequisite for migration progress.',
                            ].map((item) => (
                                <div key={item} className="rounded-2xl border border-gray-200 bg-white/80 p-4 dark:border-gray-800 dark:bg-gray-950/30">
                                    {item}
                                </div>
                            ))}
                        </div>
                    </section>
                </>
            ) : null}
        </div>
    );
}
