import {type FormEvent, useEffect, useMemo, useState} from 'react';
import {Link} from 'react-router-dom';
import {StatCard} from './stat-card';
import {buildAppPath} from '../lib/bootstrap';
import {
    createTrainingPlanPreview,
    deleteTrainingPlanPreview,
    fetchTrainingPlanFormPreview,
    type TrainingPlanFormBootstrapResponse,
    type TrainingPlanFormSubmitPayload,
} from '../lib/training-plan-form-api';

type TrainingPlanTypeValue = 'race' | 'training';
type ScheduleKey = 'swimDays' | 'bikeDays' | 'runDays' | 'longRideDays' | 'longRunDays';
type MetricKey = 'cyclingFtp' | 'runningThresholdPaceDisplay' | 'swimmingCssDisplay' | 'weeklyRunningVolume' | 'weeklyBikingVolume';

export interface TrainingPlanEditorQuery {
    trainingPlanId?: string;
    afterTrainingPlanId?: string;
    targetRaceEventId?: string;
}

interface TrainingPlanEditorProps {
    basePath: string;
    query?: TrainingPlanEditorQuery;
    mode?: 'route' | 'modal';
    onSaved?: () => void;
    onCancel?: () => void;
}

interface TrainingPlanFormState {
    type: TrainingPlanTypeValue;
    title: string;
    startDay: string;
    endDay: string;
    targetRaceEventId: string;
    discipline: string;
    targetRaceProfile: string;
    trainingFocus: string;
    trainingBlockStyle: string;
    runningWorkoutTargetMode: string;
    runHillSessionsEnabled: boolean;
    notes: string;
    sportSchedule: Record<ScheduleKey, number[]>;
    performanceMetrics: Record<MetricKey, string>;
}

const isoDayOptions = [
    {value: 1, label: 'Mon'},
    {value: 2, label: 'Tue'},
    {value: 3, label: 'Wed'},
    {value: 4, label: 'Thu'},
    {value: 5, label: 'Fri'},
    {value: 6, label: 'Sat'},
    {value: 7, label: 'Sun'},
];

function formatLabel(value: string | null): string | null {
    if (!value) {
        return null;
    }

    return value
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replace(/[-_]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

function formatPaceSeconds(value?: number): string {
    if (!value) {
        return '';
    }

    const minutes = Math.floor(value / 60);
    const seconds = value % 60;

    return `${minutes}:${String(seconds).padStart(2, '0')}`;
}

function parsePaceSeconds(value: string): number | undefined {
    const trimmed = value.trim();

    if (!trimmed) {
        return undefined;
    }

    const match = trimmed.match(/^(\d{1,2}):(\d{2})$/);
    if (!match) {
        return undefined;
    }

    return (Number(match[1]) * 60) + Number(match[2]);
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('en', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(new Date(`${value}T12:00:00`));
}

function createInitialFormState(bootstrap: TrainingPlanFormBootstrapResponse): TrainingPlanFormState {
    return {
        type: bootstrap.defaults.type,
        title: bootstrap.defaults.title ?? '',
        startDay: bootstrap.defaults.startDay,
        endDay: bootstrap.defaults.endDay,
        targetRaceEventId: bootstrap.defaults.targetRaceEventId ?? '',
        discipline: bootstrap.defaults.discipline ?? '',
        targetRaceProfile: bootstrap.defaults.targetRaceProfile ?? '',
        trainingFocus: bootstrap.defaults.trainingFocus ?? '',
        trainingBlockStyle: bootstrap.defaults.trainingBlockStyle,
        runningWorkoutTargetMode: bootstrap.defaults.runningWorkoutTargetMode,
        runHillSessionsEnabled: bootstrap.defaults.runHillSessionsEnabled,
        notes: bootstrap.defaults.notes ?? '',
        sportSchedule: {
            swimDays: bootstrap.defaults.sportSchedule.swimDays ?? [],
            bikeDays: bootstrap.defaults.sportSchedule.bikeDays ?? [],
            runDays: bootstrap.defaults.sportSchedule.runDays ?? [],
            longRideDays: bootstrap.defaults.sportSchedule.longRideDays ?? [],
            longRunDays: bootstrap.defaults.sportSchedule.longRunDays ?? [],
        },
        performanceMetrics: {
            cyclingFtp: String(bootstrap.defaults.performanceMetrics.cyclingFtp ?? ''),
            runningThresholdPaceDisplay: formatPaceSeconds(bootstrap.defaults.performanceMetrics.runningThresholdPace),
            swimmingCssDisplay: formatPaceSeconds(bootstrap.defaults.performanceMetrics.swimmingCss),
            weeklyRunningVolume: String(bootstrap.defaults.performanceMetrics.weeklyRunningVolume ?? ''),
            weeklyBikingVolume: String(bootstrap.defaults.performanceMetrics.weeklyBikingVolume ?? ''),
        },
    };
}

function buildSubmitPayload(
    bootstrap: TrainingPlanFormBootstrapResponse,
    formState: TrainingPlanFormState,
): TrainingPlanFormSubmitPayload {
    const cyclingFtp = formState.performanceMetrics.cyclingFtp.trim();
    const weeklyRunningVolume = formState.performanceMetrics.weeklyRunningVolume.trim();
    const weeklyBikingVolume = formState.performanceMetrics.weeklyBikingVolume.trim();

    return {
        trainingPlanId: bootstrap.context.trainingPlan?.id,
        type: formState.type,
        title: formState.title.trim(),
        startDay: formState.startDay,
        endDay: formState.endDay,
        targetRaceEventId: formState.targetRaceEventId || undefined,
        discipline: formState.discipline || undefined,
        swimDays: formState.sportSchedule.swimDays.length > 0 ? formState.sportSchedule.swimDays : undefined,
        bikeDays: formState.sportSchedule.bikeDays.length > 0 ? formState.sportSchedule.bikeDays : undefined,
        runDays: formState.sportSchedule.runDays.length > 0 ? formState.sportSchedule.runDays : undefined,
        longRideDays: formState.sportSchedule.longRideDays.length > 0 ? formState.sportSchedule.longRideDays : undefined,
        longRunDays: formState.sportSchedule.longRunDays.length > 0 ? formState.sportSchedule.longRunDays : undefined,
        cyclingFtp: cyclingFtp ? Number(cyclingFtp) : undefined,
        runningThresholdPace: parsePaceSeconds(formState.performanceMetrics.runningThresholdPaceDisplay),
        swimmingCss: parsePaceSeconds(formState.performanceMetrics.swimmingCssDisplay),
        weeklyRunningVolume: weeklyRunningVolume ? Number(weeklyRunningVolume) : undefined,
        weeklyBikingVolume: weeklyBikingVolume ? Number(weeklyBikingVolume) : undefined,
        targetRaceProfile: formState.targetRaceProfile || undefined,
        trainingFocus: formState.trainingFocus || undefined,
        trainingBlockStyle: formState.trainingBlockStyle || undefined,
        runningWorkoutTargetMode: formState.runningWorkoutTargetMode || undefined,
        runHillSessionsEnabled: formState.runHillSessionsEnabled,
        notes: formState.notes.trim() || undefined,
    };
}

function DayPicker({
    label,
    values,
    onToggle,
}: {
    label: string;
    values: number[];
    onToggle: (day: number) => void;
}) {
    return (
        <div>
            <label className="mb-2 block text-xs font-medium uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">{label}</label>
            <div className="flex flex-wrap gap-2">
                {isoDayOptions.map((day) => {
                    const active = values.includes(day.value);

                    return (
                        <button
                            key={day.value}
                            type="button"
                            onClick={() => onToggle(day.value)}
                            className={`inline-flex h-10 min-w-10 items-center justify-center rounded-2xl border px-3 text-xs font-semibold transition ${active
                                ? 'border-orange-500 bg-strava-orange text-white shadow-sm'
                                : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:border-gray-600'
                            }`}
                        >
                            {day.label}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

export function TrainingPlanEditor({
    basePath,
    query,
    mode = 'route',
    onSaved,
    onCancel,
}: TrainingPlanEditorProps) {
    const [bootstrap, setBootstrap] = useState<TrainingPlanFormBootstrapResponse | null>(null);
    const [formState, setFormState] = useState<TrainingPlanFormState | null>(null);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [submitMessage, setSubmitMessage] = useState<string | null>(null);

    useEffect(() => {
        const abortController = new AbortController();

        setBootstrap(null);
        setFormState(null);
        setLoading(true);
        setLoadError(null);
        setSubmitError(null);
        setSubmitMessage(null);

        fetchTrainingPlanFormPreview(basePath, {
            trainingPlanId: query?.trainingPlanId,
            afterTrainingPlanId: query?.afterTrainingPlanId,
            targetRaceEventId: query?.targetRaceEventId,
            signal: abortController.signal,
        })
            .then((response) => {
                if (abortController.signal.aborted) {
                    return;
                }

                setBootstrap(response);
                setFormState(createInitialFormState(response));
                setLoading(false);
            })
            .catch((error: unknown) => {
                if (abortController.signal.aborted) {
                    return;
                }

                setLoadError(error instanceof Error ? error.message : 'Could not load the training-plan form.');
                setLoading(false);
            });

        return () => abortController.abort();
    }, [basePath, query?.afterTrainingPlanId, query?.targetRaceEventId, query?.trainingPlanId]);

    const raceProfileOptions = useMemo(() => {
        if (!bootstrap || !formState) {
            return [];
        }

        return bootstrap.options.raceProfileGroups
            .map((group) => ({
                family: group.family,
                options: group.options.filter((option) => !formState.discipline || option.disciplineValues.includes(formState.discipline)),
            }))
            .filter((group) => group.options.length > 0);
    }, [bootstrap, formState]);

    const isEditMode = bootstrap?.mode === 'edit';
    const isBusy = submitting || deleting;
    const isRacePlan = formState?.type === 'race';
    const isTrainingPlan = formState?.type === 'training';
    const discipline = formState?.discipline;
    const showTriathlonSchedule = discipline === 'triathlon';
    const showRunningSchedule = discipline === 'running';
    const showCyclingSchedule = discipline === 'cycling';
    const showTriathlonFocus = isTrainingPlan && discipline === 'triathlon';
    const showRunningControls = isTrainingPlan && discipline === 'running';
    const showCyclingMetrics = discipline === 'triathlon' || discipline === 'cycling';
    const showRunningMetrics = discipline === 'triathlon' || discipline === 'running';
    const showSwimmingMetrics = discipline === 'triathlon';
    const selectedRace = bootstrap?.options.raceEvents.find((raceEvent) => raceEvent.id === formState?.targetRaceEventId) ?? null;

    const updateField = <K extends keyof TrainingPlanFormState,>(key: K, value: TrainingPlanFormState[K]) => {
        setFormState((current) => current ? {...current, [key]: value} : current);
    };

    const updateMetricField = (key: MetricKey, value: string) => {
        setFormState((current) => current ? {
            ...current,
            performanceMetrics: {
                ...current.performanceMetrics,
                [key]: value,
            },
        } : current);
    };

    const toggleDay = (key: ScheduleKey, day: number) => {
        setFormState((current) => {
            if (!current) {
                return current;
            }

            const nextValues = current.sportSchedule[key].includes(day)
                ? current.sportSchedule[key].filter((currentDay) => currentDay !== day)
                : [...current.sportSchedule[key], day].sort((left, right) => left - right);

            return {
                ...current,
                sportSchedule: {
                    ...current.sportSchedule,
                    [key]: nextValues,
                },
            };
        });
    };

    async function handleSubmit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (!bootstrap || !formState) {
            return;
        }

        setSubmitting(true);
        setSubmitError(null);
        setSubmitMessage(null);

        try {
            await createTrainingPlanPreview(basePath, buildSubmitPayload(bootstrap, formState));
            setSubmitMessage(isEditMode ? 'Training plan updated in the live backend.' : 'Training plan created in the live backend.');
            onSaved?.();
        } catch (error) {
            setSubmitError(error instanceof Error ? error.message : 'Could not save the plan.');
        } finally {
            setSubmitting(false);
        }
    }

    async function handleDelete() {
        const currentTrainingPlanId = bootstrap?.context.trainingPlan?.id;
        if (!currentTrainingPlanId) {
            return;
        }

        const confirmed = window.confirm('Delete this training plan from the preview timeline?');
        if (!confirmed) {
            return;
        }

        setDeleting(true);
        setSubmitError(null);
        setSubmitMessage(null);

        try {
            await deleteTrainingPlanPreview(basePath, currentTrainingPlanId);
            setSubmitMessage('Training plan deleted from the live backend.');
            onSaved?.();
        } catch (error) {
            setSubmitError(error instanceof Error ? error.message : 'Could not delete the plan.');
        } finally {
            setDeleting(false);
        }
    }

    if (loading) {
        return (
            <section className="glass-panel rounded-[32px] p-6">
                <div className="section-kicker">Loading</div>
                <div className="mt-5 grid gap-4 xl:grid-cols-[1.05fr_0.95fr]">
                    <div className="animate-pulse space-y-4 rounded-[28px] border border-gray-200 bg-white/90 p-6 dark:border-gray-800 dark:bg-gray-900/60">
                        <div className="h-4 w-32 rounded-full bg-gray-200 dark:bg-gray-800" />
                        <div className="h-10 w-2/3 rounded-full bg-gray-200 dark:bg-gray-800" />
                        <div className="h-24 rounded-[24px] bg-gray-100 dark:bg-gray-800" />
                    </div>
                    <div className="animate-pulse space-y-4 rounded-[28px] border border-gray-200 bg-white/90 p-6 dark:border-gray-800 dark:bg-gray-900/60">
                        <div className="h-4 w-24 rounded-full bg-gray-200 dark:bg-gray-800" />
                        <div className="h-12 rounded-[20px] bg-gray-100 dark:bg-gray-800" />
                        <div className="h-12 rounded-[20px] bg-gray-100 dark:bg-gray-800" />
                        <div className="h-12 rounded-[20px] bg-gray-100 dark:bg-gray-800" />
                    </div>
                </div>
            </section>
        );
    }

    if (loadError) {
        return (
            <section className="rounded-[32px] border border-rose-200 bg-rose-50/90 p-6 text-sm leading-7 text-rose-800 dark:border-rose-800/60 dark:bg-rose-950/30 dark:text-rose-100">
                <div className="text-xs font-semibold uppercase tracking-[0.24em]">Could not load plan editor data</div>
                <p className="mt-3">{loadError}</p>
                <div className="mt-5 flex flex-wrap gap-3">
                    <a
                        href={buildAppPath(basePath, `training-plan${query?.trainingPlanId
                            ? `?trainingPlanId=${query.trainingPlanId}`
                            : query?.targetRaceEventId
                                ? `?targetRaceEventId=${query.targetRaceEventId}`
                                : ''}`)}
                        className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                    >
                        Open legacy modal route
                        <span aria-hidden="true">↗</span>
                    </a>
                    {onCancel ? (
                        <button
                            type="button"
                            onClick={onCancel}
                            className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                        >
                            Close
                        </button>
                    ) : null}
                </div>
            </section>
        );
    }

    if (!bootstrap || !formState) {
        return null;
    }

    return (
        <div className="space-y-6">
            {mode === 'route' ? (
                <section className="glass-panel rounded-[36px] p-6 md:p-8">
                    <div className="grid gap-8 xl:grid-cols-[1.08fr_0.92fr]">
                        <div>
                            <div className="section-kicker">Plan editor route</div>
                            <h1 className="mt-5 max-w-4xl text-4xl font-semibold tracking-tight text-gray-900 dark:text-white md:text-5xl">
                                {isEditMode ? 'Edit a live training plan in a real React workspace.' : 'Create a live training plan without hiding the workflow in a modal.'}
                            </h1>
                            <p className="mt-5 max-w-3xl text-base leading-8 text-gray-600 dark:text-gray-300 md:text-lg">
                                The backend logic is unchanged, but the form now has route-sized breathing room: better context, cleaner navigation, and far less modal gymnastics when planning a season.
                            </p>
                            <div className="mt-6 flex flex-wrap gap-3">
                                <Link
                                    to="/training-plans"
                                    className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                                >
                                    Back to training plans
                                    <span aria-hidden="true">←</span>
                                </Link>
                                <Link
                                    to="/race-events"
                                    className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                                >
                                    Manage race events
                                    <span aria-hidden="true">→</span>
                                </Link>
                                <Link
                                    to="/training-blocks"
                                    className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                                >
                                    Manage training blocks
                                    <span aria-hidden="true">→</span>
                                </Link>
                            </div>
                        </div>
                        <div className="rounded-[32px] border border-orange-200 bg-[linear-gradient(135deg,rgba(255,255,255,0.96),rgba(255,247,237,0.96))] p-5 shadow-[0_45px_120px_-45px_rgba(15,23,42,0.65)] dark:border-orange-900/40 dark:bg-[linear-gradient(135deg,rgba(17,24,39,0.94),rgba(67,20,7,0.28))]">
                            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-orange-700 dark:text-orange-200">Why this seam works</div>
                            <div className="mt-4 space-y-3 text-sm leading-7 text-gray-700 dark:text-gray-200">
                                {[
                                    'The JSON bootstrap and save/delete API already exist, so the route can reuse proven backend behavior instead of inventing a new contract.',
                                    'It upgrades an existing React-managed modal into a shareable route-sized workspace, which is exactly the kind of migration leverage we want.',
                                    'It directly helps the race planner and training plans surfaces, because both already depend on this editor flow today.',
                                ].map((item) => (
                                    <div key={item} className="rounded-2xl border border-white/80 bg-white/80 p-4 dark:border-gray-800 dark:bg-gray-950/40">
                                        {item}
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </section>
            ) : null}

            <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Editor mode" value={isEditMode ? 'Edit' : 'Create'} hint={isEditMode ? 'Loaded from the live training-plan repository.' : 'Bootstrapped from real planner defaults and race context.'} tone="orange" />
                <StatCard label="Plan type" value={formatLabel(formState.type) ?? 'Plan'} hint="Race and training plans still share one live backend flow." tone="emerald" />
                <StatCard label="Discipline" value={formatLabel(formState.discipline) ?? 'Choose'} hint="Discipline filters schedule presets, metrics, and compatible race profiles." tone="blue" />
                <StatCard label="Linked race" value={selectedRace?.title ?? 'Optional'} hint={selectedRace ? `${formatDate(selectedRace.day)} · ${formatLabel(selectedRace.profile)}` : 'A race link is optional unless this is a race plan.'} />
            </section>

            <form className="space-y-6" onSubmit={(event) => void handleSubmit(event)}>
                <div className="grid gap-6 xl:grid-cols-[1.08fr_0.92fr]">
                    <div className="space-y-6">
                        {isEditMode && bootstrap.context.trainingPlan ? (
                            <div className="rounded-[28px] border border-sky-200 bg-sky-50/90 p-5 text-sm leading-7 text-sky-900 dark:border-sky-800/60 dark:bg-sky-950/30 dark:text-sky-100">
                                <div className="text-xs font-semibold uppercase tracking-[0.24em] text-sky-700 dark:text-sky-300">Editing live plan</div>
                                <div className="mt-3 font-semibold">{bootstrap.context.trainingPlan.title ?? formatLabel(bootstrap.context.trainingPlan.type)}</div>
                                <div className="mt-1 text-sky-800/80 dark:text-sky-100/80">{bootstrap.context.trainingPlan.startDay} → {bootstrap.context.trainingPlan.endDay}</div>
                            </div>
                        ) : null}

                        {!isEditMode && bootstrap.context.afterTrainingPlan ? (
                            <div className="rounded-[28px] border border-emerald-200 bg-emerald-50/90 p-5 text-sm leading-7 text-emerald-900 dark:border-emerald-800/60 dark:bg-emerald-950/30 dark:text-emerald-100">
                                <div className="text-xs font-semibold uppercase tracking-[0.24em] text-emerald-700 dark:text-emerald-300">Sequential handoff</div>
                                <div className="mt-3 font-semibold">This plan starts right after {bootstrap.context.afterTrainingPlan.title ?? formatLabel(bootstrap.context.afterTrainingPlan.type)} ends.</div>
                                <div className="mt-1 text-emerald-800/80 dark:text-emerald-100/80">Last plan ended on {bootstrap.context.afterTrainingPlan.endDay}.</div>
                            </div>
                        ) : null}

                        {!isEditMode && bootstrap.context.suggestedRaceEvent ? (
                            <div className="rounded-[28px] border border-amber-200 bg-amber-50/90 p-5 text-sm leading-7 text-amber-900 dark:border-amber-800/60 dark:bg-amber-950/30 dark:text-amber-100">
                                <div className="text-xs font-semibold uppercase tracking-[0.24em] text-amber-700 dark:text-amber-300">Suggested anchor</div>
                                <div className="mt-3 font-semibold">{bootstrap.context.suggestedRaceEvent.title}</div>
                                <div className="mt-1 text-amber-800/80 dark:text-amber-100/80">{bootstrap.context.suggestedRaceEvent.day} · {formatLabel(bootstrap.context.suggestedRaceEvent.profile)}</div>
                            </div>
                        ) : null}

                        <section className="glass-panel rounded-[32px] p-6 md:p-7">
                            <div className="section-kicker">Foundation</div>
                            <div className="mt-5 grid gap-4 md:grid-cols-2">
                                <div>
                                    <label className="mb-2 block text-xs font-medium uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Plan type</label>
                                    <select
                                        value={formState.type}
                                        onChange={(event) => updateField('type', event.target.value as TrainingPlanTypeValue)}
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    >
                                        {bootstrap.options.types.map((type) => (
                                            <option key={type.value} value={type.value}>{formatLabel(type.value)}</option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label className="mb-2 block text-xs font-medium uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Discipline</label>
                                    <select
                                        value={formState.discipline}
                                        onChange={(event) => updateField('discipline', event.target.value)}
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    >
                                        <option value="">Choose a discipline</option>
                                        {bootstrap.options.disciplines.map((disciplineOption) => (
                                            <option key={disciplineOption.value} value={disciplineOption.value}>{formatLabel(disciplineOption.value)}</option>
                                        ))}
                                    </select>
                                </div>
                                <div className="md:col-span-2">
                                    <label className="mb-2 block text-xs font-medium uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Title</label>
                                    <input
                                        value={formState.title}
                                        onChange={(event) => updateField('title', event.target.value)}
                                        placeholder="Early season build, marathon bridge, 70.3 prep..."
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="mb-2 block text-xs font-medium uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Start day</label>
                                    <input
                                        required
                                        type="date"
                                        value={formState.startDay}
                                        onChange={(event) => updateField('startDay', event.target.value)}
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="mb-2 block text-xs font-medium uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">End day</label>
                                    <input
                                        required
                                        type="date"
                                        min={formState.startDay}
                                        value={formState.endDay}
                                        onChange={(event) => updateField('endDay', event.target.value)}
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    />
                                </div>
                            </div>
                        </section>

                        <section className="glass-panel rounded-[32px] p-6 md:p-7">
                            <div className="section-kicker">Intent</div>
                            <div className="mt-5 grid gap-4 md:grid-cols-2">
                                {isRacePlan ? (
                                    <div>
                                        <label className="mb-2 block text-xs font-medium uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Linked race</label>
                                        <select
                                            value={formState.targetRaceEventId}
                                            onChange={(event) => updateField('targetRaceEventId', event.target.value)}
                                            className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                        >
                                            <option value="">No linked race</option>
                                            {bootstrap.options.raceEvents.map((raceEvent) => (
                                                <option key={raceEvent.id} value={raceEvent.id}>{raceEvent.day} · {raceEvent.title}</option>
                                            ))}
                                        </select>
                                    </div>
                                ) : null}
                                <div>
                                    <label className="mb-2 block text-xs font-medium uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Target distance</label>
                                    <select
                                        value={formState.targetRaceProfile}
                                        onChange={(event) => updateField('targetRaceProfile', event.target.value)}
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    >
                                        <option value="">Choose a distance</option>
                                        {raceProfileOptions.map((group) => (
                                            <optgroup key={group.family} label={formatLabel(group.family) ?? group.family}>
                                                {group.options.map((option) => (
                                                    <option key={option.value} value={option.value}>{formatLabel(option.value)}</option>
                                                ))}
                                            </optgroup>
                                        ))}
                                    </select>
                                </div>
                                {isTrainingPlan ? (
                                    <div>
                                        <label className="mb-2 block text-xs font-medium uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Training block style</label>
                                        <select
                                            value={formState.trainingBlockStyle}
                                            onChange={(event) => updateField('trainingBlockStyle', event.target.value)}
                                            className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                        >
                                            {bootstrap.options.trainingBlockStyles.map((style) => (
                                                <option key={style.value} value={style.value}>{formatLabel(style.value)}</option>
                                            ))}
                                        </select>
                                    </div>
                                ) : null}
                                {showTriathlonFocus ? (
                                    <div>
                                        <label className="mb-2 block text-xs font-medium uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Training focus</label>
                                        <select
                                            value={formState.trainingFocus}
                                            onChange={(event) => updateField('trainingFocus', event.target.value)}
                                            className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                        >
                                            <option value="">Balanced focus</option>
                                            {bootstrap.options.trainingFocuses.map((focus) => (
                                                <option key={focus.value} value={focus.value}>{formatLabel(focus.value)}</option>
                                            ))}
                                        </select>
                                    </div>
                                ) : null}
                            </div>
                        </section>

                        <section className="glass-panel rounded-[32px] p-6 md:p-7">
                            <div className="section-kicker">Weekly structure</div>
                            <div className="mt-5 grid gap-5 md:grid-cols-2">
                                {showTriathlonSchedule ? (
                                    <>
                                        <DayPicker label="Swim days" values={formState.sportSchedule.swimDays} onToggle={(day) => toggleDay('swimDays', day)} />
                                        <DayPicker label="Bike days" values={formState.sportSchedule.bikeDays} onToggle={(day) => toggleDay('bikeDays', day)} />
                                        <DayPicker label="Run days" values={formState.sportSchedule.runDays} onToggle={(day) => toggleDay('runDays', day)} />
                                        <DayPicker label="Long ride" values={formState.sportSchedule.longRideDays} onToggle={(day) => toggleDay('longRideDays', day)} />
                                        <DayPicker label="Long run" values={formState.sportSchedule.longRunDays} onToggle={(day) => toggleDay('longRunDays', day)} />
                                    </>
                                ) : null}
                                {showRunningSchedule ? (
                                    <>
                                        <DayPicker label="Run days" values={formState.sportSchedule.runDays} onToggle={(day) => toggleDay('runDays', day)} />
                                        <DayPicker label="Long run day" values={formState.sportSchedule.longRunDays} onToggle={(day) => toggleDay('longRunDays', day)} />
                                    </>
                                ) : null}
                                {showCyclingSchedule ? (
                                    <>
                                        <DayPicker label="Ride days" values={formState.sportSchedule.bikeDays} onToggle={(day) => toggleDay('bikeDays', day)} />
                                        <DayPicker label="Long ride day" values={formState.sportSchedule.longRideDays} onToggle={(day) => toggleDay('longRideDays', day)} />
                                    </>
                                ) : null}
                                {!discipline ? (
                                    <p className="text-sm leading-7 text-gray-500 dark:text-gray-400">Choose a discipline first to unlock schedule presets and compatible planning defaults.</p>
                                ) : null}
                            </div>
                        </section>
                    </div>

                    <div className="space-y-6">
                        <section className="glass-panel rounded-[32px] p-6 md:p-7">
                            <div className="section-kicker">Performance anchors</div>
                            <div className="mt-5 space-y-4">
                                {showCyclingMetrics ? (
                                    <div>
                                        <label className="mb-2 block text-xs font-medium uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Cycling FTP</label>
                                        <div className="flex items-center gap-2">
                                            <input value={formState.performanceMetrics.cyclingFtp} onChange={(event) => updateMetricField('cyclingFtp', event.target.value)} type="number" min="50" max="500" step="1" className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white" />
                                            <span className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-400">W</span>
                                        </div>
                                    </div>
                                ) : null}
                                {showRunningMetrics ? (
                                    <div>
                                        <label className="mb-2 block text-xs font-medium uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Threshold pace</label>
                                        <div className="flex items-center gap-2">
                                            <input value={formState.performanceMetrics.runningThresholdPaceDisplay} onChange={(event) => updateMetricField('runningThresholdPaceDisplay', event.target.value)} placeholder="4:30" pattern="[0-9]{1,2}:[0-5][0-9]" className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white" />
                                            <span className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-400">/km</span>
                                        </div>
                                    </div>
                                ) : null}
                                {showSwimmingMetrics ? (
                                    <div>
                                        <label className="mb-2 block text-xs font-medium uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Swim CSS</label>
                                        <div className="flex items-center gap-2">
                                            <input value={formState.performanceMetrics.swimmingCssDisplay} onChange={(event) => updateMetricField('swimmingCssDisplay', event.target.value)} placeholder="1:35" pattern="[0-9]{1,2}:[0-5][0-9]" className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white" />
                                            <span className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-400">/100m</span>
                                        </div>
                                    </div>
                                ) : null}
                                {showRunningMetrics ? (
                                    <div>
                                        <label className="mb-2 block text-xs font-medium uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Running volume</label>
                                        <div className="flex items-center gap-2">
                                            <input value={formState.performanceMetrics.weeklyRunningVolume} onChange={(event) => updateMetricField('weeklyRunningVolume', event.target.value)} type="number" min="0" max="300" step="0.1" className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white" />
                                            <span className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-400">km/wk</span>
                                        </div>
                                    </div>
                                ) : null}
                                {showCyclingMetrics ? (
                                    <div>
                                        <label className="mb-2 block text-xs font-medium uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Biking volume</label>
                                        <div className="flex items-center gap-2">
                                            <input value={formState.performanceMetrics.weeklyBikingVolume} onChange={(event) => updateMetricField('weeklyBikingVolume', event.target.value)} type="number" min="0" max="40" step="0.1" className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white" />
                                            <span className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-400">hrs/wk</span>
                                        </div>
                                    </div>
                                ) : null}
                                {showRunningControls ? (
                                    <>
                                        <div>
                                            <label className="mb-2 block text-xs font-medium uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Running workout default</label>
                                            <select
                                                value={formState.runningWorkoutTargetMode}
                                                onChange={(event) => updateField('runningWorkoutTargetMode', event.target.value)}
                                                className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                            >
                                                {bootstrap.options.runningWorkoutTargetModes.map((modeOption) => (
                                                    <option key={modeOption.value} value={modeOption.value}>{formatLabel(modeOption.value)}</option>
                                                ))}
                                            </select>
                                        </div>
                                        <label className="flex items-start gap-3 rounded-[24px] border border-gray-200 bg-gray-50 px-4 py-4 text-sm leading-7 text-gray-700 dark:border-gray-800 dark:bg-gray-900/70 dark:text-gray-200">
                                            <input type="checkbox" checked={formState.runHillSessionsEnabled} onChange={(event) => updateField('runHillSessionsEnabled', event.target.checked)} className="mt-1 h-4 w-4 rounded border-gray-300 text-strava-orange focus:ring-orange-400" />
                                            <span>
                                                <span className="block font-semibold text-gray-900 dark:text-white">Include hill sessions when terrain allows</span>
                                                <span className="mt-1 block text-xs uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">Flat-road athletes can leave this off.</span>
                                            </span>
                                        </label>
                                    </>
                                ) : null}
                            </div>
                        </section>

                        <section className="glass-panel rounded-[32px] p-6 md:p-7">
                            <div className="section-kicker">Notes</div>
                            <textarea
                                value={formState.notes}
                                onChange={(event) => updateField('notes', event.target.value)}
                                rows={8}
                                placeholder="What should this plan protect, sharpen, or prepare for?"
                                className="mt-5 block w-full rounded-[24px] border border-gray-200 bg-white px-4 py-4 text-sm leading-7 text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                            />
                        </section>

                        {submitMessage ? (
                            <div className="rounded-[24px] border border-emerald-200 bg-emerald-50/90 px-4 py-4 text-sm leading-7 text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-950/30 dark:text-emerald-100">
                                {submitMessage}
                            </div>
                        ) : null}

                        {submitError ? (
                            <div className="rounded-[24px] border border-rose-200 bg-rose-50/90 px-4 py-4 text-sm leading-7 text-rose-800 dark:border-rose-800/60 dark:bg-rose-950/30 dark:text-rose-100">
                                {submitError}
                            </div>
                        ) : null}

                        {selectedRace ? (
                            <div className="rounded-[24px] border border-amber-200 bg-amber-50/90 px-4 py-4 text-sm leading-7 text-amber-900 dark:border-amber-800/60 dark:bg-amber-950/30 dark:text-amber-100">
                                <div className="text-xs font-semibold uppercase tracking-[0.24em] text-amber-700 dark:text-amber-300">Current race link</div>
                                <div className="mt-2 font-semibold">{selectedRace.title}</div>
                                <div className="mt-1 text-amber-800/80 dark:text-amber-100/80">{formatDate(selectedRace.day)} · {formatLabel(selectedRace.profile)}</div>
                            </div>
                        ) : null}
                    </div>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 pt-6 dark:border-gray-800">
                    <div className="text-xs uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Backed by preview JSON · saved through live Symfony logic</div>
                    <div className="flex flex-wrap gap-3">
                        {onCancel ? (
                            <button
                                type="button"
                                onClick={onCancel}
                                disabled={isBusy}
                                className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                            >
                                Cancel
                            </button>
                        ) : null}
                        {isEditMode && bootstrap.context.trainingPlan ? (
                            <button
                                type="button"
                                onClick={() => void handleDelete()}
                                disabled={isBusy}
                                className="inline-flex items-center gap-2 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800 transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-60 dark:border-rose-800/60 dark:bg-rose-950/30 dark:text-rose-100"
                            >
                                {deleting ? 'Deleting plan…' : 'Delete plan'}
                            </button>
                        ) : null}
                        <button
                            type="submit"
                            disabled={isBusy}
                            className="inline-flex items-center gap-2 rounded-2xl bg-strava-orange px-5 py-3 text-sm font-semibold text-white transition hover:bg-orange-600 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {submitting ? 'Saving plan…' : isEditMode ? 'Save changes' : 'Create plan'}
                            <span aria-hidden="true">→</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    );
}