import {type FormEvent, useEffect, useMemo, useState} from 'react';
import {buildAppPath} from '../lib/bootstrap';
import {
    createTrainingPlanPreview,
    fetchTrainingPlanFormPreview,
    type TrainingPlanFormBootstrapResponse,
    type TrainingPlanFormSubmitPayload,
} from '../lib/training-plan-form-api';

type TrainingPlanTypeValue = 'race' | 'training';
type ScheduleKey = 'swimDays' | 'bikeDays' | 'runDays' | 'longRideDays' | 'longRunDays';
type MetricKey = 'cyclingFtp' | 'runningThresholdPaceDisplay' | 'swimmingCssDisplay' | 'weeklyRunningVolume' | 'weeklyBikingVolume';

interface TrainingPlanCreateModalProps {
    basePath: string;
    isOpen: boolean;
    afterTrainingPlanId?: string;
    targetRaceEventId?: string;
    onClose: () => void;
    onSaved: () => void;
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

function buildSubmitPayload(formState: TrainingPlanFormState): TrainingPlanFormSubmitPayload {
    const cyclingFtp = formState.performanceMetrics.cyclingFtp.trim();
    const weeklyRunningVolume = formState.performanceMetrics.weeklyRunningVolume.trim();
    const weeklyBikingVolume = formState.performanceMetrics.weeklyBikingVolume.trim();

    return {
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

export function TrainingPlanCreateModal({
    basePath,
    isOpen,
    afterTrainingPlanId,
    targetRaceEventId,
    onClose,
    onSaved,
}: TrainingPlanCreateModalProps) {
    const [bootstrap, setBootstrap] = useState<TrainingPlanFormBootstrapResponse | null>(null);
    const [formState, setFormState] = useState<TrainingPlanFormState | null>(null);
    const [loading, setLoading] = useState(false);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [submitError, setSubmitError] = useState<string | null>(null);

    useEffect(() => {
        if (!isOpen) {
            return;
        }

        const abortController = new AbortController();

        setBootstrap(null);
        setFormState(null);
        setLoading(true);
        setLoadError(null);
        setSubmitError(null);

        fetchTrainingPlanFormPreview(basePath, {
            afterTrainingPlanId,
            targetRaceEventId,
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
    }, [afterTrainingPlanId, basePath, isOpen, targetRaceEventId]);

    useEffect(() => {
        if (!isOpen) {
            return;
        }

        const handleEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape' && !submitting) {
                onClose();
            }
        };

        window.addEventListener('keydown', handleEscape);

        return () => window.removeEventListener('keydown', handleEscape);
    }, [isOpen, onClose, submitting]);

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

    if (!isOpen) {
        return null;
    }

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

    const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (!formState) {
            return;
        }

        setSubmitting(true);
        setSubmitError(null);

        try {
            await createTrainingPlanPreview(basePath, buildSubmitPayload(formState));
            onSaved();
            onClose();
        } catch (error) {
            setSubmitError(error instanceof Error ? error.message : 'Could not save the plan.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 md:p-8">
            <button type="button" className="absolute inset-0 bg-gray-950/55 backdrop-blur-sm" aria-label="Close modal overlay" onClick={submitting ? undefined : onClose} />
            <section className="relative z-10 max-h-[92vh] w-full max-w-5xl overflow-hidden rounded-[36px] border border-white/70 bg-white/92 shadow-[0_45px_120px_-45px_rgba(15,23,42,0.65)] backdrop-blur-xl dark:border-gray-800 dark:bg-gray-950/94 dark:shadow-none">
                <div className="border-b border-gray-200/80 px-6 py-5 dark:border-gray-800 md:px-8">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <div className="section-kicker">React modal flow</div>
                            <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">Create a plan without leaving the preview shell.</h2>
                            <p className="mt-3 max-w-3xl text-sm leading-7 text-gray-600 dark:text-gray-300">
                                This is the first fully React-managed training-plan modal in the preview app. It loads defaults from JSON, saves through the live Symfony backend, and keeps the preview timeline in sync.
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={submitting ? undefined : onClose}
                            className="inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-gray-200 bg-white text-gray-600 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:border-gray-600"
                            aria-label="Close training plan modal"
                        >
                            ✕
                        </button>
                    </div>
                </div>

                <div className="max-h-[calc(92vh-132px)] overflow-y-auto px-6 py-6 md:px-8 md:py-8">
                    {loading ? (
                        <div className="grid gap-4 lg:grid-cols-[1.05fr_0.95fr]">
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
                    ) : null}

                    {!loading && loadError ? (
                        <div className="rounded-[28px] border border-rose-200 bg-rose-50/90 p-6 text-sm leading-7 text-rose-800 dark:border-rose-800/60 dark:bg-rose-950/30 dark:text-rose-100">
                            <div className="text-xs font-semibold uppercase tracking-[0.24em]">Could not load modal data</div>
                            <p className="mt-3">{loadError}</p>
                            <div className="mt-5 flex flex-wrap gap-3">
                                <a
                                    href={buildAppPath(basePath, `training-plan${targetRaceEventId ? `?targetRaceEventId=${targetRaceEventId}` : ''}`)}
                                    className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                                >
                                    Open legacy modal route
                                    <span aria-hidden="true">↗</span>
                                </a>
                            </div>
                        </div>
                    ) : null}

                    {!loading && !loadError && bootstrap && formState ? (
                        <form className="space-y-6" onSubmit={handleSubmit}>
                            <div className="grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
                                <div className="space-y-6">
                                    {bootstrap.context.afterTrainingPlan ? (
                                        <div className="rounded-[28px] border border-emerald-200 bg-emerald-50/90 p-5 text-sm leading-7 text-emerald-900 dark:border-emerald-800/60 dark:bg-emerald-950/30 dark:text-emerald-100">
                                            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-emerald-700 dark:text-emerald-300">Sequential handoff</div>
                                            <div className="mt-3 font-semibold">This new plan starts right after {bootstrap.context.afterTrainingPlan.title ?? formatLabel(bootstrap.context.afterTrainingPlan.type)} ends.</div>
                                            <div className="mt-1 text-emerald-800/80 dark:text-emerald-100/80">Last plan ended on {bootstrap.context.afterTrainingPlan.endDay}.</div>
                                        </div>
                                    ) : null}

                                    {bootstrap.context.suggestedRaceEvent ? (
                                        <div className="rounded-[28px] border border-amber-200 bg-amber-50/90 p-5 text-sm leading-7 text-amber-900 dark:border-amber-800/60 dark:bg-amber-950/30 dark:text-amber-100">
                                            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-amber-700 dark:text-amber-300">Suggested anchor</div>
                                            <div className="mt-3 font-semibold">{bootstrap.context.suggestedRaceEvent.title}</div>
                                            <div className="mt-1 text-amber-800/80 dark:text-amber-100/80">{bootstrap.context.suggestedRaceEvent.day} · {formatLabel(bootstrap.context.suggestedRaceEvent.profile)}</div>
                                        </div>
                                    ) : null}

                                    <section className="rounded-[30px] border border-gray-200 bg-white/90 p-6 dark:border-gray-800 dark:bg-gray-900/60">
                                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Foundation</div>
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
                                                    value={formState.endDay}
                                                    onChange={(event) => updateField('endDay', event.target.value)}
                                                    className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                                />
                                            </div>
                                        </div>
                                    </section>

                                    <section className="rounded-[30px] border border-gray-200 bg-white/90 p-6 dark:border-gray-800 dark:bg-gray-900/60">
                                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Intent</div>
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

                                    <section className="rounded-[30px] border border-gray-200 bg-white/90 p-6 dark:border-gray-800 dark:bg-gray-900/60">
                                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Weekly structure</div>
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
                                                <p className="text-sm leading-7 text-gray-500 dark:text-gray-400">Choose a discipline first to unlock the schedule presets and performance defaults.</p>
                                            ) : null}
                                        </div>
                                    </section>
                                </div>

                                <div className="space-y-6">
                                    <section className="rounded-[30px] border border-gray-200 bg-white/90 p-6 dark:border-gray-800 dark:bg-gray-900/60">
                                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Performance anchors</div>
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
                                                            {bootstrap.options.runningWorkoutTargetModes.map((mode) => (
                                                                <option key={mode.value} value={mode.value}>{formatLabel(mode.value)}</option>
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

                                    <section className="rounded-[30px] border border-gray-200 bg-white/90 p-6 dark:border-gray-800 dark:bg-gray-900/60">
                                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Notes</div>
                                        <textarea
                                            value={formState.notes}
                                            onChange={(event) => updateField('notes', event.target.value)}
                                            rows={7}
                                            placeholder="What should this block protect, sharpen, or prepare for?"
                                            className="mt-5 block w-full rounded-[24px] border border-gray-200 bg-white px-4 py-4 text-sm leading-7 text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                        />
                                    </section>

                                    {submitError ? (
                                        <div className="rounded-[24px] border border-rose-200 bg-rose-50/90 px-4 py-4 text-sm leading-7 text-rose-800 dark:border-rose-800/60 dark:bg-rose-950/30 dark:text-rose-100">
                                            {submitError}
                                        </div>
                                    ) : null}
                                </div>
                            </div>

                            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 pt-6 dark:border-gray-800">
                                <div className="text-xs uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Backed by preview JSON · saved through live Symfony logic</div>
                                <div className="flex flex-wrap gap-3">
                                    <button
                                        type="button"
                                        onClick={onClose}
                                        disabled={submitting}
                                        className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={submitting}
                                        className="inline-flex items-center gap-2 rounded-2xl bg-strava-orange px-5 py-3 text-sm font-semibold text-white transition hover:bg-orange-600 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {submitting ? 'Saving plan…' : 'Create plan in preview'}
                                        <span aria-hidden="true">→</span>
                                    </button>
                                </div>
                            </div>
                        </form>
                    ) : null}
                </div>
            </section>
        </div>
    );
}
