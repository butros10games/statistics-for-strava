import {type FormEvent, useCallback, useEffect, useMemo, useState} from 'react';
import {Link} from 'react-router-dom';
import {StatCard} from '../components/stat-card';
import {buildAppPath, type ReactPreviewBootstrap} from '../lib/bootstrap';
import {
    deleteRaceEventPreview,
    fetchRaceEventsPreview,
    saveRaceEventPreview,
    type RaceEventsPreviewRace,
    type RaceEventsPreviewResponse,
} from '../lib/race-events-preview-api';
import {useAsyncResource} from '../lib/use-async-resource';

interface RaceEventsPageProps {
    bootstrap: ReactPreviewBootstrap;
}

interface RaceEventFormState {
    raceEventId?: string;
    day: string;
    family: string;
    profile: string;
    priority: string;
    title: string;
    location: string;
    notes: string;
    targetFinishTimeHours: string;
    targetFinishTimeMinutes: string;
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

function coverageTone(state: RaceEventsPreviewRace['coverage']['state']): string {
    switch (state) {
        case 'linked':
            return 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-950/30 dark:text-emerald-100';
        case 'covered':
            return 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-800/60 dark:bg-sky-950/30 dark:text-sky-100';
        default:
            return 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800/60 dark:bg-amber-950/30 dark:text-amber-100';
    }
}

function priorityTone(priority: string): string {
    switch (priority) {
        case 'a':
            return 'bg-rose-100 text-rose-800 dark:bg-rose-950/40 dark:text-rose-100';
        case 'b':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-100';
        default:
            return 'bg-sky-100 text-sky-800 dark:bg-sky-950/40 dark:text-sky-100';
    }
}

function createNewFormState(data: RaceEventsPreviewResponse): RaceEventFormState {
    return {
        day: data.formDefaults.day,
        family: data.formDefaults.family,
        profile: data.formDefaults.profile,
        priority: data.formDefaults.priority,
        title: data.formDefaults.title,
        location: data.formDefaults.location,
        notes: data.formDefaults.notes,
        targetFinishTimeHours: data.formDefaults.targetFinishTimeHours,
        targetFinishTimeMinutes: data.formDefaults.targetFinishTimeMinutes,
    };
}

function createFormStateFromRace(race: RaceEventsPreviewRace): RaceEventFormState {
    return {
        raceEventId: race.id,
        day: race.day,
        family: race.family,
        profile: race.profile,
        priority: race.priority,
        title: race.rawTitle ?? '',
        location: race.location ?? '',
        notes: race.notes ?? '',
        targetFinishTimeHours: race.targetFinishTimeHours === null ? '' : String(race.targetFinishTimeHours),
        targetFinishTimeMinutes: race.targetFinishTimeMinutes === null ? '' : String(race.targetFinishTimeMinutes),
    };
}

function saveBannerClasses(success: boolean): string {
    return success
        ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-950/30 dark:text-emerald-100'
        : 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-800/60 dark:bg-rose-950/30 dark:text-rose-100';
}

export function RaceEventsPage({bootstrap}: RaceEventsPageProps) {
    const loadRaceEvents = useCallback(
        (signal: AbortSignal): Promise<RaceEventsPreviewResponse> => fetchRaceEventsPreview(bootstrap.basePath, signal),
        [bootstrap.basePath],
    );

    const {data, loading, error, reload} = useAsyncResource(loadRaceEvents);
    const [localData, setLocalData] = useState<RaceEventsPreviewResponse | null>(null);
    const [selectedRaceId, setSelectedRaceId] = useState<string | null>(null);
    const [formState, setFormState] = useState<RaceEventFormState | null>(null);
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
    const selectedRace = useMemo(
        () => displayData?.races.find((race) => race.id === selectedRaceId) ?? null,
        [displayData, selectedRaceId],
    );
    const filteredProfiles = useMemo(
        () => displayData?.options.profileGroups.find((group) => group.family === (formState?.family ?? displayData?.formDefaults.family))?.options ?? [],
        [displayData, formState?.family],
    );

    useEffect(() => {
        if (!displayData) {
            return;
        }

        const selectedStillExists = selectedRaceId && displayData.races.some((race) => race.id === selectedRaceId);
        if (selectedStillExists) {
            return;
        }

        setSelectedRaceId(displayData.initialSelectionId);
    }, [displayData?.requestedAt, displayData?.savedRaceEventId, displayData?.deletedRaceEventId, selectedRaceId]);

    useEffect(() => {
        if (!displayData) {
            return;
        }

        if (selectedRace) {
            setFormState(createFormStateFromRace(selectedRace));

            return;
        }

        setFormState(createNewFormState(displayData));
    }, [displayData?.requestedAt, displayData?.savedRaceEventId, displayData?.deletedRaceEventId, selectedRace?.id]);

    function handleNewRace() {
        if (!displayData) {
            return;
        }

        setSelectedRaceId(null);
        setFormState(createNewFormState(displayData));
        setFlashMessage(null);
    }

    function handleFamilyChange(nextFamily: string) {
        setFormState((current) => {
            if (!current || !displayData) {
                return current;
            }

            const nextProfile = displayData.options.profileGroups.find((group) => group.family === nextFamily)?.options[0]?.value ?? current.profile;

            return {
                ...current,
                family: nextFamily,
                profile: nextProfile,
            };
        });
    }

    async function handleSubmit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (!formState) {
            return;
        }

        setSubmitting(true);
        setFlashMessage(null);

        try {
            const response = await saveRaceEventPreview(bootstrap.basePath, formState);
            setLocalData(response);
            setSelectedRaceId(response.savedRaceEventId);
            setFlashSuccess(true);
            setFlashMessage(formState.raceEventId ? 'Race event updated in the live backend.' : 'Race event created in the live backend.');
        } catch (submitError) {
            setFlashSuccess(false);
            setFlashMessage(submitError instanceof Error ? submitError.message : 'Could not save this race event.');
        } finally {
            setSubmitting(false);
        }
    }

    async function handleDelete() {
        if (!selectedRace) {
            return;
        }

        const confirmed = window.confirm(`Delete ${selectedRace.title}?`);
        if (!confirmed) {
            return;
        }

        setDeleting(true);
        setFlashMessage(null);

        try {
            const response = await deleteRaceEventPreview(bootstrap.basePath, selectedRace.id);
            setLocalData(response);
            setSelectedRaceId(response.initialSelectionId);
            setFlashSuccess(true);
            setFlashMessage('Race event deleted from the live backend.');
        } catch (deleteError) {
            setFlashSuccess(false);
            setFlashMessage(deleteError instanceof Error ? deleteError.message : 'Could not delete this race event.');
        } finally {
            setDeleting(false);
        }
    }

    return (
        <div className="space-y-6 pb-6">
            <section className="ui-section">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div className="min-w-0">
                        <h1 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white md:text-2xl">Race events</h1>
                        <p className="mt-1 max-w-3xl text-sm leading-7 text-gray-500 dark:text-gray-400">
                            Keep target races visible so the planner can organize blocks, tapers, and key sessions around real goals.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <button type="button" onClick={handleNewRace} className="ui-button ui-button-primary">
                            New race
                        </button>
                        <a href={buildAppPath(bootstrap.basePath, selectedRace?.legacyModalPath ?? displayData?.legacyCreatePath ?? 'race-event?redirectTo=/race-planner')} className="ui-button">
                            Open classic modal
                        </a>
                        <Link to="/race-planner" className="ui-button">Race planner</Link>
                        <Link to="/training-plans" className="ui-button">Training plans</Link>
                        <button
                            type="button"
                            onClick={() => {
                                setFlashMessage(null);
                                reload();
                            }}
                            className="ui-button"
                        >
                            Refresh race data
                        </button>
                    </div>
                </div>
            </section>

            <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Total races" value={displayData?.summary.totalRaces ?? 0} hint="All known race targets currently stored for the athlete." tone="orange" />
                <StatCard label="Upcoming races" value={displayData?.summary.upcomingRaces ?? 0} hint="Targets still ahead on the calendar." tone="emerald" />
                <StatCard label="Covered races" value={displayData ? `${displayData.summary.coveredRaces}/${displayData.summary.totalRaces}` : '0/0'} hint="Race targets already linked to, or at least covered by, an existing training plan window." tone="blue" />
                <StatCard label="Last refresh" value={displayData ? formatRequestedAt(displayData.requestedAt) : 'Waiting…'} hint="Updated whenever the route reloads or a save/delete succeeds." tone="slate" />
            </section>

            {loading && !displayData ? (
                <section className="ui-section text-sm text-gray-600 dark:text-gray-300">
                    Loading race targets.
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
                                <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Season targets</h2>
                                <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Select a race to edit details and inspect planner coverage.</p>
                            </div>
                            <div className="ui-pill">{displayData.races.length} events</div>
                        </div>
                        <div className="mt-4 space-y-2">
                            {displayData.races.length === 0 ? (
                                <div className="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4 text-sm leading-7 text-gray-600 dark:border-gray-700 dark:bg-gray-950/25 dark:text-gray-300">
                                    No race events exist yet. Create one here and the planner routes immediately gain a real target to steer toward.
                                </div>
                            ) : displayData.races.map((race) => {
                                const selected = race.id === selectedRaceId;

                                return (
                                    <button
                                        key={race.id}
                                        type="button"
                                        onClick={() => {
                                            setSelectedRaceId(race.id);
                                            setFlashMessage(null);
                                        }}
                                        className={`block w-full rounded-lg border p-4 text-left transition ${selected
                                            ? 'border-orange-300 bg-orange-50 shadow-sm dark:border-orange-800/50 dark:bg-orange-950/20'
                                            : 'border-gray-200 bg-white hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-950 dark:hover:bg-gray-900'
                                        }`}
                                    >
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${priorityTone(race.priority)}`}>
                                                        {race.priorityLabel}
                                                    </span>
                                                    <span className={`rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${coverageTone(race.coverage.state)}`}>
                                                        {race.coverage.state === 'linked' ? 'Directly linked' : race.coverage.state === 'covered' ? 'Covered by a plan' : 'Needs a plan'}
                                                    </span>
                                                </div>
                                                <h3 className="mt-2 text-base font-semibold text-gray-900 dark:text-white">{race.title}</h3>
                                                <div className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                                    {formatDate(race.day)} · {race.profileLabel}{race.location ? ` · ${race.location}` : ''}
                                                </div>
                                            </div>
                                            {typeof race.countdownDays === 'number' ? (
                                                <div className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-100">
                                                    <div className="font-semibold text-gray-400 dark:text-gray-500">Countdown</div>
                                                    <div className="mt-1 font-semibold">{race.countdownDays === 0 ? 'Race day' : `D-${race.countdownDays}`}</div>
                                                </div>
                                            ) : null}
                                        </div>
                                        <div className="mt-3 flex flex-wrap items-center gap-2 text-xs font-medium">
                                            <span className="ui-pill">{race.familyLabel}</span>
                                            {race.targetFinishTimeLabel ? <span className="ui-pill">Target {race.targetFinishTimeLabel}</span> : null}
                                        </div>
                                        {race.coverage.linkedTrainingPlan ? (
                                            <div className="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm leading-6 text-gray-700 dark:border-gray-800 dark:bg-gray-900/40 dark:text-gray-200">
                                                <div className="text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Planner coverage</div>
                                                <div className="mt-1 font-semibold text-gray-900 dark:text-white">{race.coverage.linkedTrainingPlan.title}</div>
                                                <div className="mt-1 text-xs text-gray-500 dark:text-gray-400">{race.coverage.state === 'linked' ? 'Explicit target race link.' : 'Falls inside the current plan window.'}</div>
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
                                <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Race editor</h2>
                                <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {selectedRace ? 'Update the event your block, taper, and key sessions should build toward.' : 'Add a target event so the planner can start organizing training around a real race goal.'}
                                </p>
                                <div className="mt-3 text-xs text-gray-400 dark:text-gray-500">
                                    Saving here triggers the same monthly stats, race planner, and training plans refresh cycle used elsewhere in the planner.
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <button type="button" onClick={handleNewRace} className="ui-button">
                                    New race
                                </button>
                                {selectedRace ? (
                                    <a href={buildAppPath(bootstrap.basePath, selectedRace.legacyModalPath)} className="ui-button">
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
                                    <div className="text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Event details</div>
                                    <div className="mt-0.5 text-[11px] text-gray-400 dark:text-gray-500">Keep target races visible so the calendar can plan around them.</div>
                                </div>
                                <div className="px-4 py-4">
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div>
                                            <label className="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" htmlFor="race-event-day">Day</label>
                                            <input
                                                id="race-event-day"
                                                required
                                                type="date"
                                                value={formState?.day ?? ''}
                                                onChange={(event) => setFormState((current) => current ? {...current, day: event.target.value} : current)}
                                                className="ui-input"
                                            />
                                        </div>
                                        <div>
                                            <label className="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" htmlFor="race-event-priority">Priority</label>
                                            <select
                                                id="race-event-priority"
                                                value={formState?.priority ?? ''}
                                                onChange={(event) => setFormState((current) => current ? {...current, priority: event.target.value} : current)}
                                                className="ui-input"
                                            >
                                                {displayData.options.priorities.map((priority) => (
                                                    <option key={priority.value} value={priority.value}>{priority.label}</option>
                                                ))}
                                            </select>
                                        </div>
                                        <div>
                                            <label className="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" htmlFor="race-event-family">Event family</label>
                                            <select
                                                id="race-event-family"
                                                value={formState?.family ?? ''}
                                                onChange={(event) => handleFamilyChange(event.target.value)}
                                                className="ui-input"
                                            >
                                                {displayData.options.families.map((family) => (
                                                    <option key={family.value} value={family.value}>{family.label}</option>
                                                ))}
                                            </select>
                                        </div>
                                        <div>
                                            <label className="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" htmlFor="race-event-profile">Distance / profile</label>
                                            <select
                                                id="race-event-profile"
                                                value={formState?.profile ?? ''}
                                                onChange={(event) => setFormState((current) => current ? {...current, profile: event.target.value} : current)}
                                                className="ui-input"
                                            >
                                                {filteredProfiles.map((profile) => (
                                                    <option key={profile.value} value={profile.value}>{profile.label}</option>
                                                ))}
                                            </select>
                                        </div>
                                        <div>
                                            <label className="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" htmlFor="race-event-title">Title</label>
                                            <input
                                                id="race-event-title"
                                                value={formState?.title ?? ''}
                                                onChange={(event) => setFormState((current) => current ? {...current, title: event.target.value} : current)}
                                                placeholder="A-race goal"
                                                className="ui-input"
                                            />
                                        </div>
                                        <div>
                                            <label className="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" htmlFor="race-event-location">Location</label>
                                            <input
                                                id="race-event-location"
                                                value={formState?.location ?? ''}
                                                onChange={(event) => setFormState((current) => current ? {...current, location: event.target.value} : current)}
                                                placeholder="Mallorca, Belgium, Kona…"
                                                className="ui-input"
                                            />
                                        </div>
                                        <div>
                                            <label className="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" htmlFor="race-event-hours">Target finish</label>
                                            <div className="grid grid-cols-2 gap-2">
                                                <input
                                                    id="race-event-hours"
                                                    type="number"
                                                    min="0"
                                                    value={formState?.targetFinishTimeHours ?? ''}
                                                    onChange={(event) => setFormState((current) => current ? {...current, targetFinishTimeHours: event.target.value} : current)}
                                                    placeholder="Hours"
                                                    className="ui-input"
                                                />
                                                <input
                                                    id="race-event-minutes"
                                                    type="number"
                                                    min="0"
                                                    max="59"
                                                    value={formState?.targetFinishTimeMinutes ?? ''}
                                                    onChange={(event) => setFormState((current) => current ? {...current, targetFinishTimeMinutes: event.target.value} : current)}
                                                    placeholder="Minutes"
                                                    className="ui-input"
                                                />
                                            </div>
                                        </div>
                                        <div className="md:col-span-2">
                                            <label className="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" htmlFor="race-event-notes">Notes</label>
                                            <textarea
                                                id="race-event-notes"
                                                rows={4}
                                                value={formState?.notes ?? ''}
                                                onChange={(event) => setFormState((current) => current ? {...current, notes: event.target.value} : current)}
                                                placeholder="Course notes, pacing cues, logistics, or nutrition reminders."
                                                className="ui-input min-h-[7rem]"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    {selectedRace ? (
                                        <button
                                            type="button"
                                            onClick={() => void handleDelete()}
                                            disabled={submitting || deleting}
                                            className="ui-button ui-button-danger disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {deleting ? 'Deleting…' : 'Delete race'}
                                        </button>
                                    ) : <span />}
                                </div>
                                <button
                                    type="submit"
                                    disabled={submitting || deleting || !formState}
                                    className="ui-button ui-button-primary disabled:cursor-not-allowed disabled:opacity-70"
                                >
                                    {submitting ? 'Saving…' : selectedRace ? 'Save changes' : 'Create race'}
                                </button>
                            </div>
                        </form>
                    </section>
                </section>
            ) : null}
        </div>
    );
}
