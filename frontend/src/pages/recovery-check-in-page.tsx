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
        <div className="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-950/40">
            <div className="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                <div>
                    <h3 className="text-sm font-semibold text-gray-900 dark:text-white">{label}</h3>
                    <p className="mt-0.5 max-w-2xl text-[11px] leading-5 text-gray-500 dark:text-gray-400">{hint}</p>
                </div>
                <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide ${polarity === 'reduce'
                    ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-200'
                    : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200'
                }`}>
                    {polarity === 'reduce' ? 'Lower is better' : 'Higher is better'}
                </span>
            </div>
            <div className="mt-3 flex flex-wrap gap-2">
                {scoreOptions.map((option) => (
                    <button
                        key={option}
                        type="button"
                        onClick={() => onChange(option)}
                        className={`inline-flex h-10 min-w-10 items-center justify-center rounded-lg border px-3 text-sm font-semibold transition ${scoreButtonClass(value === option)}`}
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
        <div className="rounded-lg bg-gray-50 p-2.5 dark:bg-gray-800/60">
            <div className="text-[10px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">{label}</div>
            <div className="mt-1 text-[1rem] font-semibold text-gray-900 dark:text-white">{value}/5</div>
        </div>
    );
}

function SnapshotPanel({record, title, copy}: {record: RecoveryCheckInPreviewRecord | null; title: string; copy: string}) {
    return (
        <section className="rounded-lg border border-gray-200 bg-white p-3.5 shadow-xs dark:border-gray-800 dark:bg-gray-950/40">
            <div className="text-sm font-semibold text-gray-700 dark:text-gray-200">{title}</div>
            <h2 className="mt-1 text-base font-semibold text-gray-900 dark:text-white">{record ? formatDay(record.day) : 'No data yet'}</h2>
            <p className="mt-1.5 text-[11px] leading-5 text-gray-500 dark:text-gray-400">{copy}</p>
            {record ? (
                <>
                    <div className="mt-3 grid gap-2.5 sm:grid-cols-2">
                        <ScoreBadge label="Fatigue" value={record.fatigue} />
                        <ScoreBadge label="Soreness" value={record.soreness} />
                        <ScoreBadge label="Stress" value={record.stress} />
                        <ScoreBadge label="Motivation" value={record.motivation} />
                        <ScoreBadge label="Sleep quality" value={record.sleepQuality} />
                        <div className="rounded-lg border border-orange-200 bg-orange-50 p-2.5 dark:border-orange-900/50 dark:bg-orange-950/35">
                            <div className="text-[10px] font-semibold uppercase tracking-[0.18em] text-orange-700 dark:text-orange-200">Readiness signal</div>
                            <div className="mt-1 text-[1rem] font-semibold text-orange-900 dark:text-orange-100">{record.readinessScore.toFixed(1)}/5</div>
                            <div className="mt-1 text-[11px] leading-5 text-orange-800/80 dark:text-orange-100/80">Derived from sleep and motivation minus fatigue, soreness, and stress.</div>
                        </div>
                    </div>
                    <div className="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-3 text-[13px] leading-6 text-gray-700 dark:border-gray-800 dark:bg-gray-950/25 dark:text-gray-300">
                        Average self-report: <span className="font-semibold text-gray-900 dark:text-white">{record.averageScore.toFixed(1)}/5</span>
                    </div>
                </>
            ) : (
                <div className="mt-3 rounded-lg border border-dashed border-gray-300 bg-gray-50 p-3 text-[13px] leading-6 text-gray-600 dark:border-gray-700 dark:bg-gray-950/25 dark:text-gray-300">
                    Save a check-in to start building a useful recovery snapshot.
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
        <div className="space-y-6 pb-6">
            <section className="ui-section">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div className="min-w-0">
                        <h1 className="text-[1.8rem] font-bold tracking-tight text-gray-900 dark:text-white">Recovery check-in</h1>
                        <p className="mt-1 max-w-3xl text-[13px] leading-6 text-gray-500 dark:text-gray-400">
                            Log today’s recovery state and keep the dashboard’s readiness interpretation honest.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <a href={buildAppPath(bootstrap.basePath, displayData?.legacyPath ?? 'recovery-check-in?redirectTo=/dashboard')} className="ui-button">
                            Open classic check-in
                        </a>
                        <Link to="/dashboard" className="ui-button">
                            Dashboard
                        </Link>
                        <button
                            type="button"
                            onClick={() => {
                                setSubmitSuccess(null);
                                reload();
                            }}
                            className="ui-button"
                        >
                            Refresh form data
                        </button>
                    </div>
                </div>
            </section>

            <section className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Check-in status" value={displayData ? describeState(displayData.summary.state) : 'Loading…'} hint="Whether today already has a recorded wellness entry." tone="orange" compact />
                <StatCard label="Latest entry" value={displayData ? formatDay(displayData.summary.latestDay) : 'Waiting…'} hint="Most recent recorded day in the wellness log." tone="emerald" compact />
                <StatCard label="Readiness signal" value={displayData ? formatScore(displayData.summary.readinessScore) : '—'} hint="Higher is better: motivation and sleep lift it, fatigue/soreness/stress drag it down." tone="blue" compact />
                <StatCard label="Last refresh" value={displayData ? formatRequestedAt(displayData.requestedAt) : 'Waiting…'} hint="Shows when the payload was last loaded or saved." tone="slate" compact />
            </section>

            {loading && !displayData ? (
                <section className="ui-section text-sm text-gray-600 dark:text-gray-300">
                    Loading the recovery check-in form.
                </section>
            ) : null}

            {error ? (
                <section className="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                    {error}
                </section>
            ) : null}

            {displayData ? (
                <section className="grid gap-4 xl:grid-cols-[1.1fr_0.9fr]">
                    <div className="space-y-3.5">
                        <section className={`rounded-lg border p-4 ${statePanelClasses(displayData.summary.state)}`}>
                            <div className="text-[10px] font-semibold uppercase tracking-[0.18em]">Current state</div>
                            <h2 className="mt-1.5 text-base font-semibold">{describeState(displayData.summary.state)}</h2>
                            <p className="mt-1.5 max-w-3xl text-[13px] leading-6 opacity-90">
                                {displayData.summary.state === 'updated-today'
                                    ? 'Today is already covered, so you can refine the entry if the morning suddenly took a turn.'
                                    : displayData.summary.state === 'stale'
                                        ? 'There is wellness history, but not for today yet. One quick update keeps the dashboard’s readiness interpretation honest.'
                                        : 'No wellness entries exist yet, which makes this a nice small but meaningful write-flow seam for the app.'}
                            </p>
                        </section>

                        <form onSubmit={handleSubmit} className="ui-section">
                            <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                <div>
                                    <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Morning recovery check-in</h2>
                                    <p className="mt-1 max-w-3xl text-[13px] leading-6 text-gray-500 dark:text-gray-400">
                                        A quick self-report helps the app balance objective Garmin data with how recovered you actually feel.
                                    </p>
                                </div>
                                <div className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 dark:border-gray-800 dark:bg-gray-950/35">
                                    <label className="mb-2 block text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" htmlFor="recovery-check-in-day">Day</label>
                                    <input
                                        id="recovery-check-in-day"
                                        type="date"
                                        value={formState?.day ?? displayData.form.day}
                                        onChange={(event) => setFormState((current) => current ? {...current, day: event.target.value} : current)}
                                        className="ui-input"
                                    />
                                </div>
                            </div>

                            <div className="mt-5 space-y-3">
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
                                <div className="mt-5 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm leading-7 text-rose-800 dark:border-rose-800/60 dark:bg-rose-950/30 dark:text-rose-100">
                                    {submitError}
                                </div>
                            ) : null}

                            {submitSuccess ? (
                                <div className="mt-5 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm leading-7 text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-950/30 dark:text-emerald-100">
                                    {submitSuccess}
                                </div>
                            ) : null}

                            <div className="mt-5 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                <div className="text-[13px] leading-6 text-gray-600 dark:text-gray-300">
                                    Use <span className="font-semibold text-gray-900 dark:text-white">1</span> for very low/poor and <span className="font-semibold text-gray-900 dark:text-white">5</span> for very high/good. The readiness signal rewards motivation and sleep, while fatigue, soreness, and stress act like anchors.
                                </div>
                                <button
                                    type="submit"
                                    disabled={submitting || !formState}
                                    className="ui-button ui-button-primary disabled:cursor-not-allowed disabled:opacity-70"
                                >
                                    {submitting ? 'Saving check-in…' : 'Save check-in'}
                                </button>
                            </div>
                        </form>
                    </div>

                    <div className="space-y-3.5">
                        <SnapshotPanel
                            title="Latest snapshot"
                            record={displayData.latestCheckIn}
                            copy="The most recent recorded wellness state."
                        />
                        <SnapshotPanel
                            title="Today’s entry"
                            record={displayData.todayCheckIn}
                            copy="Today’s current baseline before you make changes."
                        />
                    </div>
                </section>
            ) : null}
        </div>
    );
}
