import {type FormEvent, type ReactNode, useCallback, useEffect, useMemo, useState} from 'react';
import {Link} from 'react-router-dom';
import {StatCard} from './stat-card';
import {buildAppPath} from '../lib/bootstrap';
import {
    buildPlannedSessionEditorPath,
    confirmPlannedSessionLinkPreview,
    deletePlannedSessionPreview,
    fetchPlannedSessionPreview,
    savePlannedSessionPreview,
    type PlannedSessionEditorBootstrapResponse,
    type PlannedSessionFormSubmitPayload,
    type PlannedSessionFormWorkoutStep,
    type PlannedSessionRecommendation,
    type PlannedSessionTemplateActivity,
    unlinkPlannedSessionPreview,
} from '../lib/planned-session-preview-api';

export interface PlannedSessionEditorQuery {
    plannedSessionId?: string;
    day?: string;
}

interface PlannedSessionEditorProps {
    basePath: string;
    query?: PlannedSessionEditorQuery;
    mode?: 'route' | 'modal';
    onSaved?: (day: string) => void;
    onCancel?: () => void;
}

interface PlannedSessionFormState {
    day: string;
    title: string;
    activityType: string;
    notes: string;
    targetLoad: string;
    manualTargetLoadOverride: boolean;
    targetDurationInMinutes: string;
    targetDurationInSecondsPart: string;
    targetIntensity: string;
    templateActivityId: string;
    workoutSteps: PlannedSessionFormWorkoutStep[];
}

function createInitialFormState(bootstrap: PlannedSessionEditorBootstrapResponse): PlannedSessionFormState {
    return {
        day: bootstrap.defaults.day,
        title: bootstrap.defaults.title,
        activityType: bootstrap.defaults.activityType,
        notes: bootstrap.defaults.notes,
        targetLoad: bootstrap.defaults.targetLoad === null ? '' : String(bootstrap.defaults.targetLoad),
        manualTargetLoadOverride: bootstrap.defaults.manualTargetLoadOverride,
        targetDurationInMinutes: bootstrap.defaults.targetDurationInMinutes === null ? '' : String(bootstrap.defaults.targetDurationInMinutes),
        targetDurationInSecondsPart: bootstrap.defaults.targetDurationInSecondsPart === null ? '' : String(bootstrap.defaults.targetDurationInSecondsPart),
        targetIntensity: bootstrap.defaults.targetIntensity ?? '',
        templateActivityId: bootstrap.defaults.templateActivityId ?? '',
        workoutSteps: bootstrap.defaults.workoutSteps,
    };
}

function buildSubmitPayload(
    bootstrap: PlannedSessionEditorBootstrapResponse,
    formState: PlannedSessionFormState,
): PlannedSessionFormSubmitPayload {
    return {
        plannedSessionId: bootstrap.context.plannedSession?.id,
        day: formState.day,
        title: formState.title.trim(),
        activityType: formState.activityType,
        notes: formState.notes.trim() || undefined,
        targetLoad: formState.targetLoad.trim() || undefined,
        manualTargetLoadOverride: formState.manualTargetLoadOverride ? '1' : '0',
        targetDurationInMinutes: formState.targetDurationInMinutes.trim() || undefined,
        targetDurationInSecondsPart: formState.targetDurationInSecondsPart.trim() || undefined,
        targetIntensity: formState.targetIntensity || undefined,
        templateActivityId: formState.templateActivityId || undefined,
        workoutSteps: formState.workoutSteps,
    };
}

function createDraftId(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    return `planned-step-${Math.random().toString(36).slice(2, 10)}`;
}

function createWorkoutStep(parentBlockId: string | null = null, type = 'steady'): PlannedSessionFormWorkoutStep {
    return {
        itemId: createDraftId(),
        parentBlockId,
        type,
        label: '',
        repetitions: '1',
        targetType: type === 'repeatBlock' ? 'time' : 'time',
        conditionType: '',
        durationInMinutes: '',
        durationInSecondsPart: '',
        distanceInMeters: '',
        targetPace: '',
        targetPower: '',
        targetHeartRate: '',
    };
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('en', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(new Date(`${value}T12:00:00`));
}

function formatShortDate(value: string): string {
    return new Intl.DateTimeFormat('en', {
        month: 'short',
        day: 'numeric',
    }).format(new Date(`${value}T12:00:00`));
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

function formatLabel(value: string | null): string | null {
    if (!value) {
        return null;
    }

    return value
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replace(/[-_]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

function buildMonthPreviewPath(day: string): string {
    return `/monthly-stats/month-${day.slice(0, 7)}`;
}

function buildMatchTone(matchStatus: string | null): string {
    switch (matchStatus) {
        case 'linked':
            return 'border-emerald-200 bg-emerald-50/90 text-emerald-900 dark:border-emerald-800/60 dark:bg-emerald-950/30 dark:text-emerald-100';
        case 'suggested':
            return 'border-amber-200 bg-amber-50/90 text-amber-900 dark:border-amber-800/60 dark:bg-amber-950/30 dark:text-amber-100';
        default:
            return 'border-sky-200 bg-sky-50/90 text-sky-900 dark:border-sky-800/60 dark:bg-sky-950/30 dark:text-sky-100';
    }
}

function buildStepTone(type: string): string {
    switch (type) {
        case 'warmup':
            return 'border-cyan-200 bg-cyan-50/90 dark:border-cyan-900/40 dark:bg-cyan-950/30';
        case 'interval':
            return 'border-orange-200 bg-orange-50/90 dark:border-orange-900/40 dark:bg-orange-950/30';
        case 'repeatBlock':
            return 'border-violet-200 bg-violet-50/90 dark:border-violet-900/40 dark:bg-violet-950/30';
        case 'recovery':
            return 'border-emerald-200 bg-emerald-50/90 dark:border-emerald-900/40 dark:bg-emerald-950/30';
        case 'cooldown':
            return 'border-slate-200 bg-slate-50/90 dark:border-slate-800 dark:bg-slate-950/30';
        default:
            return 'border-gray-200 bg-white/85 dark:border-gray-800 dark:bg-gray-950/40';
    }
}

export function PlannedSessionEditor({
    basePath,
    query,
    mode = 'route',
    onSaved,
    onCancel,
}: PlannedSessionEditorProps) {
    const [bootstrap, setBootstrap] = useState<PlannedSessionEditorBootstrapResponse | null>(null);
    const [formState, setFormState] = useState<PlannedSessionFormState | null>(null);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [linkActionLoading, setLinkActionLoading] = useState(false);
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [submitMessage, setSubmitMessage] = useState<string | null>(null);

    const loadPreview = useCallback(async (signal?: AbortSignal) => {
        const response = await fetchPlannedSessionPreview(basePath, {
            plannedSessionId: query?.plannedSessionId,
            day: query?.day,
            signal,
        });

        setBootstrap(response);
        setFormState(createInitialFormState(response));
        return response;
    }, [basePath, query?.day, query?.plannedSessionId]);

    useEffect(() => {
        const abortController = new AbortController();

        setBootstrap(null);
        setFormState(null);
        setLoading(true);
        setLoadError(null);
        setSubmitError(null);
        setSubmitMessage(null);

        loadPreview(abortController.signal)
            .then(() => {
                if (!abortController.signal.aborted) {
                    setLoading(false);
                }
            })
            .catch((error: unknown) => {
                if (abortController.signal.aborted) {
                    return;
                }

                setLoadError(error instanceof Error ? error.message : 'Could not load the planned-session editor.');
                setLoading(false);
            });

        return () => abortController.abort();
    }, [loadPreview]);

    const isEditMode = bootstrap?.mode === 'edit';
    const isBusy = submitting || deleting || linkActionLoading;
    const selectedActivityType = formState?.activityType;
    const activityTypeOptions = bootstrap?.options.activityTypes ?? [];
    const selectedActivityTypeOption = activityTypeOptions.find((activityType) => activityType.value === selectedActivityType) ?? null;
    const supportsPower = selectedActivityTypeOption?.supportsPower ?? false;
    const stepTypeLabels = useMemo(
        () => new Map((bootstrap?.options.stepTypes ?? []).map((stepType) => [stepType.value, stepType.label])),
        [bootstrap?.options.stepTypes],
    );
    const stepTypeIsContainer = useMemo(
        () => new Map((bootstrap?.options.stepTypes ?? []).map((stepType) => [stepType.value, stepType.isContainer])),
        [bootstrap?.options.stepTypes],
    );
    const recommendationGroups = bootstrap?.options.recommendations ?? {};

    const updateField = <K extends keyof PlannedSessionFormState,>(key: K, value: PlannedSessionFormState[K]) => {
        setFormState((current) => current ? {...current, [key]: value} : current);
    };

    const updateWorkoutSteps = (updater: (current: PlannedSessionFormWorkoutStep[]) => PlannedSessionFormWorkoutStep[]) => {
        setFormState((current) => current ? {...current, workoutSteps: updater(current.workoutSteps)} : current);
    };

    const collectDescendantIds = useCallback((itemId: string, steps: PlannedSessionFormWorkoutStep[]): string[] => {
        const directChildren = steps.filter((step) => step.parentBlockId === itemId).map((step) => step.itemId);

        return directChildren.flatMap((childId) => [childId, ...collectDescendantIds(childId, steps)]);
    }, []);

    const addWorkoutStep = (parentBlockId: string | null = null, type = 'steady') => {
        updateWorkoutSteps((current) => [...current, createWorkoutStep(parentBlockId, type)]);
    };

    const removeWorkoutStep = (itemId: string) => {
        updateWorkoutSteps((current) => {
            const descendantIds = new Set([itemId, ...collectDescendantIds(itemId, current)]);

            return current.filter((step) => !descendantIds.has(step.itemId));
        });
    };

    const updateWorkoutStep = (itemId: string, patch: Partial<PlannedSessionFormWorkoutStep>) => {
        updateWorkoutSteps((current) => current.map((step) => step.itemId === itemId ? {...step, ...patch} : step));
    };

    const changeWorkoutStepType = (itemId: string, nextType: string) => {
        updateWorkoutSteps((current) => {
            const nextSteps = [...current];
            const stepIndex = nextSteps.findIndex((step) => step.itemId === itemId);
            if (stepIndex === -1) {
                return current;
            }

            const currentStep = nextSteps[stepIndex];
            const nextIsContainer = stepTypeIsContainer.get(nextType) ?? false;
            const currentIsContainer = stepTypeIsContainer.get(currentStep.type) ?? false;
            const descendantIds = currentIsContainer && !nextIsContainer
                ? new Set(collectDescendantIds(itemId, current))
                : new Set<string>();

            const updatedStep: PlannedSessionFormWorkoutStep = nextIsContainer
                ? {
                    ...currentStep,
                    type: nextType,
                    repetitions: currentStep.repetitions || '2',
                    targetType: 'time',
                    conditionType: '',
                    durationInMinutes: '',
                    durationInSecondsPart: '',
                    distanceInMeters: '',
                    targetPace: '',
                    targetPower: '',
                    targetHeartRate: '',
                }
                : {
                    ...currentStep,
                    type: nextType,
                    targetType: currentStep.targetType || 'time',
                };

            nextSteps[stepIndex] = updatedStep;

            return descendantIds.size === 0
                ? nextSteps
                : nextSteps.filter((step) => !descendantIds.has(step.itemId));
        });
    };

    const applyRecommendation = (recommendation: PlannedSessionRecommendation) => {
        if (!formState) {
            return;
        }

        setFormState({
            ...formState,
            activityType: recommendation.activityType,
            title: recommendation.title ?? formState.title,
            notes: recommendation.notes ?? '',
            targetLoad: recommendation.targetLoad === null ? '' : String(recommendation.targetLoad),
            manualTargetLoadOverride: recommendation.manualTargetLoadOverride,
            targetDurationInMinutes: recommendation.targetDurationInMinutes === null ? '' : String(recommendation.targetDurationInMinutes),
            targetDurationInSecondsPart: recommendation.targetDurationInSecondsPart === null ? '' : String(recommendation.targetDurationInSecondsPart),
            targetIntensity: recommendation.targetIntensity ?? '',
            templateActivityId: recommendation.templateActivityId ?? '',
            workoutSteps: recommendation.workoutSteps.length > 0 ? recommendation.workoutSteps : formState.workoutSteps,
        });
        setSubmitMessage(`Loaded ${recommendation.title ?? recommendation.activityTypeLabel} into the editor.`);
        setSubmitError(null);
    };

    const applyTemplateActivity = (activity: PlannedSessionTemplateActivity) => {
        if (!formState) {
            return;
        }

        const totalMinutes = Math.floor(activity.movingTimeInSeconds / 60);
        const secondsPart = activity.movingTimeInSeconds % 60;

        setFormState({
            ...formState,
            activityType: activity.activityType,
            title: formState.title || activity.name,
            targetLoad: activity.estimatedLoad === null ? formState.targetLoad : String(activity.estimatedLoad),
            targetDurationInMinutes: String(totalMinutes),
            targetDurationInSecondsPart: secondsPart === 0 ? '' : String(secondsPart),
            templateActivityId: activity.activityId,
        });
        setSubmitMessage(`Reused ${activity.name} as the session template anchor.`);
        setSubmitError(null);
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
            await savePlannedSessionPreview(basePath, buildSubmitPayload(bootstrap, formState));
            setSubmitMessage(isEditMode ? 'Planned session updated in the live backend.' : 'Planned session created in the live backend.');
            onSaved?.(formState.day);
        } catch (error) {
            setSubmitError(error instanceof Error ? error.message : 'Could not save the planned session.');
        } finally {
            setSubmitting(false);
        }
    }

    async function handleDelete() {
        const plannedSessionId = bootstrap?.context.plannedSession?.id;
        if (!plannedSessionId || !formState) {
            return;
        }

        const confirmed = window.confirm('Delete this planned session from the live planner?');
        if (!confirmed) {
            return;
        }

        setDeleting(true);
        setSubmitError(null);
        setSubmitMessage(null);

        try {
            await deletePlannedSessionPreview(basePath, plannedSessionId);
            setSubmitMessage('Planned session deleted from the live backend.');
            onSaved?.(formState.day);
        } catch (error) {
            setSubmitError(error instanceof Error ? error.message : 'Could not delete the planned session.');
        } finally {
            setDeleting(false);
        }
    }

    async function handleConfirmLink() {
        const plannedSessionId = bootstrap?.context.plannedSession?.id;
        const matchedActivity = bootstrap?.context.matchedActivity;

        if (!plannedSessionId || !matchedActivity) {
            return;
        }

        setLinkActionLoading(true);
        setSubmitError(null);
        setSubmitMessage(null);

        try {
            await confirmPlannedSessionLinkPreview(basePath, plannedSessionId, matchedActivity.activityId);
            await loadPreview();
            setSubmitMessage('Activity link confirmed in the live planner.');
        } catch (error) {
            setSubmitError(error instanceof Error ? error.message : 'Could not confirm the suggested activity link.');
        } finally {
            setLinkActionLoading(false);
        }
    }

    async function handleUnlink() {
        const plannedSessionId = bootstrap?.context.plannedSession?.id;
        if (!plannedSessionId) {
            return;
        }

        setLinkActionLoading(true);
        setSubmitError(null);
        setSubmitMessage(null);

        try {
            await unlinkPlannedSessionPreview(basePath, plannedSessionId);
            await loadPreview();
            setSubmitMessage('Removed the activity link from the live planner.');
        } catch (error) {
            setSubmitError(error instanceof Error ? error.message : 'Could not unlink the activity from the planned session.');
        } finally {
            setLinkActionLoading(false);
        }
    }

    function renderWorkoutStepTree(parentBlockId: string | null, depth = 0): ReactNode {
        if (!formState || !bootstrap) {
            return null;
        }

        const steps = formState.workoutSteps.filter((step) => step.parentBlockId === parentBlockId);
        if (steps.length === 0) {
            return null;
        }

        return (
            <div className={`space-y-3 ${depth > 0 ? 'mt-3 border-l border-dashed border-gray-300 pl-4 dark:border-gray-700' : ''}`}>
                {steps.map((step) => {
                    const isContainer = stepTypeIsContainer.get(step.type) ?? false;
                    const conditionOptions = bootstrap.options.conditionTypes.filter((conditionType) => {
                        if (step.targetType === 'heartRate') {
                            return ['holdTarget', 'untilBelow', 'untilAbove', 'lapButton'].includes(conditionType.value);
                        }

                        if (step.targetType === 'time') {
                            return ['', 'holdTarget', 'lapButton'].includes(conditionType.value) || conditionType.value === 'lapButton';
                        }

                        return conditionType.value === 'holdTarget';
                    });

                    return (
                        <div key={step.itemId} className={`rounded-[28px] border p-4 ${buildStepTone(step.type)}`}>
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Workout step</div>
                                    <div className="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{step.label || stepTypeLabels.get(step.type) || 'Step'}</div>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {isContainer ? (
                                        <button
                                            type="button"
                                            onClick={() => addWorkoutStep(step.itemId, 'interval')}
                                            className="inline-flex items-center gap-2 rounded-2xl border border-violet-200 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-[0.18em] text-violet-800 transition hover:bg-violet-50 dark:border-violet-800/60 dark:bg-gray-950/40 dark:text-violet-100"
                                        >
                                            Add child step
                                        </button>
                                    ) : null}
                                    <button
                                        type="button"
                                        onClick={() => removeWorkoutStep(step.itemId)}
                                        className="inline-flex items-center gap-2 rounded-2xl border border-rose-200 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-[0.18em] text-rose-700 transition hover:bg-rose-50 dark:border-rose-800/60 dark:bg-gray-950/40 dark:text-rose-100"
                                    >
                                        Remove
                                    </button>
                                </div>
                            </div>

                            <div className="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                <div>
                                    <label className="mb-2 block text-[11px] font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Type</label>
                                    <select
                                        value={step.type}
                                        onChange={(event) => changeWorkoutStepType(step.itemId, event.target.value)}
                                        className="block w-full rounded-[18px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    >
                                        {bootstrap.options.stepTypes.map((stepType) => (
                                            <option key={stepType.value} value={stepType.value}>{stepType.label}</option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label className="mb-2 block text-[11px] font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Label</label>
                                    <input
                                        value={step.label}
                                        onChange={(event) => updateWorkoutStep(step.itemId, {label: event.target.value})}
                                        placeholder={isContainer ? 'Main set, hill block…' : 'Steady, 1k reps, cooldown…'}
                                        className="block w-full rounded-[18px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="mb-2 block text-[11px] font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Repetitions</label>
                                    <input
                                        type="number"
                                        min="1"
                                        step="1"
                                        value={step.repetitions}
                                        onChange={(event) => updateWorkoutStep(step.itemId, {repetitions: event.target.value})}
                                        className="block w-full rounded-[18px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    />
                                </div>

                                {!isContainer ? (
                                    <>
                                        <div>
                                            <label className="mb-2 block text-[11px] font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Target type</label>
                                            <select
                                                value={step.targetType}
                                                onChange={(event) => updateWorkoutStep(step.itemId, {targetType: event.target.value, conditionType: ''})}
                                                className="block w-full rounded-[18px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                            >
                                                {bootstrap.options.targetTypes.map((targetType) => (
                                                    <option key={targetType.value} value={targetType.value}>{targetType.label}</option>
                                                ))}
                                            </select>
                                        </div>
                                        <div>
                                            <label className="mb-2 block text-[11px] font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Condition</label>
                                            <select
                                                value={step.conditionType}
                                                onChange={(event) => updateWorkoutStep(step.itemId, {conditionType: event.target.value})}
                                                className="block w-full rounded-[18px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                            >
                                                <option value="">Default</option>
                                                {conditionOptions.map((conditionType) => (
                                                    <option key={conditionType.value} value={conditionType.value}>{conditionType.label}</option>
                                                ))}
                                            </select>
                                        </div>
                                        {(step.targetType === 'time' || (step.targetType === 'heartRate' && step.conditionType === 'holdTarget')) ? (
                                            <div className="grid grid-cols-[minmax(0,1fr)_110px] gap-3 md:col-span-2 xl:col-span-1">
                                                <div>
                                                    <label className="mb-2 block text-[11px] font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Minutes</label>
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        step="1"
                                                        value={step.durationInMinutes}
                                                        onChange={(event) => updateWorkoutStep(step.itemId, {durationInMinutes: event.target.value})}
                                                        className="block w-full rounded-[18px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                                    />
                                                </div>
                                                <div>
                                                    <label className="mb-2 block text-[11px] font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Seconds</label>
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        max="59"
                                                        step="1"
                                                        value={step.durationInSecondsPart}
                                                        onChange={(event) => updateWorkoutStep(step.itemId, {durationInSecondsPart: event.target.value})}
                                                        className="block w-full rounded-[18px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                                    />
                                                </div>
                                            </div>
                                        ) : null}
                                        {step.targetType === 'distance' ? (
                                            <div>
                                                <label className="mb-2 block text-[11px] font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Distance (m)</label>
                                                <input
                                                    type="number"
                                                    min="0"
                                                    step="1"
                                                    value={step.distanceInMeters}
                                                    onChange={(event) => updateWorkoutStep(step.itemId, {distanceInMeters: event.target.value})}
                                                    className="block w-full rounded-[18px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                                />
                                            </div>
                                        ) : null}
                                        {step.targetType === 'heartRate' ? (
                                            <div>
                                                <label className="mb-2 block text-[11px] font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Target heart rate</label>
                                                <input
                                                    type="number"
                                                    min="0"
                                                    step="1"
                                                    value={step.targetHeartRate}
                                                    onChange={(event) => updateWorkoutStep(step.itemId, {targetHeartRate: event.target.value})}
                                                    className="block w-full rounded-[18px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                                />
                                            </div>
                                        ) : null}
                                        <div>
                                            <label className="mb-2 block text-[11px] font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Target pace</label>
                                            <input
                                                value={step.targetPace}
                                                onChange={(event) => updateWorkoutStep(step.itemId, {targetPace: event.target.value})}
                                                placeholder={selectedActivityType === 'Run' ? '4:30/km' : 'Optional'}
                                                className="block w-full rounded-[18px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                            />
                                        </div>
                                        {supportsPower ? (
                                            <div>
                                                <label className="mb-2 block text-[11px] font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Target power</label>
                                                <input
                                                    type="number"
                                                    min="0"
                                                    step="1"
                                                    value={step.targetPower}
                                                    onChange={(event) => updateWorkoutStep(step.itemId, {targetPower: event.target.value})}
                                                    placeholder="Optional watts"
                                                    className="block w-full rounded-[18px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                                />
                                            </div>
                                        ) : null}
                                    </>
                                ) : null}
                            </div>

                            {isContainer ? renderWorkoutStepTree(step.itemId, depth + 1) : null}
                        </div>
                    );
                })}
            </div>
        );
    }

    if (loading) {
        return (
            <section className="ui-section">
                <h1 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Loading planned session</h1>
                <div className="mt-5 grid gap-4 xl:grid-cols-[1.05fr_0.95fr]">
                    <div className="animate-pulse space-y-4 rounded-[28px] border border-gray-200 bg-white/90 p-6 dark:border-gray-800 dark:bg-gray-900/60">
                        <div className="h-4 w-32 rounded-full bg-gray-200 dark:bg-gray-800" />
                        <div className="h-10 w-2/3 rounded-full bg-gray-200 dark:bg-gray-800" />
                        <div className="h-28 rounded-[24px] bg-gray-100 dark:bg-gray-800" />
                    </div>
                    <div className="animate-pulse space-y-4 rounded-[28px] border border-gray-200 bg-white/90 p-6 dark:border-gray-800 dark:bg-gray-900/60">
                        <div className="h-4 w-24 rounded-full bg-gray-200 dark:bg-gray-800" />
                        <div className="h-16 rounded-[20px] bg-gray-100 dark:bg-gray-800" />
                        <div className="h-16 rounded-[20px] bg-gray-100 dark:bg-gray-800" />
                        <div className="h-16 rounded-[20px] bg-gray-100 dark:bg-gray-800" />
                    </div>
                </div>
            </section>
        );
    }

    if (loadError) {
        return (
            <section className="rounded-lg border border-rose-200 bg-rose-50/90 p-6 text-sm leading-7 text-rose-800 dark:border-rose-800/60 dark:bg-rose-950/30 dark:text-rose-100">
                <div className="text-sm font-semibold">Could not load planned-session data</div>
                <p className="mt-3">{loadError}</p>
                <div className="mt-5 flex flex-wrap gap-3">
                    <a
                        href={buildAppPath(basePath, bootstrap?.legacyPath ?? `planned-session${query?.plannedSessionId ? `?plannedSessionId=${query.plannedSessionId}` : query?.day ? `?day=${query.day}` : ''}`)}
                        className="ui-button"
                    >
                        Open classic editor
                    </a>
                    {onCancel ? (
                        <button
                            type="button"
                            onClick={onCancel}
                            className="ui-button"
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
                <section className="grid gap-4 xl:grid-cols-[1.08fr_0.92fr]">
                    <div className="ui-section">
                        <h1 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white md:text-2xl">
                            {isEditMode ? 'Planned session editor' : 'Create planned session'}
                        </h1>
                        <p className="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">
                            Edit the day, activity, load, workout structure, and planner link state in one place.
                        </p>
                        <div className="mt-4 flex flex-wrap gap-2">
                            <Link to={buildMonthPreviewPath(formState.day)} className="ui-button ui-button-primary">
                                Back to monthly stats
                            </Link>
                            <Link to={buildPlannedSessionEditorPath({day: formState.day})} className="ui-button">
                                New session on this day
                            </Link>
                            <a href={buildAppPath(basePath, bootstrap.legacyPath)} className="ui-button">
                                Open classic editor
                            </a>
                        </div>
                    </div>
                    <div className="ui-section">
                        <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Session notes</h2>
                        <div className="mt-3 space-y-3 text-sm leading-7 text-gray-600 dark:text-gray-300">
                            {[
                                'Save, delete, confirm-link, and unlink actions still use the live backend handlers.',
                                'Workout steps, template reuse, and recommendation cards stay visible alongside the form instead of being split across modals.',
                                'The editor drops back into the monthly calendar after save, which keeps the planning loop tight.',
                            ].map((item) => (
                                <div key={item} className="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-gray-900/40">
                                    {item}
                                </div>
                            ))}
                        </div>
                    </div>
                </section>
            ) : null}

            <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Editor mode" value={isEditMode ? 'Edit' : 'Create'} hint={isEditMode ? 'Loaded from the live planned-session repository.' : 'Bootstrapped with real day defaults and planner context.'} tone="orange" />
                <StatCard label="Session day" value={formatShortDate(formState.day)} hint={`Preview refreshed ${formatRequestedAt(bootstrap.requestedAt)}.`} tone="emerald" />
                <StatCard label="Activity" value={selectedActivityTypeOption?.label ?? 'Choose'} hint={supportsPower ? 'Power targets are available for this activity type.' : 'Pace and heart-rate targets stay front and center here.'} tone="blue" />
                <StatCard label="Link status" value={formatLabel(bootstrap.context.matchStatus ?? bootstrap.context.plannedSession?.linkStatus ?? 'unlinked') ?? 'Unlinked'} hint={bootstrap.context.matchedActivity ? `${bootstrap.context.matchedActivity.name} is ready to connect.` : 'No linked or suggested activity yet.'} />
            </section>

            <form className="space-y-6" onSubmit={(event) => void handleSubmit(event)}>
                <div className="grid gap-6 xl:grid-cols-[1.08fr_0.92fr]">
                    <div className="space-y-6">
                        <section className="ui-section">
                            <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Foundation</h2>
                            <div className="mt-5 grid gap-4 md:grid-cols-2">
                                <div>
                                    <label className="mb-2 block text-xs font-medium uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Day</label>
                                    <input
                                        required
                                        type="date"
                                        value={formState.day}
                                        onChange={(event) => updateField('day', event.target.value)}
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="mb-2 block text-xs font-medium uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Activity type</label>
                                    <select
                                        value={formState.activityType}
                                        onChange={(event) => updateField('activityType', event.target.value)}
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    >
                                        {bootstrap.options.activityTypes.map((activityType) => (
                                            <option key={activityType.value} value={activityType.value}>{activityType.label}</option>
                                        ))}
                                    </select>
                                </div>
                                <div className="md:col-span-2">
                                    <label className="mb-2 block text-xs font-medium uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Title</label>
                                    <input
                                        value={formState.title}
                                        onChange={(event) => updateField('title', event.target.value)}
                                        placeholder="Sunday long run, threshold ride, aerobic brick…"
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    />
                                </div>
                            </div>
                        </section>

                        <section className="ui-section">
                            <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Load & duration</h2>
                            <div className="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                <div>
                                    <label className="mb-2 block text-xs font-medium uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Target load</label>
                                    <input
                                        value={formState.targetLoad}
                                        onChange={(event) => updateField('targetLoad', event.target.value)}
                                        type="number"
                                        min="0"
                                        step="0.1"
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="mb-2 block text-xs font-medium uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Minutes</label>
                                    <input
                                        value={formState.targetDurationInMinutes}
                                        onChange={(event) => updateField('targetDurationInMinutes', event.target.value)}
                                        type="number"
                                        min="0"
                                        step="1"
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="mb-2 block text-xs font-medium uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Seconds</label>
                                    <input
                                        value={formState.targetDurationInSecondsPart}
                                        onChange={(event) => updateField('targetDurationInSecondsPart', event.target.value)}
                                        type="number"
                                        min="0"
                                        max="59"
                                        step="1"
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="mb-2 block text-xs font-medium uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Intensity</label>
                                    <select
                                        value={formState.targetIntensity}
                                        onChange={(event) => updateField('targetIntensity', event.target.value)}
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    >
                                        <option value="">No target intensity</option>
                                        {bootstrap.options.intensities.map((intensity) => (
                                            <option key={intensity.value} value={intensity.value}>{intensity.label}</option>
                                        ))}
                                    </select>
                                </div>
                                <div className="md:col-span-2 xl:col-span-2">
                                    <label className="flex items-start gap-3 rounded-[24px] border border-gray-200 bg-gray-50 px-4 py-4 text-sm leading-7 text-gray-700 dark:border-gray-800 dark:bg-gray-900/70 dark:text-gray-200">
                                        <input
                                            type="checkbox"
                                            checked={formState.manualTargetLoadOverride}
                                            onChange={(event) => updateField('manualTargetLoadOverride', event.target.checked)}
                                            className="mt-1 h-4 w-4 rounded border-gray-300 text-strava-orange focus:ring-orange-400"
                                        />
                                        <span>
                                            <span className="block font-semibold text-gray-900 dark:text-white">Treat the target load as a manual override</span>
                                            <span className="mt-1 block text-xs uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">Leave this off if workout targets should drive the estimate.</span>
                                        </span>
                                    </label>
                                </div>
                            </div>
                            {(bootstrap.estimatedLoad !== null || bootstrap.estimatedSourceLabel) ? (
                                <div className="mt-5 rounded-[24px] border border-sky-200 bg-sky-50/90 p-4 text-sm leading-7 text-sky-900 dark:border-sky-800/60 dark:bg-sky-950/30 dark:text-sky-100">
                                    <div className="text-xs font-semibold uppercase tracking-[0.24em] text-sky-700 dark:text-sky-300">Current estimate snapshot</div>
                                    <div className="mt-2 font-semibold">
                                        {bootstrap.estimatedLoad !== null ? `Estimated load ${bootstrap.estimatedLoad.toFixed(1)}` : 'No current estimated load'}
                                    </div>
                                    {bootstrap.estimatedSourceLabel ? (
                                        <div className="mt-1 text-sky-800/80 dark:text-sky-100/80">Source: {bootstrap.estimatedSourceLabel}</div>
                                    ) : null}
                                </div>
                            ) : null}
                        </section>

                        <section className="ui-section">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Workout builder</h2>
                                    <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Structured steps with nested repeat blocks.</p>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        onClick={() => addWorkoutStep(null, 'steady')}
                                        className="ui-button"
                                    >
                                        Add step
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => addWorkoutStep(null, 'repeatBlock')}
                                        className="ui-button"
                                    >
                                        Add repeat block
                                    </button>
                                </div>
                            </div>
                            <div className="mt-6">
                                {formState.workoutSteps.length > 0 ? renderWorkoutStepTree(null) : null}
                            </div>
                        </section>

                        <section className="ui-section">
                            <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Notes</h2>
                            <textarea
                                value={formState.notes}
                                onChange={(event) => updateField('notes', event.target.value)}
                                rows={8}
                                placeholder="Session intent, coaching guardrails, terrain notes, execution cues…"
                                className="mt-5 block w-full rounded-[24px] border border-gray-200 bg-white px-4 py-4 text-sm leading-7 text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                            />
                        </section>
                    </div>

                    <div className="space-y-6">
                        {bootstrap.context.matchedActivity ? (
                            <section className={`rounded-[28px] border p-5 text-sm leading-7 ${buildMatchTone(bootstrap.context.matchStatus)}`}>
                                <div className="text-xs font-semibold uppercase tracking-[0.24em] opacity-80">Activity link</div>
                                <div className="mt-3 text-lg font-semibold">{bootstrap.context.matchedActivity.name}</div>
                                <div className="mt-1 opacity-80">
                                    {bootstrap.context.matchedActivity.activityTypeLabel} · {formatDuration(bootstrap.context.matchedActivity.movingTime)}
                                </div>
                                <div className="mt-4 flex flex-wrap gap-3">
                                    {bootstrap.context.matchStatus !== 'linked' ? (
                                        <button
                                            type="button"
                                            onClick={() => void handleConfirmLink()}
                                            disabled={isBusy}
                                            className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                                        >
                                            Confirm link
                                            <span aria-hidden="true">→</span>
                                        </button>
                                    ) : null}
                                    {isEditMode ? (
                                        <button
                                            type="button"
                                            onClick={() => void handleUnlink()}
                                            disabled={isBusy}
                                            className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                                        >
                                            Remove link
                                        </button>
                                    ) : null}
                                </div>
                            </section>
                        ) : null}

                        {bootstrap.options.templateActivities.length > 0 ? (
                            <section className="ui-section">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Recent template activities</h2>
                                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Reuse a familiar session shape.</p>
                                    </div>
                                    <div className="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                        {bootstrap.options.templateActivities.length} ideas
                                    </div>
                                </div>
                                <div className="mt-5 space-y-3">
                                    {bootstrap.options.templateActivities.map((activity) => (
                                        <button
                                            key={activity.activityId}
                                            type="button"
                                            onClick={() => applyTemplateActivity(activity)}
                                            className={`w-full rounded-[24px] border p-4 text-left transition hover:translate-y-[-1px] ${formState.templateActivityId === activity.activityId
                                                ? 'border-orange-300 bg-orange-50/90 dark:border-orange-700/60 dark:bg-orange-950/30'
                                                : 'border-gray-200 bg-white/85 dark:border-gray-800 dark:bg-gray-950/40'
                                            }`}
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <div className="font-semibold text-gray-900 dark:text-white">{activity.name}</div>
                                                    <div className="mt-1 text-xs uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">{activity.activityTypeLabel} · {formatDate(activity.day)}</div>
                                                </div>
                                                <div className="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                                    {activity.movingTimeLabel}
                                                </div>
                                            </div>
                                            {activity.estimatedLoad !== null ? (
                                                <div className="mt-2 text-sm leading-7 text-gray-600 dark:text-gray-300">Estimated load {activity.estimatedLoad.toFixed(1)}</div>
                                            ) : null}
                                        </button>
                                    ))}
                                </div>
                            </section>
                        ) : null}

                            <section className="ui-section">
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                        <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Recommended sessions</h2>
                                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Planner memory, in card form.</p>
                                </div>
                                <div className="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                    {Object.values(recommendationGroups).reduce((sum, group) => sum + group.length, 0)} options
                                </div>
                            </div>
                            <div className="mt-5 space-y-5">
                                {(recommendationGroups[selectedActivityType ?? ''] ?? []).length > 0 ? (
                                    (recommendationGroups[selectedActivityType ?? ''] ?? []).map((recommendation) => (
                                        <div key={recommendation.trainingSessionId} className="rounded-[24px] border border-gray-200 bg-white/85 p-4 dark:border-gray-800 dark:bg-gray-950/40">
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <div className="font-semibold text-gray-900 dark:text-white">{recommendation.title ?? recommendation.activityTypeLabel}</div>
                                                    <div className="mt-1 text-xs uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">
                                                        {recommendation.targetIntensityLabel ?? 'No intensity label'}
                                                        {recommendation.lastPlannedOnLabel ? ` · Last planned ${recommendation.lastPlannedOnLabel}` : ''}
                                                    </div>
                                                </div>
                                                {recommendation.targetDurationLabel ? (
                                                    <div className="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                                        {recommendation.targetDurationLabel}
                                                    </div>
                                                ) : null}
                                            </div>
                                            {recommendation.notes ? (
                                                <p className="mt-3 text-sm leading-7 text-gray-600 dark:text-gray-300">{recommendation.notes}</p>
                                            ) : null}
                                            <div className="mt-3 flex flex-wrap gap-2 text-xs uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">
                                                <span>Load {recommendation.targetLoad?.toFixed(1) ?? '—'}</span>
                                                <span>·</span>
                                                <span>{recommendation.estimationSourceLabel}</span>
                                                <span>·</span>
                                                <span>{recommendation.workoutSteps.length} workout rows</span>
                                            </div>
                                            <div className="mt-4 flex flex-wrap gap-3">
                                                <button
                                                    type="button"
                                                    onClick={() => applyRecommendation(recommendation)}
                                                    className="inline-flex items-center gap-2 rounded-2xl bg-strava-orange px-4 py-3 text-sm font-semibold text-white transition hover:bg-orange-600"
                                                >
                                                    Use session
                                                    <span aria-hidden="true">→</span>
                                                </button>
                                            </div>
                                        </div>
                                    ))
                                ) : (
                                    <div className="rounded-[24px] border border-gray-200 bg-white/85 p-4 text-sm leading-7 text-gray-600 dark:border-gray-800 dark:bg-gray-950/40 dark:text-gray-300">
                                        No saved recommendations for {selectedActivityTypeOption?.label ?? 'this activity type'} yet. Save a few sessions and this column starts pulling its weight.
                                    </div>
                                )}
                            </div>
                        </section>

                        <section className="ui-section">
                            <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Planner outlook</h2>
                            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Projected load across the next {bootstrap.plannerOutlook.horizon} days.</p>
                            <div className="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-1">
                                <div className="rounded-[24px] border border-gray-200 bg-white/85 p-4 dark:border-gray-800 dark:bg-gray-950/40">
                                    <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Current day projected load</div>
                                    <div className="mt-2 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">{bootstrap.plannerOutlook.currentDayProjectedLoad.toFixed(1)}</div>
                                </div>
                                <div className="rounded-[24px] border border-gray-200 bg-white/85 p-4 dark:border-gray-800 dark:bg-gray-950/40">
                                    <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Total projected load</div>
                                    <div className="mt-2 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">{bootstrap.plannerOutlook.totalProjectedLoad.toFixed(1)}</div>
                                </div>
                            </div>
                            <div className="mt-5 space-y-2">
                                {bootstrap.plannerOutlook.projectedLoads.map((entry) => (
                                    <div key={entry.dayOffset} className="grid grid-cols-[88px_minmax(0,1fr)_56px] items-center gap-3 text-sm">
                                        <div className="text-gray-500 dark:text-gray-400">+{entry.dayOffset}d</div>
                                        <div className="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                                            <div className="h-full rounded-full bg-strava-orange" style={{width: `${Math.min(100, entry.load)}%`}} />
                                        </div>
                                        <div className="text-right font-semibold text-gray-900 dark:text-white">{entry.load.toFixed(1)}</div>
                                    </div>
                                ))}
                            </div>
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
                    </div>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 pt-6 dark:border-gray-800">
                    <div className="text-xs uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Live planner save path</div>
                    <div className="flex flex-wrap gap-3">
                        {onCancel ? (
                            <button
                                type="button"
                                onClick={onCancel}
                                disabled={isBusy}
                                className="ui-button"
                            >
                                Cancel
                            </button>
                        ) : null}
                        {isEditMode && bootstrap.context.plannedSession ? (
                            <button
                                type="button"
                                onClick={() => void handleDelete()}
                                disabled={isBusy}
                                className="inline-flex items-center gap-2 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800 transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-60 dark:border-rose-800/60 dark:bg-rose-950/30 dark:text-rose-100"
                            >
                                {deleting ? 'Deleting session…' : 'Delete session'}
                            </button>
                        ) : null}
                        <button
                            type="submit"
                            disabled={isBusy}
                            className="inline-flex items-center gap-2 rounded-2xl bg-strava-orange px-5 py-3 text-sm font-semibold text-white transition hover:bg-orange-600 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {submitting ? 'Saving session…' : isEditMode ? 'Save changes' : 'Create session'}
                            <span aria-hidden="true">→</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    );
}
