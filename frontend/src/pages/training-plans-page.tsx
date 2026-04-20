import {useCallback, useMemo, useState} from 'react';
import {Link} from 'react-router-dom';
import {StatCard} from '../components/stat-card';
import {TrainingPlanCreateModal} from '../components/training-plan-create-modal';
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

function buildContinuityTone(plan: TrainingPlansPreviewPlan): string {
    if (!plan.continuity) {
        return 'border-gray-200 bg-white/85 dark:border-gray-800 dark:bg-gray-900/60';
    }

    switch (plan.continuity.kind) {
        case 'gap':
            return 'border-amber-200 bg-amber-50/90 dark:border-amber-800/60 dark:bg-amber-950/30';
        case 'overlap':
            return 'border-rose-200 bg-rose-50/90 dark:border-rose-800/60 dark:bg-rose-950/30';
        default:
            return 'border-emerald-200 bg-emerald-50/90 dark:border-emerald-800/60 dark:bg-emerald-950/30';
    }
}

function TrainingPlansLoadingState() {
    return (
        <section className="glass-panel rounded-[32px] p-6">
            <div className="section-kicker">Loading</div>
            <div className="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {Array.from({length: 6}).map((_, index) => (
                    <div key={index} className="animate-pulse rounded-[28px] border border-gray-200 bg-white/85 p-5 dark:border-gray-800 dark:bg-gray-900/60">
                        <div className="h-3 w-16 rounded-full bg-gray-200 dark:bg-gray-800" />
                        <div className="mt-5 h-7 w-2/3 rounded-full bg-gray-200 dark:bg-gray-800" />
                        <div className="mt-3 h-4 w-1/2 rounded-full bg-gray-200 dark:bg-gray-800" />
                        <div className="mt-6 space-y-2">
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
    const [modalConfig, setModalConfig] = useState<null | {afterTrainingPlanId?: string; targetRaceEventId?: string}>(null);
    const loadTrainingPlans = useCallback(
        (signal: AbortSignal): Promise<TrainingPlansPreviewResponse> => fetchTrainingPlansPreview(bootstrap.basePath, signal),
        [bootstrap.basePath],
    );

    const {data, loading, error, reload} = useAsyncResource(loadTrainingPlans);

    const activePlan = useMemo(
        () => data?.plans.find((plan) => plan.id === data.activePlanId) ?? null,
        [data],
    );
    const latestPlanId = data?.plans.at(-1)?.id;

    return (
        <div className="space-y-8 pb-8">
            <section className="glass-panel rounded-[36px] p-6 md:p-8">
                <div className="grid gap-8 xl:grid-cols-[1.1fr_0.9fr]">
                    <div>
                        <div className="section-kicker">Route spike</div>
                        <h1 className="mt-5 text-4xl font-semibold tracking-tight text-gray-900 dark:text-white md:text-5xl">
                            Training plans are now backed by real Symfony data in the React preview.
                        </h1>
                        <p className="mt-5 max-w-2xl text-base leading-8 text-gray-600 dark:text-gray-300 md:text-lg">
                            This route now fetches a real authenticated JSON payload from the backend instead of relying on
                            placeholder cards. That gives us a realistic seam for migrating planner screens without replacing
                            Symfony or the legacy route all at once.
                        </p>
                        <div className="mt-6 flex flex-wrap gap-3">
                            <button
                                type="button"
                                onClick={() => setModalConfig(latestPlanId ? {afterTrainingPlanId: latestPlanId} : {})}
                                className="inline-flex items-center gap-2 rounded-2xl bg-strava-orange px-5 py-3 text-sm font-semibold text-white transition hover:bg-orange-600"
                            >
                                {latestPlanId ? 'Create next plan in React' : 'Create first plan in React'}
                                <span aria-hidden="true">+</span>
                            </button>
                            <a
                                href={buildAppPath(bootstrap.basePath, 'training-plans')}
                                className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                            >
                                Compare with the live route
                                <span aria-hidden="true">↗</span>
                            </a>
                            <Link
                                to="/roadmap"
                                className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                            >
                                See the rollout order
                                <span aria-hidden="true">→</span>
                            </Link>
                        </div>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <StatCard label="Current athlete" value={bootstrap.athlete.name} hint="Shell bootstrap is still shared with Symfony." tone="orange" />
                        <StatCard label="API shape" value={loading ? 'Loading…' : error ? 'Error' : 'Live'} hint="This route now consumes a real authenticated JSON endpoint." tone="emerald" />
                        <StatCard label="Modal migration" value="Live create" hint="The first create-plan flow now runs in React with preview JSON defaults and live persistence." tone="blue" />
                        <StatCard label="Target state" value={loading ? '—' : `${data?.stats.totalPlans ?? 0} plans`} hint="Planner cards and stats are now driven by backend data." />
                    </div>
                </div>
            </section>

            {loading ? <TrainingPlansLoadingState /> : null}

            {!loading && error ? (
                <section className="glass-panel rounded-[32px] p-6">
                    <div className="section-kicker">Fetch error</div>
                    <h2 className="mt-5 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">The preview route could not load training plans.</h2>
                    <p className="mt-4 max-w-3xl text-base leading-8 text-gray-600 dark:text-gray-300">
                        {error}. This is usually a sign that the session expired, the backend route is unavailable, or the preview build is out of date.
                    </p>
                    <div className="mt-6 flex flex-wrap gap-3">
                        <a
                            href={buildAppPath(bootstrap.basePath, 'training-plans')}
                            className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                        >
                            Open the live route
                            <span aria-hidden="true">↗</span>
                        </a>
                        <button
                            type="button"
                            onClick={reload}
                            className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                        >
                            Retry preview fetch
                            <span aria-hidden="true">↻</span>
                        </button>
                    </div>
                </section>
            ) : null}

            {!loading && !error && data ? (
                <>
                    <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <StatCard label="Total plans" value={data.stats.totalPlans} hint="Read from the same repositories that build the legacy planner page." tone="orange" />
                        <StatCard label="Race vs training" value={`${data.stats.racePlans} / ${data.stats.trainingPlans}`} hint="A quick signal for how race-heavy the season is." tone="emerald" />
                        <StatCard label="Continuity issues" value={data.stats.gapCount + data.stats.overlapCount} hint={data.stats.gapCount + data.stats.overlapCount === 0 ? 'No gaps or overlaps detected.' : `${data.stats.gapCount} gaps and ${data.stats.overlapCount} overlaps need attention.`} tone="blue" />
                        <StatCard label="Unassigned races" value={data.stats.unassignedUpcomingRaces} hint={data.stats.unassignedUpcomingRaces > 0 ? 'Upcoming races exist without an obvious linked plan.' : 'All upcoming races are currently covered.'} />
                    </section>

                    <section className="grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
                        <div className="glass-panel rounded-[32px] p-6">
                            <div className="section-kicker">Current focus</div>
                            {activePlan ? (
                                <>
                                    <h2 className="mt-5 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">{activePlan.title}</h2>
                                    <p className="mt-3 text-base leading-8 text-gray-600 dark:text-gray-300">{formatDateRange(activePlan.startDay, activePlan.endDay)}</p>
                                    <div className="mt-5 flex flex-wrap items-center gap-2">
                                        <span className={`rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] ${buildStatusTone(activePlan)}`}>{buildStatusBadge(activePlan)}</span>
                                        <span className="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">{activePlan.type === 'race' ? 'Race plan' : 'Training block'}</span>
                                        {activePlan.discipline ? <span className="rounded-full border border-orange-200 bg-orange-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-orange-700 dark:border-orange-900/40 dark:bg-orange-950/40 dark:text-orange-200">{formatLabel(activePlan.discipline)}</span> : null}
                                    </div>
                                    {activePlan.notes ? <p className="mt-5 rounded-[24px] border border-gray-200 bg-white/85 p-4 text-sm leading-7 text-gray-700 dark:border-gray-800 dark:bg-gray-900/60 dark:text-gray-200">{activePlan.notes}</p> : null}
                                </>
                            ) : (
                                <p className="mt-5 text-base leading-8 text-gray-600 dark:text-gray-300">No active or upcoming plan was found for this athlete yet.</p>
                            )}
                        </div>

                        <div className="glass-panel rounded-[32px] p-6">
                            <div className="section-kicker">Upcoming races without a plan</div>
                            <div className="mt-5 space-y-3">
                                {data.unassignedUpcomingRaces.length === 0 ? (
                                    <div className="rounded-[24px] border border-emerald-200 bg-emerald-50/90 p-4 text-sm leading-7 text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-950/30 dark:text-emerald-100">
                                        Nice — every upcoming race appears to be covered by a current plan window.
                                    </div>
                                ) : (
                                    data.unassignedUpcomingRaces.map((race) => (
                                        <div key={race.id} className="rounded-[24px] border border-amber-200 bg-amber-50/90 p-4 text-sm leading-7 text-amber-900 dark:border-amber-800/60 dark:bg-amber-950/30 dark:text-amber-100">
                                            <div className="flex items-center justify-between gap-3">
                                                <div className="font-semibold">{race.title}</div>
                                                <div className={`rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] ${buildRacePriorityTone(race)}`}>{race.priority.toUpperCase()}</div>
                                            </div>
                                            <div className="mt-2 text-amber-800/80 dark:text-amber-100/80">{formatShortDate(race.day)} · {formatLabel(race.profile)}</div>
                                            <button
                                                type="button"
                                                onClick={() => setModalConfig({targetRaceEventId: race.id})}
                                                className="mt-4 inline-flex items-center gap-2 rounded-2xl border border-amber-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-[0.24em] text-amber-800 transition hover:border-amber-400 hover:bg-amber-100/80 dark:border-amber-700 dark:bg-amber-950/20 dark:text-amber-100"
                                            >
                                                Create anchored plan
                                                <span aria-hidden="true">→</span>
                                            </button>
                                        </div>
                                    ))
                                )}
                            </div>
                        </div>
                    </section>

                    <section className="glass-panel rounded-[32px] p-6">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <div className="section-kicker">Live route data</div>
                                <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">Planner timeline preview</h2>
                            </div>
                            <div className="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-emerald-700 dark:border-emerald-900/40 dark:bg-emerald-950/40 dark:text-emerald-300">
                                Read-only API slice
                            </div>
                        </div>
                        <div className="mt-6 grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
                            {data.plans.map((plan) => (
                                <article key={plan.id} className={`rounded-[28px] border p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg ${buildContinuityTone(plan)}`}>
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">{plan.type === 'race' ? 'Race plan' : 'Training block'}</div>
                                            <h3 className="mt-3 text-xl font-semibold text-gray-900 dark:text-white">{plan.title}</h3>
                                        </div>
                                        <span className={`rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] ${buildStatusTone(plan)}`}>{buildStatusBadge(plan)}</span>
                                    </div>

                                    <p className="mt-4 text-sm leading-7 text-gray-600 dark:text-gray-300">{formatDateRange(plan.startDay, plan.endDay)}</p>

                                    <div className="mt-4 flex flex-wrap items-center gap-2">
                                        <span className="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">{plan.durationWeeks} weeks</span>
                                        {plan.discipline ? <span className="rounded-full border border-orange-200 bg-orange-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-orange-700 dark:border-orange-900/40 dark:bg-orange-950/40 dark:text-orange-200">{formatLabel(plan.discipline)}</span> : null}
                                        {plan.objective ? <span className="rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-sky-700 dark:border-sky-900/40 dark:bg-sky-950/40 dark:text-sky-100">{formatLabel(plan.objective)}</span> : null}
                                    </div>

                                    {plan.linkedRace ? (
                                        <div className="mt-5 rounded-[20px] border border-gray-200 bg-white/80 p-4 text-sm leading-7 text-gray-700 dark:border-gray-800 dark:bg-gray-950/30 dark:text-gray-200">
                                            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Linked race</div>
                                            <div className="mt-2 font-semibold text-gray-900 dark:text-white">{plan.linkedRace.title}</div>
                                            <div className="mt-1 text-gray-500 dark:text-gray-400">{formatShortDate(plan.linkedRace.day)} · {formatLabel(plan.linkedRace.profile)}</div>
                                        </div>
                                    ) : null}

                                    {plan.scheduleHighlights.length > 0 ? (
                                        <div className="mt-5">
                                            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Schedule</div>
                                            <div className="mt-3 flex flex-wrap gap-2">
                                                {plan.scheduleHighlights.map((highlight) => (
                                                    <span key={highlight} className="rounded-full bg-sky-100 px-3 py-1 text-xs font-medium text-sky-800 dark:bg-sky-950/40 dark:text-sky-100">{highlight}</span>
                                                ))}
                                            </div>
                                        </div>
                                    ) : null}

                                    {plan.performanceHighlights.length > 0 ? (
                                        <div className="mt-5">
                                            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Metrics</div>
                                            <div className="mt-3 flex flex-wrap gap-2">
                                                {plan.performanceHighlights.map((highlight) => (
                                                    <span key={highlight} className="rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-100">{highlight}</span>
                                                ))}
                                            </div>
                                        </div>
                                    ) : null}

                                    {plan.continuity ? (
                                        <div className="mt-5 rounded-[20px] border border-white/70 bg-white/65 p-4 text-sm leading-7 text-gray-700 dark:border-gray-800 dark:bg-gray-950/30 dark:text-gray-200">
                                            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Continuity</div>
                                            <div className="mt-2 font-medium">
                                                {plan.continuity.kind === 'gap' ? `Gap of ${plan.continuity.days} day${plan.continuity.days === 1 ? '' : 's'}` : plan.continuity.kind === 'overlap' ? `Overlap of ${plan.continuity.days} day${plan.continuity.days === 1 ? '' : 's'}` : 'Perfect handoff'}
                                            </div>
                                            <div className="mt-1 text-gray-500 dark:text-gray-400">{plan.continuity.nextPlanTitle}</div>
                                        </div>
                                    ) : null}

                                    <div className="mt-6 flex flex-wrap gap-3">
                                        <a
                                            href={buildAppPath(bootstrap.basePath, plan.racePlannerPath)}
                                            className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                                        >
                                            Open legacy detail
                                            <span aria-hidden="true">↗</span>
                                        </a>
                                    </div>
                                </article>
                            ))}
                        </div>
                    </section>
                </>
            ) : null}

            <TrainingPlanCreateModal
                basePath={bootstrap.basePath}
                isOpen={null !== modalConfig}
                afterTrainingPlanId={modalConfig?.afterTrainingPlanId}
                targetRaceEventId={modalConfig?.targetRaceEventId}
                onClose={() => setModalConfig(null)}
                onSaved={reload}
            />
        </div>
    );
}
