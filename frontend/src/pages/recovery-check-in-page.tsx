import {type FormEvent, useCallback, useEffect, useMemo, useState} from 'react';
import {Link} from 'react-router-dom';
import {StatCard} from '../components/stat-card';
import {buildAppPath, type ReactPreviewBootstrap} from '../lib/bootstrap';
import {
    fetchRecoveryCheckInPreview,
    saveRecoveryCheckInPreview,
    type RecoveryCheckInPreviewRecord,
    type RecoveryCheckInPreviewResponse,
} from '../lib/recovery-check-in-preview-api';
import {useAsyncResource} from '../lib/use-async-resource';

interface RecoveryCheckInPageProps {
    bootstrap: ReactPreviewBootstrap;
}

interface RecoveryCheckInFormState {
    day: string;
    fatigue: number;
    soreness: number;
    stress: number;
    motivation: number;
    sleepQuality: number;
}

const scoreOptions = [1, 2, 3, 4, 5] as const;

const fieldDefinitions: Array<{
    key: keyof Omit<RecoveryCheckInFormState, 'day'>;
    label: string;
    hint: string;
    polarity: 'reduce' | 'raise';
}> = [
    {key: 'fatigue', label: 'Fatigue', hint: 'How cooked do your legs and system feel this morning?', polarity: 'reduce'},
    {key: 'soreness', label: 'Soreness', hint: 'How much lingering muscle damage is still voting in the room?', polarity: 'reduce'},
    {key: 'stress', label: 'Stress', hint: 'How noisy is life outside the training file right now?', polarity: 'reduce'},
    {key: 'motivation', label: 'Motivation', hint: 'How ready are you to actually show up and do the work?', polarity: 'raise'},
    {key: 'sleepQuality', label: 'Sleep quality', hint: 'How restorative did last night really feel?', polarity: 'raise'},
];

function createFormState(data: RecoveryCheckInPreviewResponse): RecoveryCheckInFormState {
    return {
        day: data.form.day,
        ...data.form.defaults,
    };
}

function formatRequestedAt(value: string): string {
    return new Intl.DateTimeFormat('en', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function formatDay(value: string | null): string {
    if (!value) {
        return 'Not logged yet';
    }

    return new Intl.DateTimeFormat('en', {
        dateStyle: 'medium',
    }).format(new Date(`${value}T12:00:00`));
}

function formatScore(value: number | null): string {
    return typeof value === 'number' ? `${value.toFixed(1)}/5` : '—';
}

function describeState(state: RecoveryCheckInPreviewResponse['summary']['state']): string {
    switch (state) {
        case 'updated-today':
            return 'Updated today';
        case 'stale':
            return 'Needs today\'s check-in';
        default:
            return 'No entries yet';
    }
}

function statePanelClasses(state: RecoveryCheckInPreviewResponse['summary']['state']): string {
    switch (state) {
        case 'updated-today':
            return 'border-emerald-200 bg-emerald-50/90 text-emerald-900 dark:border-emerald-800/60 dark:bg-emerald-950/30 dark:text-emerald-100';
        case 'stale':
            return 'border-amber-200 bg-amber-50/90 text-amber-900 dark:border-amber-800/60 dark:bg-amber-950/30 dark:text-amber-100';
        default:
            return 'border-slate-200 bg-slate-50/90 text-slate-800 dark:border-slate-800/60 dark:bg-slate-950/30 dark:text-slate-100';
    }
}

function scoreButtonClass(selected: boolean): string {
    return selected
        ? 'border-orange-500 bg-strava-orange text-white shadow-sm shadow-orange-500/30'
        : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:text-white';
}

function ScoreField({
    label,
    hint,
    value,
    onChange,
    polarity,
}: {
    label: string;
    hint: string;
    value: number;
    onChange: (value: number) => void;
    polarity: 'reduce' | 'raise';
}) {
    return (
        <div className="rounded-[26px] border border-gray-200 bg-white/80 p-5 dark:border-gray-800 dark:bg-gray-950/35">
            <div className="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                <div>
                    <h3 className="text-lg font-semibold text-gray-900 dark:text-white">{label}</h3>
                    <p className="mt-1 max-w-2xl text-sm leading-7 text-gray-600 dark:text-gray-300">{hint}</p>
                </div>
                <span className={`inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] ${polarity === 'reduce'
                    ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-200'
                    : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200'
                }`}>
                    {polarity === 'reduce' ? 'Lower is better' : 'Higher is better'}
                </span>
            </div>
            <div className="mt-4 flex flex-wrap gap-2">
                {scoreOptions.map((option) => (
                    <button
                        key={option}
                        type="button"
                        onClick={() => onChange(option)}
                        className={`inline-flex h-12 min-w-12 items-center justify-center rounded-2xl border px-4 text-sm font-semibold transition ${scoreButtonClass(value === option)}`}
                        aria-pressed={value === option}
                    >
                        {option}
                    </button>
                ))}
            </div>
        </div>
    );
}

function ScoreBadge({label, value}: {label: string; value: number}) {
    return (
        <div className="rounded-2xl border border-gray-200 bg-white/80 p-4 dark:border-gray-800 dark:bg-gray-950/35">
            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">{label}</div>
            <div className="mt-2 text-2xl font-semibold text-gray-900 dark:text-white">{value}/5</div>
        </div>
    );
}

function SnapshotPanel({record, title, copy}: {record: RecoveryCheckInPreviewRecord | null; title: string; copy: string}) {
    return (
        <section className="rounded-[32px] border border-gray-200 bg-white/92 p-6 shadow-sm dark:border-gray-800 dark:bg-gray-950/40">
            <div className="section-kicker">{title}</div>
            <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">{record ? formatDay(record.day) : 'No data yet'}</h2>
            <p className="mt-3 text-sm leading-7 text-gray-600 dark:text-gray-300">{copy}</p>
            {record ? (
                <>
                    <div className="mt-5 grid gap-3 sm:grid-cols-2">
                        <ScoreBadge label="Fatigue" value={record.fatigue} />
                        <ScoreBadge label="Soreness" value={record.soreness} />
                        <ScoreBadge label="Stress" value={record.stress} />
                        <ScoreBadge label="Motivation" value={record.motivation} />
                        <ScoreBadge label="Sleep quality" value={record.sleepQuality} />
                        <div className="rounded-2xl border border-orange-200 bg-orange-50/90 p-4 dark:border-orange-900/50 dark:bg-orange-950/35">
                            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-orange-700 dark:text-orange-200">Readiness signal</div>
                            <div className="mt-2 text-2xl font-semibold text-orange-900 dark:text-orange-100">{record.readinessScore.toFixed(1)}/5</div>
                            <div className="mt-1 text-sm text-orange-800/80 dark:text-orange-100/80">Derived by easing the penalties from fatigue, soreness, and stress while rewarding motivation and sleep.</div>
                        </div>
                    </div>
                    <div className="mt-5 rounded-2xl border border-gray-200 bg-gray-50/90 p-4 text-sm leading-7 text-gray-700 dark:border-gray-800 dark:bg-gray-950/25 dark:text-gray-300">
                        Average self-report: <span className="font-semibold text-gray-900 dark:text-white">{record.averageScore.toFixed(1)}/5</span>
                    </div>
                </>
            ) : (
                <div className="mt-5 rounded-2xl border border-dashed border-gray-300 bg-gray-50/80 p-4 text-sm leading-7 text-gray-600 dark:border-gray-700 dark:bg-gray-950/25 dark:text-gray-300">
                    Once you save a check-in, the preview keeps the latest wellness snapshot here so the route feels like a proper morning cockpit instead of a one-shot form.
                </div>
            )}
        </section>
    );
}

export function RecoveryCheckInPage({bootstrap}: RecoveryCheckInPageProps) {
    const loadRecoveryCheckIn = useCallback(
        (signal: AbortSignal): Promise<RecoveryCheckInPreviewResponse> => fetchRecoveryCheckInPreview(bootstrap.basePath, signal),
        [bootstrap.basePath],
    );

    const {data, loading, error, reload} = useAsyncResource(loadRecoveryCheckIn);
    const [localData, setLocalData] = useState<RecoveryCheckInPreviewResponse | null>(null);
    const [formState, setFormState] = useState<RecoveryCheckInFormState | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [submitSuccess, setSubmitSuccess] = useState<string | null>(null);

    useEffect(() => {
        if (data) {
            setLocalData(data);
        }
    }, [data]);

    const displayData = localData ?? data;

    useEffect(() => {
        if (displayData) {
            setFormState(createFormState(displayData));
        }
    }, [displayData?.requestedAt, displayData?.savedDay]);

    const latestOrTodayRecord = useMemo(
        () => displayData?.todayCheckIn ?? displayData?.latestCheckIn ?? null,
        [displayData],
    );

    const handleFieldChange = (key: keyof Omit<RecoveryCheckInFormState, 'day'>, value: number) => {
        setFormState((current) => (current ? {...current, [key]: value} : current));
    };

    const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (!formState) {
            return;
        }

        setSubmitting(true);
        setSubmitError(null);
        setSubmitSuccess(null);

        try {
            const response = await saveRecoveryCheckInPreview(bootstrap.basePath, formState);
            setLocalData(response);
            setSubmitSuccess(`Saved the recovery check-in for ${formatDay(response.savedDay ?? formState.day)}.`);
        } catch (submit) {
            setSubmitError(submit instanceof Error ? submit.message : 'Could not save this recovery check-in.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div className="space-y-8 pb-8">
            <section className="glass-panel overflow-hidden rounded-[36px] p-6 md:p-8">
                <div className="grid gap-8 xl:grid-cols-[1.08fr_0.92fr]">
                    <div>
                        <div className="section-kicker">Recovery form preview</div>
                        <h1 className="mt-5 max-w-4xl text-4xl font-semibold tracking-tight text-gray-900 dark:text-white md:text-5xl">
                            Morning recovery check-in now has a real React route instead of living as a small dashboard modal with commitment issues.
                        </h1>
                        <p className="mt-5 max-w-3xl text-base leading-8 text-gray-600 dark:text-gray-300 md:text-lg">
                            This is the first lightweight wellness form to graduate into the preview shell as a proper route. The live Symfony backend still stores the check-in and rebuilds the dashboard, but the interaction, feedback, and surrounding context now feel much more intentional.
                        </p>
                        <div className="mt-6 flex flex-wrap gap-3">
                            <a
                                href={buildAppPath(bootstrap.basePath, displayData?.legacyPath ?? 'recovery-check-in?redirectTo=/dashboard')}
                                className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                            >
                                Compare with the live modal page
                                <span aria-hidden="true">↗</span>
                            </a>
                            <Link
                                to="/dashboard"
                                className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                            >
                                Back to dashboard
                                <span aria-hidden="true">→</span>
                            </Link>
                            <button
                                type="button"
                                onClick={() => {
                                    setSubmitSuccess(null);
                                    reload();
                                }}
                                className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                            >
                                Refresh form data
                                <span aria-hidden="true">↻</span>
                            </button>
                        </div>
                    </div>
                    <div className="rounded-[32px] border border-orange-200 bg-[linear-gradient(135deg,rgba(255,255,255,0.96),rgba(255,244,237,0.96))] p-5 shadow-[0_45px_120px_-45px_rgba(15,23,42,0.65)] dark:border-orange-900/40 dark:bg-[linear-gradient(135deg,rgba(17,24,39,0.94),rgba(49,24,17,0.92))]">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-orange-700 dark:text-orange-200">Why this cut matters</div>
                        <div className="mt-4 space-y-3 text-sm leading-7 text-gray-700 dark:text-gray-200">
                            {[
                                'It promotes a modal-sized interaction into a route with enough context to actually explain the readiness signal.',
                                'It proves the preview can handle live write flows without jumping straight to the most complicated planner editors.',
                                'It creates a reusable blueprint for the next form slices: fetch defaults, save to Symfony, then refresh local JSON state in place.',
                            ].map((item) => (
                                <div key={item} className="rounded-2xl border border-white/80 bg-white/80 p-4 dark:border-gray-800 dark:bg-gray-950/40">
                                    {item}
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </section>

            <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Check-in status" value={displayData ? describeState(displayData.summary.state) : 'Loading…'} hint="Whether today already has a recorded wellness entry." tone="orange" />
                <StatCard label="Latest entry" value={displayData ? formatDay(displayData.summary.latestDay) : 'Waiting…'} hint="Most recent recorded day in the wellness log." tone="emerald" />
                <StatCard label="Readiness signal" value={displayData ? formatScore(displayData.summary.readinessScore) : '—'} hint="Higher is better: motivation and sleep lift it, fatigue/soreness/stress drag it down." tone="blue" />
                <StatCard label="Preview refresh" value={displayData ? formatRequestedAt(displayData.requestedAt) : 'Waiting…'} hint="Shows when the preview payload was last loaded or saved." tone="slate" />
            </section>

            {loading && !displayData ? (
                <section className="glass-panel rounded-[32px] p-6 text-sm text-gray-600 dark:text-gray-300">
                    Loading the morning wellness ritual… assembling caffeine, honesty, and a few discreetly judgmental numbers.
                </section>
            ) : null}

            {error ? (
                <section className="rounded-[32px] border border-rose-200 bg-rose-50/90 p-6 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                    {error}
                </section>
            ) : null}

            {displayData ? (
                <section className="grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
                    <div className="space-y-6">
                        <section className={`rounded-[32px] border p-6 ${statePanelClasses(displayData.summary.state)}`}>
                            <div className="text-xs font-semibold uppercase tracking-[0.24em]">Current state</div>
                            <h2 className="mt-4 text-3xl font-semibold tracking-tight">{describeState(displayData.summary.state)}</h2>
                            <p className="mt-3 max-w-3xl text-sm leading-7 opacity-90">
                                {displayData.summary.state === 'updated-today'
                                    ? 'Today is already covered, so you can refine the entry if the morning suddenly took a turn.'
                                    : displayData.summary.state === 'stale'
                                        ? 'There is wellness history, but not for today yet. One quick update keeps the dashboard’s readiness interpretation honest.'
                                        : 'No wellness entries exist yet, which makes this a nice small but meaningful write-flow seam for the preview app.'}
                            </p>
                        </section>

                        <form onSubmit={handleSubmit} className="glass-panel rounded-[32px] p-6 md:p-7">
                            <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                <div>
                                    <div className="section-kicker">React form flow</div>
                                    <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">Log today’s readiness without leaving the preview shell.</h2>
                                    <p className="mt-3 max-w-3xl text-sm leading-7 text-gray-600 dark:text-gray-300">
                                        The form saves through the live backend, clamps the same 1–5 scale as the legacy flow, and returns fresh JSON so the route can update itself immediately.
                                    </p>
                                </div>
                                <div className="rounded-2xl border border-gray-200 bg-white/90 px-4 py-3 dark:border-gray-800 dark:bg-gray-950/35">
                                    <label className="mb-2 block text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400" htmlFor="recovery-check-in-day">Day</label>
                                    <input
                                        id="recovery-check-in-day"
                                        type="date"
                                        value={formState?.day ?? displayData.form.day}
                                        onChange={(event) => setFormState((current) => current ? {...current, day: event.target.value} : current)}
                                        className="block w-full rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    />
                                </div>
                            </div>

                            <div className="mt-6 space-y-4">
                                {fieldDefinitions.map((field) => (
                                    <ScoreField
                                        key={field.key}
                                        label={field.label}
                                        hint={field.hint}
                                        polarity={field.polarity}
                                        value={formState?.[field.key] ?? displayData.form.defaults[field.key]}
                                        onChange={(value) => handleFieldChange(field.key, value)}
                                    />
                                ))}
                            </div>

                            {submitError ? (
                                <div className="mt-5 rounded-2xl border border-rose-200 bg-rose-50/90 p-4 text-sm leading-7 text-rose-800 dark:border-rose-800/60 dark:bg-rose-950/30 dark:text-rose-100">
                                    {submitError}
                                </div>
                            ) : null}

                            {submitSuccess ? (
                                <div className="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50/90 p-4 text-sm leading-7 text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-950/30 dark:text-emerald-100">
                                    {submitSuccess}
                                </div>
                            ) : null}

                            <div className="mt-6 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                <div className="text-sm leading-7 text-gray-600 dark:text-gray-300">
                                    Use <span className="font-semibold text-gray-900 dark:text-white">1</span> for very low/poor and <span className="font-semibold text-gray-900 dark:text-white">5</span> for very high/good. The readiness signal rewards motivation and sleep, while fatigue, soreness, and stress act like anchors.
                                </div>
                                <button
                                    type="submit"
                                    disabled={submitting || !formState}
                                    className="inline-flex items-center justify-center gap-2 rounded-2xl bg-strava-orange px-5 py-3 text-sm font-semibold text-white transition hover:bg-orange-600 disabled:cursor-not-allowed disabled:opacity-70"
                                >
                                    {submitting ? 'Saving check-in…' : 'Save check-in'}
                                    <span aria-hidden="true">→</span>
                                </button>
                            </div>
                        </form>
                    </div>

                    <div className="space-y-6">
                        <SnapshotPanel
                            title="Latest snapshot"
                            record={displayData.latestCheckIn}
                            copy="This mirrors the latest recorded wellness state, whether it was today or earlier. It gives the route enough memory to feel informative, not just transactional."
                        />
                        <SnapshotPanel
                            title="Today’s entry"
                            record={displayData.todayCheckIn}
                            copy="If today is already logged, this panel makes the current baseline visible before you decide to update it."
                        />
                    </div>
                </section>
            ) : null}

            <section className="glass-panel rounded-[32px] p-6">
                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <div className="section-kicker">Migration note</div>
                        <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">A deliberately small write flow with real leverage</h2>
                    </div>
                    <Link
                        to="/race-planner"
                        className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                    >
                        Open a more complex planner route
                        <span aria-hidden="true">→</span>
                    </Link>
                </div>
                <div className="mt-5 space-y-3 text-sm leading-7 text-gray-600 dark:text-gray-300">
                    {[
                        'This route establishes the simplest possible live persistence loop in the preview app: load, edit, save, and immediately reflect the server response.',
                        'That makes it the perfect rehearsal before moving into race-event and training-block forms, where the stakes rise but the interaction pattern stays familiar.',
                        'It also repairs a genuine UX smell in the legacy app: a useful daily ritual no longer has to hide behind a dashboard modal to be first-class.',
                    ].map((item) => (
                        <div key={item} className="rounded-2xl border border-gray-200 bg-white/80 p-4 dark:border-gray-800 dark:bg-gray-950/30">
                            {item}
                        </div>
                    ))}
                </div>
            </section>
        </div>
    );
}
