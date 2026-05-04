import {type FormEvent, useCallback, useEffect, useMemo, useState} from 'react';
import {Link} from 'react-router-dom';
import {StatCard} from '../components/stat-card';
import {buildAppPath, type ReactPreviewBootstrap} from '../lib/bootstrap';
import {
    deleteTrainingBlockPreview,
    fetchTrainingBlocksPreview,
    saveTrainingBlockPreview,
    type TrainingBlocksPreviewBlock,
    type TrainingBlocksPreviewResponse,
} from '../lib/training-blocks-preview-api';
import {useAsyncResource} from '../lib/use-async-resource';

interface TrainingBlocksPageProps {
    bootstrap: ReactPreviewBootstrap;
}

interface TrainingBlockFormState {
    trainingBlockId?: string;
    startDay: string;
    endDay: string;
    phase: string;
    title: string;
    focus: string;
    notes: string;
    targetRaceEventId: string;
}

function formatRequestedAt(value: string): string {
    return new Intl.DateTimeFormat('en', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
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

function formatDateRange(startDay: string, endDay: string): string {
    return `${formatShortDate(startDay)} → ${formatShortDate(endDay)}`;
}

function phaseTone(phase: string): string {
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
            return 'bg-slate-100 text-slate-800 dark:bg-slate-900/40 dark:text-slate-100';
    }
}

function stateTone(state: TrainingBlocksPreviewBlock['state']): string {
    switch (state) {
        case 'current':
            return 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-950/30 dark:text-emerald-100';
        case 'upcoming':
            return 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-800/60 dark:bg-sky-950/30 dark:text-sky-100';
        default:
            return 'border-slate-200 bg-slate-50 text-slate-800 dark:border-slate-800 dark:bg-slate-900/40 dark:text-slate-100';
    }
}

function stateLabel(state: TrainingBlocksPreviewBlock['state']): string {
    switch (state) {
        case 'current':
            return 'Current block';
        case 'upcoming':
            return 'Upcoming block';
        default:
            return 'Completed block';
    }
}

function saveBannerClasses(success: boolean): string {
    return success
    ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-950/30 dark:text-emerald-100'
    : 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-800/60 dark:bg-rose-950/30 dark:text-rose-100';
}

function createNewFormState(data: TrainingBlocksPreviewResponse): TrainingBlockFormState {
    return {
        startDay: data.formDefaults.startDay,
        endDay: data.formDefaults.endDay,
        phase: data.formDefaults.phase,
        title: data.formDefaults.title,
        focus: data.formDefaults.focus,
        notes: data.formDefaults.notes,
        targetRaceEventId: data.formDefaults.targetRaceEventId,
    };
}

function createFormStateFromBlock(block: TrainingBlocksPreviewBlock): TrainingBlockFormState {
    return {
        trainingBlockId: block.id,
        startDay: block.startDay,
        endDay: block.endDay,
        phase: block.phase,
        title: block.rawTitle ?? '',
        focus: block.focus ?? '',
        notes: block.notes ?? '',
        targetRaceEventId: block.linkedRace?.id ?? '',
    };
}

export function TrainingBlocksPage({bootstrap}: TrainingBlocksPageProps) {
    const loadTrainingBlocks = useCallback(
        (signal: AbortSignal): Promise<TrainingBlocksPreviewResponse> => fetchTrainingBlocksPreview(bootstrap.basePath, signal),
        [bootstrap.basePath],
    );

    const {data, loading, error, reload} = useAsyncResource(loadTrainingBlocks);
    const [localData, setLocalData] = useState<TrainingBlocksPreviewResponse | null>(null);
    const [selectedBlockId, setSelectedBlockId] = useState<string | null>(null);
    const [formState, setFormState] = useState<TrainingBlockFormState | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [flashMessage, setFlashMessage] = useState<string | null>(null);
    const [flashSuccess, setFlashSuccess] = useState(true);

    useEffect(() => {
        if (data) {
            setLocalData(data);
        }
    }, [data]);

    const displayData = localData ?? data;
    const selectedBlock = useMemo(
        () => displayData?.blocks.find((block) => block.id === selectedBlockId) ?? null,
        [displayData, selectedBlockId],
    );

    useEffect(() => {
        if (!displayData) {
            return;
        }

        const selectedStillExists = selectedBlockId && displayData.blocks.some((block) => block.id === selectedBlockId);
        if (selectedStillExists) {
            return;
        }

        setSelectedBlockId(displayData.initialSelectionId);
    }, [displayData?.requestedAt, displayData?.savedTrainingBlockId, displayData?.deletedTrainingBlockId, selectedBlockId]);

    useEffect(() => {
        if (!displayData) {
            return;
        }

        if (selectedBlock) {
            setFormState(createFormStateFromBlock(selectedBlock));

            return;
        }

        setFormState(createNewFormState(displayData));
    }, [displayData?.requestedAt, displayData?.savedTrainingBlockId, displayData?.deletedTrainingBlockId, selectedBlock?.id]);

    function handleNewBlock() {
        if (!displayData) {
            return;
        }

        setSelectedBlockId(null);
        setFormState(createNewFormState(displayData));
        setFlashMessage(null);
    }

    async function handleSubmit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (!formState) {
            return;
        }

        setSubmitting(true);
        setFlashMessage(null);

        try {
            const response = await saveTrainingBlockPreview(bootstrap.basePath, formState);
            setLocalData(response);
            setSelectedBlockId(response.savedTrainingBlockId);
            setFlashSuccess(true);
            setFlashMessage(formState.trainingBlockId ? 'Training block updated in the live backend.' : 'Training block created in the live backend.');
        } catch (submitError) {
            setFlashSuccess(false);
            setFlashMessage(submitError instanceof Error ? submitError.message : 'Could not save this training block.');
        } finally {
            setSubmitting(false);
        }
    }

    async function handleDelete() {
        if (!selectedBlock) {
            return;
        }

        const confirmed = window.confirm(`Delete ${selectedBlock.title}?`);
        if (!confirmed) {
            return;
        }

        setDeleting(true);
        setFlashMessage(null);

        try {
            const response = await deleteTrainingBlockPreview(bootstrap.basePath, selectedBlock.id);
            setLocalData(response);
            setSelectedBlockId(response.initialSelectionId);
            setFlashSuccess(true);
            setFlashMessage('Training block deleted from the live backend.');
        } catch (deleteError) {
            setFlashSuccess(false);
            setFlashMessage(deleteError instanceof Error ? deleteError.message : 'Could not delete this training block.');
        } finally {
            setDeleting(false);
        }
    }

    return (
        <div className="space-y-6 pb-6">
            <section className="ui-section">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div className="min-w-0">
                        <h1 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white md:text-2xl">Training blocks</h1>
                        <p className="mt-1 max-w-3xl text-sm leading-7 text-gray-500 dark:text-gray-400">
                            Manage season phases, block sequencing, and race alignment without diving back into modal UI.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <button type="button" onClick={handleNewBlock} className="ui-button ui-button-primary">
                            New block
                        </button>
                        <a href={buildAppPath(bootstrap.basePath, selectedBlock?.legacyModalPath ?? displayData?.legacyCreatePath ?? 'training-block?redirectTo=/monthly-stats')} className="ui-button">
                            Open classic modal
                        </a>
                        <Link to="/monthly-stats" className="ui-button">Monthly stats</Link>
                        <Link to="/race-planner" className="ui-button">Race planner</Link>
                        <button
                            type="button"
                            onClick={() => {
                                setFlashMessage(null);
                                reload();
                            }}
                            className="ui-button"
                        >
                            Refresh block data
                        </button>
                    </div>
                </div>
            </section>

            <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Total blocks" value={displayData?.summary.totalBlocks ?? 0} hint="Every saved season block currently known to the athlete." tone="orange" />
                <StatCard label="Current / upcoming" value={displayData ? `${displayData.summary.currentBlocks} / ${displayData.summary.upcomingBlocks}` : '0 / 0'} hint="How much season structure is active now versus still ahead." tone="emerald" />
                <StatCard label="Linked races" value={displayData?.summary.linkedRaceBlocks ?? 0} hint="Blocks already anchored to a race target." tone="blue" />
                <StatCard label="Planned days" value={displayData?.summary.totalPlannedDays ?? 0} hint="Combined duration across all saved blocks." tone="slate" />
            </section>

            {loading && !displayData ? (
                <section className="ui-section text-sm text-gray-600 dark:text-gray-300">
                    Loading training blocks.
                </section>
            ) : null}

            {error && !displayData ? (
                <section className="rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                    {error}
                </section>
            ) : null}

            {displayData ? (
                <section className="grid gap-4 xl:grid-cols-[0.96fr_1.04fr]">
                    <section className="ui-section">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Season structure</h2>
                                <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Select a block to edit details and inspect race anchors.</p>
                            </div>
                            <div className="ui-pill">
                                {displayData.blocks.length} blocks
                            </div>
                        </div>
                        <div className="mt-4 space-y-2">
                            {displayData.blocks.length === 0 ? (
                                <div className="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4 text-sm leading-7 text-gray-600 dark:border-gray-700 dark:bg-gray-950/25 dark:text-gray-300">
                                    No training blocks exist yet. Create one here and the calendar gets a proper season backbone immediately.
                                </div>
                            ) : displayData.blocks.map((block) => {
                                const selected = block.id === selectedBlockId;

                                return (
                                    <button
                                        key={block.id}
                                        type="button"
                                        onClick={() => {
                                            setSelectedBlockId(block.id);
                                            setFlashMessage(null);
                                        }}
                                        className={`block w-full rounded-lg border p-4 text-left transition ${selected
                                            ? 'border-violet-300 bg-violet-50 shadow-sm dark:border-violet-800/50 dark:bg-violet-950/20'
                                            : 'border-gray-200 bg-white hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-950 dark:hover:bg-gray-900'
                                        }`}
                                    >
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${phaseTone(block.phase)}`}>
                                                        {block.phaseLabel}
                                                    </span>
                                                    <span className={`rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${stateTone(block.state)}`}>
                                                        {stateLabel(block.state)}
                                                    </span>
                                                </div>
                                                <h3 className="mt-2 text-base font-semibold text-gray-900 dark:text-white">{block.title}</h3>
                                                <div className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                                    {formatDateRange(block.startDay, block.endDay)} · {block.durationInDays} days
                                                </div>
                                            </div>
                                            <div className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-100">
                                                <div className="font-semibold text-gray-400 dark:text-gray-500">Window</div>
                                                <div className="mt-1 font-semibold">{formatDate(block.startDay)}</div>
                                            </div>
                                        </div>
                                        {block.focus ? (
                                            <div className="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm leading-6 text-gray-700 dark:border-gray-800 dark:bg-gray-900/40 dark:text-gray-200">
                                                <div className="text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Focus</div>
                                                <div className="mt-1">{block.focus}</div>
                                            </div>
                                        ) : null}
                                        {block.linkedRace ? (
                                            <div className="mt-3 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm leading-6 text-rose-900 dark:border-rose-900/50 dark:bg-rose-950/20 dark:text-rose-100">
                                                <div className="text-[10px] font-semibold uppercase tracking-wide opacity-75">Linked race</div>
                                                <div className="mt-1 font-semibold">{block.linkedRace.title}</div>
                                                <div className="mt-1 text-xs opacity-80">
                                                    {formatDate(block.linkedRace.day)} · {block.linkedRace.profileLabel}
                                                    {block.linkedRace.location ? ` · ${block.linkedRace.location}` : ''}
                                                </div>
                                            </div>
                                        ) : null}
                                    </button>
                                );
                            })}
                        </div>
                    </section>

                    <section className="ui-section">
                        <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                            <div>
                                <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Training block editor</h2>
                                <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {selectedBlock ? 'Edit the selected training block.' : 'Create a new block for the season timeline.'}
                                </p>
                                <div className="mt-3 text-xs text-gray-400 dark:text-gray-500">
                                    Saving here triggers the same monthly stats and race planner rebuilds used elsewhere in the planner.
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <button type="button" onClick={handleNewBlock} className="ui-button">
                                    New block
                                </button>
                                {selectedBlock ? (
                                    <a href={buildAppPath(bootstrap.basePath, selectedBlock.legacyModalPath)} className="ui-button">
                                        Open classic modal
                                    </a>
                                ) : null}
                            </div>
                        </div>

                        {flashMessage ? (
                            <div className={`mt-4 rounded-lg border p-4 text-sm leading-7 ${saveBannerClasses(flashSuccess)}`}>
                                {flashMessage}
                            </div>
                        ) : null}

                        <form onSubmit={(event) => void handleSubmit(event)} className="mt-4 space-y-4">
                            <div className="rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950/40">
                                <div className="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                                    <div className="text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Block details</div>
                                    <div className="mt-0.5 text-[11px] text-gray-400 dark:text-gray-500">Keep phase structure visible so the calendar can organize the season around it.</div>
                                </div>
                                <div className="px-4 py-4">
                                    <div className="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label className="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" htmlFor="training-block-start-day">Start day</label>
                                    <input
                                        id="training-block-start-day"
                                        required
                                        type="date"
                                        value={formState?.startDay ?? ''}
                                        onChange={(event) => setFormState((current) => current ? {
                                            ...current,
                                            startDay: event.target.value,
                                            endDay: current.endDay < event.target.value ? event.target.value : current.endDay,
                                        } : current)}
                                        className="ui-input"
                                    />
                                </div>
                                <div>
                                    <label className="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" htmlFor="training-block-end-day">End day</label>
                                    <input
                                        id="training-block-end-day"
                                        required
                                        type="date"
                                        value={formState?.endDay ?? ''}
                                        min={formState?.startDay}
                                        onChange={(event) => setFormState((current) => current ? {...current, endDay: event.target.value} : current)}
                                        className="ui-input"
                                    />
                                </div>
                                <div>
                                    <label className="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" htmlFor="training-block-phase">Phase</label>
                                    <select
                                        id="training-block-phase"
                                        value={formState?.phase ?? ''}
                                        onChange={(event) => setFormState((current) => current ? {...current, phase: event.target.value} : current)}
                                        className="ui-input"
                                    >
                                        {displayData.options.phases.map((phase) => (
                                            <option key={phase.value} value={phase.value}>{phase.label}</option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label className="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" htmlFor="training-block-race">Target race</label>
                                    <select
                                        id="training-block-race"
                                        value={formState?.targetRaceEventId ?? ''}
                                        onChange={(event) => setFormState((current) => current ? {...current, targetRaceEventId: event.target.value} : current)}
                                        className="ui-input"
                                    >
                                        <option value="">No linked race</option>
                                        {displayData.options.raceEvents.map((raceEvent) => (
                                            <option key={raceEvent.id} value={raceEvent.id}>
                                                {raceEvent.title} · {formatDate(raceEvent.day)}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label className="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" htmlFor="training-block-title">Title</label>
                                    <input
                                        id="training-block-title"
                                        value={formState?.title ?? ''}
                                        onChange={(event) => setFormState((current) => current ? {...current, title: event.target.value} : current)}
                                        placeholder="Early summer build"
                                        className="ui-input"
                                    />
                                </div>
                                <div>
                                    <label className="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" htmlFor="training-block-focus">Focus</label>
                                    <input
                                        id="training-block-focus"
                                        value={formState?.focus ?? ''}
                                        onChange={(event) => setFormState((current) => current ? {...current, focus: event.target.value} : current)}
                                        placeholder="Aerobic durability and threshold support"
                                        className="ui-input"
                                    />
                                </div>
                                <div className="md:col-span-2">
                                    <label className="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" htmlFor="training-block-notes">Notes</label>
                                    <textarea
                                        id="training-block-notes"
                                        rows={4}
                                        value={formState?.notes ?? ''}
                                        onChange={(event) => setFormState((current) => current ? {...current, notes: event.target.value} : current)}
                                        placeholder="Key reminders, constraints, or the feel this phase should create."
                                        className="ui-input min-h-[7rem]"
                                    />
                                </div>
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    {selectedBlock ? (
                                        <button
                                            type="button"
                                            onClick={() => void handleDelete()}
                                            disabled={submitting || deleting}
                                            className="ui-button ui-button-danger disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {deleting ? 'Deleting…' : 'Delete block'}
                                        </button>
                                    ) : <span />}
                                </div>
                                <button
                                    type="submit"
                                    disabled={submitting || deleting || !formState}
                                    className="ui-button ui-button-primary disabled:cursor-not-allowed disabled:opacity-70"
                                >
                                    {submitting ? 'Saving…' : selectedBlock ? 'Save changes' : 'Create block'}
                                </button>
                            </div>
                        </form>
                    </section>
                </section>
            ) : null}
        </div>
    );
}
