import {useCallback, useMemo, useState} from 'react';
import {buildAppPath, type ReactPreviewBootstrap} from '../lib/bootstrap';
import {
    fetchMilestonesPreview,
    type MilestonePreviewMilestone,
    type MilestonesPreviewResponse,
} from '../lib/milestones-preview-api';
import {useAsyncResource} from '../lib/use-async-resource';

interface MilestonesPageProps {
    bootstrap: ReactPreviewBootstrap;
}

interface MilestoneFilters {
    search: string;
    group: string;
    sportType: string;
    year: string;
}

const initialFilters: MilestoneFilters = {
    search: '',
    group: 'all',
    sportType: 'all',
    year: 'all',
};

function formatRequestedAt(value: string): string {
    return new Intl.DateTimeFormat('en', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('en', {
        dateStyle: 'medium',
    }).format(new Date(value));
}

function formatNumber(value: number): string {
    return new Intl.NumberFormat('en-US').format(value);
}

function resolveGroupIcon(icon: string): string {
    return {
        'number-one': '①',
        distance: '↔',
        elevation: '△',
        time: '◷',
        muscle: '✦',
        trophy: '🏆',
        eddington: '◎',
        fire: '🔥',
        rocket: '🚀',
    }[icon] ?? '•';
}

function flagUrl(basePath: string, countryCode: string | null | undefined): string | null {
    if (!countryCode) {
        return null;
    }

    return buildAppPath(basePath, `assets/images/flags/${countryCode.toLowerCase()}.svg`);
}

function MilestoneCard({
    bootstrap,
    milestone,
    onJumpToPrevious,
}: {
    bootstrap: ReactPreviewBootstrap;
    milestone: MilestonePreviewMilestone;
    onJumpToPrevious: (milestoneId: string) => void;
}) {
    const flag = flagUrl(bootstrap.basePath, milestone.country?.code);

    return (
        <article id={milestone.id} className="relative mb-6 ml-5 border-l border-gray-200 pl-8 dark:border-gray-800">
            <div className="absolute -left-4 top-1 flex h-8 w-8 items-center justify-center rounded-full border border-gray-200 bg-white text-sm shadow-sm dark:border-gray-800 dark:bg-gray-950">
                {resolveGroupIcon(milestone.filterGroup.icon)}
            </div>
            <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-950/40">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="rounded-lg border border-orange-200 bg-orange-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-orange-700 dark:border-orange-900/50 dark:bg-orange-950/30 dark:text-orange-200">
                                {milestone.filterGroup.label}
                            </span>
                            {milestone.sportType ? (
                                <span className="rounded-lg border border-sky-200 bg-sky-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-700 dark:border-sky-900/50 dark:bg-sky-950/30 dark:text-sky-200">
                                    {milestone.sportType.label}
                                </span>
                            ) : null}
                            <span className="rounded-lg border border-gray-200 bg-gray-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                {formatDate(milestone.achievedOn)}
                            </span>
                        </div>
                        <div className="mt-3 flex flex-wrap items-center gap-3">
                            <h3 className="text-sm font-semibold tracking-tight text-gray-900 dark:text-white md:text-base">{milestone.title}</h3>
                            {flag ? (
                                <img src={flag} alt={milestone.country?.label ?? 'Country'} className="h-5 w-7 rounded-sm object-cover shadow-sm" />
                            ) : null}
                        </div>
                    </div>
                    <div className="text-sm text-gray-500 dark:text-gray-400">
                        Year {milestone.year}
                    </div>
                </div>

                {milestone.activity ? (
                    <div className="mt-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900/70 dark:text-gray-300">
                        <div className="font-medium text-gray-900 dark:text-white">{milestone.activity.name}</div>
                        {milestone.activity.url ? (
                            <a href={milestone.activity.url} className="mt-1 inline-flex items-center gap-2 font-semibold text-strava-orange hover:underline">
                                Open activity
                                <span aria-hidden="true">↗</span>
                            </a>
                        ) : null}
                    </div>
                ) : null}

                {milestone.details.length > 0 ? (
                    <div className="mt-3 space-y-1 text-sm leading-7 text-gray-600 dark:text-gray-300">
                        {milestone.details.map((detail) => (
                            <p key={detail}>{detail}</p>
                        ))}
                    </div>
                ) : null}

                {milestone.previous ? (
                    <div className="mt-3 flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                        <span className="uppercase tracking-wide">Previous</span>
                        <button
                            type="button"
                            onClick={() => onJumpToPrevious(milestone.previous!.id)}
                            className="font-semibold text-gray-700 underline decoration-dotted underline-offset-4 hover:text-strava-orange dark:text-gray-200"
                        >
                            {milestone.previous.threshold}
                        </button>
                        <span>on {formatDate(milestone.previous.achievedOn)}</span>
                    </div>
                ) : null}
            </div>
        </article>
    );
}

export function MilestonesPage({bootstrap}: MilestonesPageProps) {
    const [filters, setFilters] = useState<MilestoneFilters>(initialFilters);

    const loadMilestones = useCallback(
        (signal: AbortSignal): Promise<MilestonesPreviewResponse> => fetchMilestonesPreview(bootstrap.basePath, signal),
        [bootstrap.basePath],
    );
    const {data, loading, error, reload} = useAsyncResource(loadMilestones);

    const filteredMilestones = useMemo(() => {
        if (!data) {
            return [];
        }

        const search = filters.search.trim().toLowerCase();

        return data.milestones.filter((milestone) => {
            if ('all' !== filters.group && milestone.filterGroup.value !== filters.group) {
                return false;
            }

            if ('all' !== filters.sportType && milestone.sportType?.value !== filters.sportType) {
                return false;
            }

            if ('all' !== filters.year && String(milestone.year) !== filters.year) {
                return false;
            }

            if (!search) {
                return true;
            }

            const haystack = [
                milestone.title,
                milestone.filterGroup.label,
                milestone.sportType?.label,
                milestone.country?.label,
                milestone.activity?.name,
                ...milestone.details,
            ]
                .filter(Boolean)
                .join(' ')
                .toLowerCase();

            return haystack.includes(search);
        });
    }, [data, filters]);

    const milestonesByYear = useMemo(() => {
        const groups = new Map<number, MilestonePreviewMilestone[]>();

        for (const milestone of filteredMilestones) {
            const current = groups.get(milestone.year) ?? [];
            current.push(milestone);
            groups.set(milestone.year, current);
        }

        return [...groups.entries()].sort((left, right) => right[0] - left[0]);
    }, [filteredMilestones]);

    const activeFilterCount = useMemo(
        () => [filters.search, 'all' !== filters.group ? filters.group : '', 'all' !== filters.sportType ? filters.sportType : '', 'all' !== filters.year ? filters.year : ''].filter(Boolean).length,
        [filters],
    );

    function resetFilters() {
        setFilters(initialFilters);
    }

    function jumpToMilestone(milestoneId: string) {
        const element = document.getElementById(milestoneId);
        if (!element) {
            return;
        }

        element.scrollIntoView({behavior: 'smooth', block: 'center'});
    }

    return (
        <div className="space-y-6 pb-6">
            <section className="ui-section">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div className="min-w-0">
                        <h1 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white md:text-2xl">Milestones</h1>
                        <p className="mt-1 max-w-3xl text-sm leading-7 text-gray-500 dark:text-gray-400">
                            Browse the achievement timeline by group, sport type, year, and activity context.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <a href={buildAppPath(bootstrap.basePath, 'milestones')} className="ui-button">
                            Open classic milestones page
                        </a>
                        <button type="button" onClick={reload} className="ui-button">
                            Refresh data
                        </button>
                    </div>
                </div>
            </section>

            <section className="ui-section">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div className="flex-1">
                        <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Filters</h2>
                        <p className="mt-1 max-w-3xl text-sm leading-7 text-gray-500 dark:text-gray-400">
                            Filter the timeline by title, group, sport type, or year.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-3 text-sm text-gray-500 dark:text-gray-400">
                        <button
                            type="button"
                            onClick={resetFilters}
                            className="ui-button"
                        >
                            Reset filters
                            <span className="ui-pill">{activeFilterCount}</span>
                        </button>
                        <div className="ui-pill">{formatNumber(filteredMilestones.length)} results</div>
                        {data ? <span>Refreshed {formatRequestedAt(data.requestedAt)}.</span> : null}
                    </div>
                </div>

                <div className="mt-6 grid gap-4 lg:grid-cols-[1.4fr_repeat(3,minmax(0,1fr))]">
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-600 dark:text-gray-300">
                        <span>Search the achievement ledger</span>
                        <input
                            type="search"
                            value={filters.search}
                            onChange={(event) => setFilters((current) => ({...current, search: event.target.value}))}
                            placeholder="Search title, country, activity, or detail"
                            className="ui-input"
                        />
                    </label>
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-600 dark:text-gray-300">
                        <span>Sport type</span>
                        <select
                            value={filters.sportType}
                            onChange={(event) => setFilters((current) => ({...current, sportType: event.target.value}))}
                            className="ui-input"
                        >
                            <option value="all">All sport types</option>
                            {data?.filters.sportTypes.map((option) => (
                                <option key={option.value} value={option.value}>{option.label}</option>
                            ))}
                        </select>
                    </label>
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-600 dark:text-gray-300">
                        <span>Year</span>
                        <select
                            value={filters.year}
                            onChange={(event) => setFilters((current) => ({...current, year: event.target.value}))}
                            className="ui-input"
                        >
                            <option value="all">All years</option>
                            {data?.filters.years.map((option) => (
                                <option key={option.value} value={String(option.value)}>{option.label}</option>
                            ))}
                        </select>
                    </label>
                    <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900/40 dark:text-gray-300">
                        <div className="font-semibold text-gray-900 dark:text-white">This year</div>
                        <div className="mt-1 text-2xl font-semibold tracking-tight text-strava-orange">{formatNumber(data?.summary.achievedThisYear ?? 0)}</div>
                        <p className="mt-2 leading-6">Milestones achieved in the current calendar year.</p>
                    </div>
                </div>

                {data?.filters.groups.length ? (
                    <div className="mt-6">
                        <div className="text-sm font-medium text-gray-600 dark:text-gray-300">Achievement groups</div>
                        <div className="mt-3 flex flex-wrap gap-2">
                            <button
                                type="button"
                                onClick={() => setFilters((current) => ({...current, group: 'all'}))}
                                className={`inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition ${filters.group === 'all'
                                    ? 'border-orange-300 bg-orange-50 text-orange-700 dark:border-orange-400 dark:bg-orange-950/40 dark:text-orange-200'
                                    : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:text-white'}`}
                            >
                                <span>All</span>
                                <span className="ui-pill">{formatNumber(data.summary.totalMilestones)}</span>
                            </button>
                            {data.filters.groups.map((group) => {
                                const active = filters.group === group.value;

                                return (
                                    <button
                                        key={group.value}
                                        type="button"
                                        onClick={() => setFilters((current) => ({...current, group: group.value}))}
                                        className={`inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition ${active
                                            ? 'border-orange-300 bg-orange-50 text-orange-700 dark:border-orange-400 dark:bg-orange-950/40 dark:text-orange-200'
                                            : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:text-white'}`}
                                    >
                                        <span>{resolveGroupIcon(group.icon)}</span>
                                        <span>{group.label}</span>
                                        <span className="ui-pill">{formatNumber(group.count)}</span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                ) : null}
            </section>

            {loading && !data ? (
                <section className="ui-section text-sm text-gray-600 dark:text-gray-300">
                    Loading milestones.
                </section>
            ) : null}

            {error ? (
                <section className="rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                    {error}
                </section>
            ) : null}

            {data ? (
                <section className="ui-section">
                        <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Timeline</h2>
                                <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Achievement chronology grouped by year.</p>
                            </div>
                            <div className="text-sm text-gray-500 dark:text-gray-400">
                                {formatNumber(filteredMilestones.length)} milestone{filteredMilestones.length === 1 ? '' : 's'} after filtering.
                            </div>
                        </div>

                        {0 === milestonesByYear.length ? (
                            <div className="mt-6 rounded-lg border border-gray-200 bg-white p-6 text-sm leading-7 text-gray-600 dark:border-gray-800 dark:bg-gray-950/35 dark:text-gray-300">
                                No milestones match the current filters.
                            </div>
                        ) : (
                            <div className="mt-8 space-y-8">
                                {milestonesByYear.map(([year, milestones]) => (
                                    <section key={year} className="space-y-6">
                                        <div className="relative -ml-1 flex items-center gap-3">
                                            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-strava-orange text-sm font-semibold text-white">{String(year).slice(-2)}</div>
                                            <div className="text-sm font-bold text-gray-700 dark:text-gray-300">{year}</div>
                                        </div>
                                        <div className="space-y-6">
                                            {milestones.map((milestone) => (
                                                <MilestoneCard
                                                    key={milestone.id}
                                                    bootstrap={bootstrap}
                                                    milestone={milestone}
                                                    onJumpToPrevious={jumpToMilestone}
                                                />
                                            ))}
                                        </div>
                                    </section>
                                ))}
                            </div>
                        )}
                </section>
            ) : null}
        </div>
    );
}