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
            return 'border-emerald-200 bg-emerald-50/90 text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-950/30 dark:text-emerald-100';
        case 'upcoming':
            return 'border-sky-200 bg-sky-50/90 text-sky-800 dark:border-sky-800/60 dark:bg-sky-950/30 dark:text-sky-100';
        default:
            return 'border-slate-200 bg-slate-50/90 text-slate-800 dark:border-slate-800 dark:bg-slate-900/40 dark:text-slate-100';
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
        ? 'border-emerald-200 bg-emerald-50/90 text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-950/30 dark:text-emerald-100'
        : 'border-rose-200 bg-rose-50/90 text-rose-800 dark:border-rose-800/60 dark:bg-rose-950/30 dark:text-rose-100';
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
        <div className="space-y-8 pb-8">
            <section className="glass-panel rounded-[36px] p-6 md:p-8">
                <div className="grid gap-8 xl:grid-cols-[1.08fr_0.92fr]">
                    <div>
                        <div className="section-kicker">Route migration</div>
                        <h1 className="mt-5 max-w-4xl text-4xl font-semibold tracking-tight text-gray-900 dark:text-white md:text-5xl">
                            Training blocks now have a real React planning workspace instead of living as a hidden modal behind the calendar.
                        </h1>
                        <p className="mt-5 max-w-3xl text-base leading-8 text-gray-600 dark:text-gray-300 md:text-lg">
                            This route turns season structure into a first-class editing surface. The Symfony backend still owns persistence and planner rebuilds, but React now gives block sequencing, race alignment, and phase context room to breathe.
                        </p>
                        <div className="mt-6 flex flex-wrap gap-3">
                            <button
                                type="button"
                                onClick={handleNewBlock}
                                className="inline-flex items-center gap-2 rounded-2xl bg-strava-orange px-5 py-3 text-sm font-semibold text-white transition hover:bg-orange-600"
                            >
                                Create block in React
                                <span aria-hidden="true">+</span>
                            </button>
                            <a
                                href={buildAppPath(bootstrap.basePath, selectedBlock?.legacyModalPath ?? displayData?.legacyCreatePath ?? 'training-block?redirectTo=/monthly-stats')}
                                className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                            >
                                Compare with the live modal
                                <span aria-hidden="true">↗</span>
                            </a>
                            <Link
                                to="/monthly-stats"
                                className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                            >
                                Open monthly stats
                                <span aria-hidden="true">→</span>
                            </Link>
                            <Link
                                to="/race-planner"
                                className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                            >
                                Open race planner
                                <span aria-hidden="true">→</span>
                            </Link>
                        </div>
                    </div>
                    <div className="rounded-[32px] border border-violet-200 bg-[linear-gradient(135deg,rgba(255,255,255,0.96),rgba(245,243,255,0.96))] p-5 shadow-[0_45px_120px_-45px_rgba(15,23,42,0.65)] dark:border-violet-900/40 dark:bg-[linear-gradient(135deg,rgba(17,24,39,0.94),rgba(49,46,129,0.3))]">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-violet-700 dark:text-violet-200">Why this cut works</div>
                        <div className="mt-4 space-y-3 text-sm leading-7 text-gray-700 dark:text-gray-200">
                            {[
                                'It upgrades a planner primitive that already appears across monthly stats and race-planner views, so the migration value lands immediately.',
                                'It reuses the same live save/delete contract we just proved for race events, which keeps risk modest while expanding planner depth.',
                                'It sets up future planner editing work because block structure, phase sequencing, and race alignment can now evolve in React instead of modal HTML.',
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
                <StatCard label="Total blocks" value={displayData?.summary.totalBlocks ?? 0} hint="Every saved season block currently known to the athlete." tone="orange" />
                <StatCard label="Current / upcoming" value={displayData ? `${displayData.summary.currentBlocks} / ${displayData.summary.upcomingBlocks}` : '0 / 0'} hint="How much season structure is active now versus still ahead." tone="emerald" />
                <StatCard label="Linked races" value={displayData?.summary.linkedRaceBlocks ?? 0} hint="Blocks already anchored to a race target." tone="blue" />
                <StatCard label="Planned days" value={displayData?.summary.totalPlannedDays ?? 0} hint="Combined duration across all saved blocks." tone="slate" />
            </section>

            {loading && !displayData ? (
                <section className="glass-panel rounded-[32px] p-6 text-sm text-gray-600 dark:text-gray-300">
                    Loading training blocks… pulling phase structure out of the planner attic.
                </section>
            ) : null}

            {error && !displayData ? (
                <section className="rounded-[32px] border border-rose-200 bg-rose-50/90 p-6 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                    {error}
                </section>
            ) : null}

            {displayData ? (
                <section className="grid gap-6 xl:grid-cols-[0.96fr_1.04fr]">
                    <section className="glass-panel rounded-[32px] p-6">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <div className="section-kicker">Season structure</div>
                                <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">Block timeline with race anchors</h2>
                            </div>
                            <div className="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                {displayData.blocks.length} blocks
                            </div>
                        </div>
                        <div className="mt-6 space-y-3">
                            {displayData.blocks.length === 0 ? (
                                <div className="rounded-[24px] border border-dashed border-gray-300 bg-gray-50/80 p-5 text-sm leading-7 text-gray-600 dark:border-gray-700 dark:bg-gray-950/25 dark:text-gray-300">
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
                                        className={`block w-full rounded-[26px] border p-5 text-left transition ${selected
                                            ? 'border-violet-300 bg-violet-50/70 shadow-sm dark:border-violet-800/50 dark:bg-violet-950/20'
                                            : 'border-gray-200 bg-white/85 hover:-translate-y-0.5 hover:shadow-md dark:border-gray-800 dark:bg-gray-900/55'
                                        }`}
                                    >
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className={`rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] ${phaseTone(block.phase)}`}>
                                                        {block.phaseLabel}
                                                    </span>
                                                    <span className={`rounded-full border px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] ${stateTone(block.state)}`}>
                                                        {stateLabel(block.state)}
                                                    </span>
                                                </div>
                                                <h3 className="mt-3 text-xl font-semibold text-gray-900 dark:text-white">{block.title}</h3>
                                                <div className="mt-2 text-sm leading-7 text-gray-600 dark:text-gray-300">
                                                    {formatDateRange(block.startDay, block.endDay)} · {block.durationInDays} days
                                                </div>
                                            </div>
                                            <div className="rounded-[18px] border border-gray-200 bg-white/80 px-4 py-3 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-950/30 dark:text-gray-100">
                                                <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-400 dark:text-gray-500">Window</div>
                                                <div className="mt-2 font-semibold">{formatDate(block.startDay)}</div>
                                            </div>
                                        </div>
                                        {block.focus ? (
                                            <div className="mt-4 rounded-2xl border border-gray-200 bg-white/80 p-4 text-sm leading-7 text-gray-700 dark:border-gray-800 dark:bg-gray-950/25 dark:text-gray-200">
                                                <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Focus</div>
                                                <div className="mt-2">{block.focus}</div>
                                            </div>
                                        ) : null}
                                        {block.linkedRace ? (
                                            <div className="mt-4 rounded-2xl border border-rose-200 bg-rose-50/80 p-4 text-sm leading-7 text-rose-900 dark:border-rose-900/50 dark:bg-rose-950/20 dark:text-rose-100">
                                                <div className="text-xs font-semibold uppercase tracking-[0.24em] opacity-75">Linked race</div>
                                                <div className="mt-2 font-semibold">{block.linkedRace.title}</div>
                                                <div className="mt-1 opacity-80">
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

                    <section className="glass-panel rounded-[32px] p-6 md:p-7">
                        <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                            <div>
                                <div className="section-kicker">Live write flow</div>
                                <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">
                                    {selectedBlock ? 'Edit the selected training block.' : 'Create a new block for the season timeline.'}
                                </h2>
                                <p className="mt-3 max-w-3xl text-sm leading-7 text-gray-600 dark:text-gray-300">
                                    This form writes through the live Symfony backend and rebuilds the monthly stats and race planner views, just like the legacy modal. React simply makes the workflow dramatically more usable.
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    onClick={handleNewBlock}
                                    className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                                >
                                    New block
                                    <span aria-hidden="true">+</span>
                                </button>
                                {selectedBlock ? (
                                    <a
                                        href={buildAppPath(bootstrap.basePath, selectedBlock.legacyModalPath)}
                                        className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                                    >
                                        Open legacy modal
                                        <span aria-hidden="true">↗</span>
                                    </a>
                                ) : null}
                            </div>
                        </div>

                        {flashMessage ? (
                            <div className={`mt-5 rounded-2xl border p-4 text-sm leading-7 ${saveBannerClasses(flashSuccess)}`}>
                                {flashMessage}
                            </div>
                        ) : null}

                        <form onSubmit={(event) => void handleSubmit(event)} className="mt-6 space-y-5">
                            <div className="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label className="mb-2 block text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400" htmlFor="training-block-start-day">Start day</label>
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
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="mb-2 block text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400" htmlFor="training-block-end-day">End day</label>
                                    <input
                                        id="training-block-end-day"
                                        required
                                        type="date"
                                        value={formState?.endDay ?? ''}
                                        min={formState?.startDay}
                                        onChange={(event) => setFormState((current) => current ? {...current, endDay: event.target.value} : current)}
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="mb-2 block text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400" htmlFor="training-block-phase">Phase</label>
                                    <select
                                        id="training-block-phase"
                                        value={formState?.phase ?? ''}
                                        onChange={(event) => setFormState((current) => current ? {...current, phase: event.target.value} : current)}
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    >
                                        {displayData.options.phases.map((phase) => (
                                            <option key={phase.value} value={phase.value}>{phase.label}</option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label className="mb-2 block text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400" htmlFor="training-block-race">Target race</label>
                                    <select
                                        id="training-block-race"
                                        value={formState?.targetRaceEventId ?? ''}
                                        onChange={(event) => setFormState((current) => current ? {...current, targetRaceEventId: event.target.value} : current)}
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
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
                                    <label className="mb-2 block text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400" htmlFor="training-block-title">Title</label>
                                    <input
                                        id="training-block-title"
                                        value={formState?.title ?? ''}
                                        onChange={(event) => setFormState((current) => current ? {...current, title: event.target.value} : current)}
                                        placeholder="Early summer build"
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="mb-2 block text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400" htmlFor="training-block-focus">Focus</label>
                                    <input
                                        id="training-block-focus"
                                        value={formState?.focus ?? ''}
                                        onChange={(event) => setFormState((current) => current ? {...current, focus: event.target.value} : current)}
                                        placeholder="Aerobic durability and threshold support"
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    />
                                </div>
                                <div className="md:col-span-2">
                                    <label className="mb-2 block text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400" htmlFor="training-block-notes">Notes</label>
                                    <textarea
                                        id="training-block-notes"
                                        rows={5}
                                        value={formState?.notes ?? ''}
                                        onChange={(event) => setFormState((current) => current ? {...current, notes: event.target.value} : current)}
                                        placeholder="Key reminders, constraints, or the feel this phase should create."
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm leading-7 text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    />
                                </div>
                            </div>

                            <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                <div className="text-sm leading-7 text-gray-600 dark:text-gray-300">
                                    Saving here triggers the same monthly-stats and race-planner rebuilds as the legacy training-block modal.
                                </div>
                                <div className="flex flex-wrap gap-3">
                                    {selectedBlock ? (
                                        <button
                                            type="button"
                                            onClick={() => void handleDelete()}
                                            disabled={submitting || deleting}
                                            className="inline-flex items-center gap-2 rounded-2xl border border-rose-300 bg-white px-4 py-3 text-sm font-semibold text-rose-700 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-rose-800/60 dark:bg-gray-900 dark:text-rose-200 dark:hover:bg-rose-950/20"
                                        >
                                            {deleting ? 'Deleting…' : 'Delete block'}
                                        </button>
                                    ) : null}
                                    <button
                                        type="submit"
                                        disabled={submitting || deleting || !formState}
                                        className="inline-flex items-center gap-2 rounded-2xl bg-strava-orange px-5 py-3 text-sm font-semibold text-white transition hover:bg-orange-600 disabled:cursor-not-allowed disabled:opacity-70"
                                    >
                                        {submitting ? 'Saving…' : selectedBlock ? 'Save changes' : 'Create block'}
                                        <span aria-hidden="true">→</span>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </section>
                </section>
            ) : null}

            <section className="glass-panel rounded-[32px] p-6">
                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <div className="section-kicker">Migration note</div>
                        <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">Season structure is now a route, not just a modal footnote</h2>
                    </div>
                    <button
                        type="button"
                        onClick={() => {
                            setFlashMessage(null);
                            reload();
                        }}
                        className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                    >
                        Refresh block data
                        <span aria-hidden="true">↻</span>
                    </button>
                </div>
                <div className="mt-5 space-y-3 text-sm leading-7 text-gray-600 dark:text-gray-300">
                    {[
                        'This route upgrades training blocks from hidden scaffolding into a visible planning tool that users can actually scan and manage.',
                        'It also complements the race-events slice: now both the target events and the season phases around them can be managed in React.',
                        'That leaves richer planner editing as the next layer, rather than forcing every future improvement through legacy modal UI first.',
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
