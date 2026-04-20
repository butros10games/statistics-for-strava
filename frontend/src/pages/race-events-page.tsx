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
            return 'border-emerald-200 bg-emerald-50/90 text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-950/30 dark:text-emerald-100';
        case 'covered':
            return 'border-sky-200 bg-sky-50/90 text-sky-800 dark:border-sky-800/60 dark:bg-sky-950/30 dark:text-sky-100';
        default:
            return 'border-amber-200 bg-amber-50/90 text-amber-800 dark:border-amber-800/60 dark:bg-amber-950/30 dark:text-amber-100';
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
        ? 'border-emerald-200 bg-emerald-50/90 text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-950/30 dark:text-emerald-100'
        : 'border-rose-200 bg-rose-50/90 text-rose-800 dark:border-rose-800/60 dark:bg-rose-950/30 dark:text-rose-100';
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
        <div className="space-y-8 pb-8">
            <section className="glass-panel rounded-[36px] p-6 md:p-8">
                <div className="grid gap-8 xl:grid-cols-[1.08fr_0.92fr]">
                    <div>
                        <div className="section-kicker">Route migration</div>
                        <h1 className="mt-5 max-w-4xl text-4xl font-semibold tracking-tight text-gray-900 dark:text-white md:text-5xl">
                            Race events now have a proper React workspace instead of being squeezed into a tiny modal with big responsibilities.
                        </h1>
                        <p className="mt-5 max-w-3xl text-base leading-8 text-gray-600 dark:text-gray-300 md:text-lg">
                            This route promotes the season-target editor into a real planning surface. The live Symfony backend still owns persistence and rebuilds the planner views, but React now gives race targets room for context, coverage state, and faster iteration.
                        </p>
                        <div className="mt-6 flex flex-wrap gap-3">
                            <button
                                type="button"
                                onClick={handleNewRace}
                                className="inline-flex items-center gap-2 rounded-2xl bg-strava-orange px-5 py-3 text-sm font-semibold text-white transition hover:bg-orange-600"
                            >
                                Create race in React
                                <span aria-hidden="true">+</span>
                            </button>
                            <a
                                href={buildAppPath(bootstrap.basePath, selectedRace?.legacyModalPath ?? displayData?.legacyCreatePath ?? 'race-event?redirectTo=/race-planner')}
                                className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                            >
                                Compare with the live modal
                                <span aria-hidden="true">↗</span>
                            </a>
                            <Link
                                to="/race-planner"
                                className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                            >
                                Open race planner
                                <span aria-hidden="true">→</span>
                            </Link>
                            <Link
                                to="/training-plans"
                                className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                            >
                                Open training plans
                                <span aria-hidden="true">→</span>
                            </Link>
                        </div>
                    </div>
                    <div className="rounded-[32px] border border-orange-200 bg-[linear-gradient(135deg,rgba(255,255,255,0.96),rgba(255,244,237,0.96))] p-5 shadow-[0_45px_120px_-45px_rgba(15,23,42,0.65)] dark:border-orange-900/40 dark:bg-[linear-gradient(135deg,rgba(17,24,39,0.94),rgba(49,24,17,0.92))]">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-orange-700 dark:text-orange-200">Why this cut works</div>
                        <div className="mt-4 space-y-3 text-sm leading-7 text-gray-700 dark:text-gray-200">
                            {[
                                'It upgrades an important planner dependency from modal-only UI into a first-class route with list state, editing context, and linked-plan visibility.',
                                'It reuses the same live-write pattern proven by recovery check-in, but with slightly richer form semantics and delete support.',
                                'It directly supports the already-migrated race-planner and training-plans routes, so the value lands immediately instead of waiting for a later phase.',
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
                <StatCard label="Total races" value={displayData?.summary.totalRaces ?? 0} hint="All known race targets currently stored for the athlete." tone="orange" />
                <StatCard label="Upcoming races" value={displayData?.summary.upcomingRaces ?? 0} hint="Targets still ahead on the calendar." tone="emerald" />
                <StatCard label="Covered races" value={displayData ? `${displayData.summary.coveredRaces}/${displayData.summary.totalRaces}` : '0/0'} hint="Race targets already linked to, or at least covered by, an existing training plan window." tone="blue" />
                <StatCard label="Preview refresh" value={displayData ? formatRequestedAt(displayData.requestedAt) : 'Waiting…'} hint="Updated whenever the route reloads or a save/delete succeeds." tone="slate" />
            </section>

            {loading && !displayData ? (
                <section className="glass-panel rounded-[32px] p-6 text-sm text-gray-600 dark:text-gray-300">
                    Loading race targets… assembling calendars, priorities, and a little controlled ambition.
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
                                <div className="section-kicker">Season targets</div>
                                <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">Race list with plan coverage</h2>
                            </div>
                            <div className="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                {displayData.races.length} events
                            </div>
                        </div>
                        <div className="mt-6 space-y-3">
                            {displayData.races.length === 0 ? (
                                <div className="rounded-[24px] border border-dashed border-gray-300 bg-gray-50/80 p-5 text-sm leading-7 text-gray-600 dark:border-gray-700 dark:bg-gray-950/25 dark:text-gray-300">
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
                                        className={`block w-full rounded-[26px] border p-5 text-left transition ${selected
                                            ? 'border-orange-300 bg-orange-50/70 shadow-sm dark:border-orange-800/50 dark:bg-orange-950/20'
                                            : 'border-gray-200 bg-white/85 hover:-translate-y-0.5 hover:shadow-md dark:border-gray-800 dark:bg-gray-900/55'
                                        }`}
                                    >
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className={`rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] ${priorityTone(race.priority)}`}>
                                                        {race.priorityLabel}
                                                    </span>
                                                    <span className={`rounded-full border px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] ${coverageTone(race.coverage.state)}`}>
                                                        {race.coverage.state === 'linked' ? 'Directly linked' : race.coverage.state === 'covered' ? 'Covered by a plan' : 'Needs a plan'}
                                                    </span>
                                                </div>
                                                <h3 className="mt-3 text-xl font-semibold text-gray-900 dark:text-white">{race.title}</h3>
                                                <div className="mt-2 text-sm leading-7 text-gray-600 dark:text-gray-300">
                                                    {formatDate(race.day)} · {race.profileLabel}{race.location ? ` · ${race.location}` : ''}
                                                </div>
                                            </div>
                                            {typeof race.countdownDays === 'number' ? (
                                                <div className="rounded-[18px] border border-gray-200 bg-white/80 px-4 py-3 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-950/30 dark:text-gray-100">
                                                    <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-400 dark:text-gray-500">Countdown</div>
                                                    <div className="mt-2 font-semibold">{race.countdownDays === 0 ? 'Race day' : `D-${race.countdownDays}`}</div>
                                                </div>
                                            ) : null}
                                        </div>
                                        <div className="mt-4 flex flex-wrap items-center gap-2 text-xs font-medium">
                                            <span className="rounded-full border border-gray-200 bg-white px-3 py-1 text-gray-600 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200">{race.familyLabel}</span>
                                            {race.targetFinishTimeLabel ? <span className="rounded-full border border-gray-200 bg-white px-3 py-1 text-gray-600 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200">Target {race.targetFinishTimeLabel}</span> : null}
                                        </div>
                                        {race.coverage.linkedTrainingPlan ? (
                                            <div className="mt-4 rounded-2xl border border-gray-200 bg-white/80 p-4 text-sm leading-7 text-gray-700 dark:border-gray-800 dark:bg-gray-950/25 dark:text-gray-200">
                                                <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Planner coverage</div>
                                                <div className="mt-2 font-semibold text-gray-900 dark:text-white">{race.coverage.linkedTrainingPlan.title}</div>
                                                <div className="mt-1 text-gray-500 dark:text-gray-400">{race.coverage.state === 'linked' ? 'Explicit target race link.' : 'Falls inside the current plan window.'}</div>
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
                                    {selectedRace ? 'Edit the currently selected target race.' : 'Create a new target race for the season.'}
                                </h2>
                                <p className="mt-3 max-w-3xl text-sm leading-7 text-gray-600 dark:text-gray-300">
                                    This form persists through the live Symfony backend and triggers the same planner rebuilds as the legacy modal. React just gives it vastly better elbow room.
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    onClick={handleNewRace}
                                    className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                                >
                                    New race
                                    <span aria-hidden="true">+</span>
                                </button>
                                {selectedRace ? (
                                    <a
                                        href={buildAppPath(bootstrap.basePath, selectedRace.legacyModalPath)}
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
                                    <label className="mb-2 block text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400" htmlFor="race-event-day">Day</label>
                                    <input
                                        id="race-event-day"
                                        required
                                        type="date"
                                        value={formState?.day ?? ''}
                                        onChange={(event) => setFormState((current) => current ? {...current, day: event.target.value} : current)}
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="mb-2 block text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400" htmlFor="race-event-priority">Priority</label>
                                    <select
                                        id="race-event-priority"
                                        value={formState?.priority ?? ''}
                                        onChange={(event) => setFormState((current) => current ? {...current, priority: event.target.value} : current)}
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    >
                                        {displayData.options.priorities.map((priority) => (
                                            <option key={priority.value} value={priority.value}>{priority.label}</option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label className="mb-2 block text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400" htmlFor="race-event-family">Event family</label>
                                    <select
                                        id="race-event-family"
                                        value={formState?.family ?? ''}
                                        onChange={(event) => handleFamilyChange(event.target.value)}
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    >
                                        {displayData.options.families.map((family) => (
                                            <option key={family.value} value={family.value}>{family.label}</option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label className="mb-2 block text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400" htmlFor="race-event-profile">Distance / profile</label>
                                    <select
                                        id="race-event-profile"
                                        value={formState?.profile ?? ''}
                                        onChange={(event) => setFormState((current) => current ? {...current, profile: event.target.value} : current)}
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    >
                                        {filteredProfiles.map((profile) => (
                                            <option key={profile.value} value={profile.value}>{profile.label}</option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label className="mb-2 block text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400" htmlFor="race-event-title">Title</label>
                                    <input
                                        id="race-event-title"
                                        value={formState?.title ?? ''}
                                        onChange={(event) => setFormState((current) => current ? {...current, title: event.target.value} : current)}
                                        placeholder="A-race goal"
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="mb-2 block text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400" htmlFor="race-event-location">Location</label>
                                    <input
                                        id="race-event-location"
                                        value={formState?.location ?? ''}
                                        onChange={(event) => setFormState((current) => current ? {...current, location: event.target.value} : current)}
                                        placeholder="Mallorca, Belgium, Kona…"
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="mb-2 block text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400" htmlFor="race-event-hours">Target finish hours</label>
                                    <input
                                        id="race-event-hours"
                                        type="number"
                                        min="0"
                                        value={formState?.targetFinishTimeHours ?? ''}
                                        onChange={(event) => setFormState((current) => current ? {...current, targetFinishTimeHours: event.target.value} : current)}
                                        placeholder="4"
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="mb-2 block text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400" htmlFor="race-event-minutes">Target finish minutes</label>
                                    <input
                                        id="race-event-minutes"
                                        type="number"
                                        min="0"
                                        max="59"
                                        value={formState?.targetFinishTimeMinutes ?? ''}
                                        onChange={(event) => setFormState((current) => current ? {...current, targetFinishTimeMinutes: event.target.value} : current)}
                                        placeholder="45"
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    />
                                </div>
                                <div className="md:col-span-2">
                                    <label className="mb-2 block text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400" htmlFor="race-event-notes">Notes</label>
                                    <textarea
                                        id="race-event-notes"
                                        rows={5}
                                        value={formState?.notes ?? ''}
                                        onChange={(event) => setFormState((current) => current ? {...current, notes: event.target.value} : current)}
                                        placeholder="Course notes, pacing cues, logistics, or nutrition reminders."
                                        className="block w-full rounded-[20px] border border-gray-200 bg-white px-4 py-3 text-sm leading-7 text-gray-900 shadow-sm focus:border-orange-400 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                                    />
                                </div>
                            </div>

                            <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                <div className="text-sm leading-7 text-gray-600 dark:text-gray-300">
                                    Saving here triggers the same monthly-stats, race-planner, and training-plans rebuilds as the live modal flow.
                                </div>
                                <div className="flex flex-wrap gap-3">
                                    {selectedRace ? (
                                        <button
                                            type="button"
                                            onClick={() => void handleDelete()}
                                            disabled={submitting || deleting}
                                            className="inline-flex items-center gap-2 rounded-2xl border border-rose-300 bg-white px-4 py-3 text-sm font-semibold text-rose-700 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-rose-800/60 dark:bg-gray-900 dark:text-rose-200 dark:hover:bg-rose-950/20"
                                        >
                                            {deleting ? 'Deleting…' : 'Delete race'}
                                        </button>
                                    ) : null}
                                    <button
                                        type="submit"
                                        disabled={submitting || deleting || !formState}
                                        className="inline-flex items-center gap-2 rounded-2xl bg-strava-orange px-5 py-3 text-sm font-semibold text-white transition hover:bg-orange-600 disabled:cursor-not-allowed disabled:opacity-70"
                                    >
                                        {submitting ? 'Saving…' : selectedRace ? 'Save changes' : 'Create race'}
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
                        <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">A stronger planner foundation, one form seam at a time</h2>
                    </div>
                    <button
                        type="button"
                        onClick={() => {
                            setFlashMessage(null);
                            reload();
                        }}
                        className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                    >
                        Refresh race data
                        <span aria-hidden="true">↻</span>
                    </button>
                </div>
                <div className="mt-5 space-y-3 text-sm leading-7 text-gray-600 dark:text-gray-300">
                    {[
                        'This route gives the planner ecosystem a much better control surface: the same race targets now support list scanning, edit context, and plan coverage hints without modal churn.',
                        'It also reduces risk for the next slices. Training blocks and richer planner edits can now assume race targets are manageable in React already.',
                        'Most importantly, this is real parity work: the preview route writes to the live backend and updates the existing planner outputs instead of inventing a disconnected shadow flow.',
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
