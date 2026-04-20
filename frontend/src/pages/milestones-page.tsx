import {useCallback, useMemo, useState} from 'react';
import {Link} from 'react-router-dom';
import {StatCard} from '../components/stat-card';
import {buildAppPath, type ReactPreviewBootstrap} from '../lib/bootstrap';
import {
    fetchMilestonesPreview,
    type MilestonePreviewGroupFilter,
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
        <article id={milestone.id} className="relative ml-5 border-l border-white/60 pl-8 dark:border-gray-800">
            <div className="absolute -left-4 top-1 flex h-8 w-8 items-center justify-center rounded-full border border-orange-200 bg-white text-sm shadow-sm dark:border-orange-900/50 dark:bg-gray-950">
                {resolveGroupIcon(milestone.filterGroup.icon)}
            </div>
            <div className="rounded-[28px] border border-white/70 bg-white/88 p-5 shadow-[0_30px_80px_-55px_rgba(15,23,42,0.65)] dark:border-gray-800 dark:bg-gray-950/35">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="rounded-full border border-orange-200 bg-orange-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-orange-700 dark:border-orange-900/50 dark:bg-orange-950/30 dark:text-orange-200">
                                {milestone.filterGroup.label}
                            </span>
                            {milestone.sportType ? (
                                <span className="rounded-full border border-sky-200 bg-sky-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-sky-700 dark:border-sky-900/50 dark:bg-sky-950/30 dark:text-sky-200">
                                    {milestone.sportType.label}
                                </span>
                            ) : null}
                            <span className="rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                {formatDate(milestone.achievedOn)}
                            </span>
                        </div>
                        <div className="mt-3 flex flex-wrap items-center gap-3">
                            <h3 className="text-lg font-semibold tracking-tight text-gray-900 dark:text-white">{milestone.title}</h3>
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
                    <div className="mt-4 rounded-2xl border border-gray-200 bg-gray-50/90 px-4 py-3 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900/70 dark:text-gray-300">
                        <div className="font-medium text-gray-900 dark:text-white">{milestone.activity.name}</div>
                        {milestone.activity.url ? (
                            <a href={milestone.activity.url} className="mt-1 inline-flex items-center gap-2 font-semibold text-strava-orange">
                                Open activity
                                <span aria-hidden="true">↗</span>
                            </a>
                        ) : null}
                    </div>
                ) : null}

                {milestone.details.length > 0 ? (
                    <div className="mt-4 space-y-2 text-sm leading-7 text-gray-600 dark:text-gray-300">
                        {milestone.details.map((detail) => (
                            <p key={detail}>{detail}</p>
                        ))}
                    </div>
                ) : null}

                {milestone.previous ? (
                    <div className="mt-4 flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                        <span className="uppercase tracking-[0.22em]">Previous</span>
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

    const visibleGroups = useMemo(() => {
        const counts = new Map<string, MilestonePreviewGroupFilter>();

        for (const milestone of filteredMilestones) {
            const current = counts.get(milestone.filterGroup.value);
            if (current) {
                current.count += 1;
                continue;
            }

            counts.set(milestone.filterGroup.value, {
                value: milestone.filterGroup.value,
                label: milestone.filterGroup.label,
                icon: milestone.filterGroup.icon,
                count: 1,
            });
        }

        return [...counts.values()];
    }, [filteredMilestones]);

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
        <div className="space-y-8 pb-8">
            <section className="glass-panel overflow-hidden rounded-[36px] p-6 md:p-8">
                <div className="grid gap-8 xl:grid-cols-[1.05fr_0.95fr]">
                    <div>
                        <div className="section-kicker">Milestones preview</div>
                        <h1 className="mt-5 max-w-3xl text-4xl font-semibold tracking-tight text-gray-900 dark:text-white md:text-5xl">
                            An achievement ledger with cleaner filtering, a richer timeline rhythm, and none of the hidden radio-input archaeology.
                        </h1>
                        <p className="mt-5 max-w-2xl text-base leading-8 text-gray-600 dark:text-gray-300 md:text-lg">
                            Milestones are a perfect preview seam: read-only, story-rich, and already shaped like a narrative timeline. This React version keeps the chronology, sharpens the filtering model, and makes previous-milestone jumps feel like part of the journey instead of a tiny hidden affordance.
                        </p>
                        <div className="mt-6 flex flex-wrap gap-3">
                            <a
                                href={buildAppPath(bootstrap.basePath, 'milestones')}
                                className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                            >
                                Open the current milestones page
                                <span aria-hidden="true">↗</span>
                            </a>
                            <button
                                type="button"
                                onClick={reload}
                                className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                            >
                                Refresh preview data
                                <span aria-hidden="true">↻</span>
                            </button>
                        </div>
                    </div>
                    <div className="rounded-[32px] border border-orange-200 bg-[linear-gradient(135deg,rgba(255,255,255,0.95),rgba(255,244,237,0.96))] p-5 shadow-[0_45px_120px_-45px_rgba(15,23,42,0.65)] dark:border-orange-900/50 dark:bg-[linear-gradient(135deg,rgba(17,24,39,0.94),rgba(49,24,17,0.92))]">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-orange-700 dark:text-orange-200">Why this seam works</div>
                        <div className="mt-4 space-y-3 text-sm leading-7 text-gray-700 dark:text-gray-200">
                            {[
                                'The data is already compiled into a clean chronological collection, so the preview can stay pure and read-only.',
                                'Timeline UI gives the React preview a new presentation pattern without introducing write-heavy complexity.',
                                'It is small enough to keep momentum high while still proving the preview can handle story-driven, filterable interfaces elegantly.',
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
                <StatCard label="Visible milestones" value={`${formatNumber(filteredMilestones.length)} / ${formatNumber(data?.summary.totalMilestones ?? 0)}`} hint="The preview filters the timeline in-memory for instant feedback." tone="orange" />
                <StatCard label="Years in view" value={formatNumber(milestonesByYear.length)} hint="Distinct timeline years visible with the current filters." tone="blue" />
                <StatCard label="Visible groups" value={formatNumber(visibleGroups.length)} hint="Achievement group variety currently present on the page." tone="emerald" />
                <StatCard label="Linked activities" value={formatNumber(filteredMilestones.filter((milestone) => milestone.activity?.url).length)} hint="Milestones that can jump directly to the source activity." tone="slate" />
            </section>

            <section className="glass-panel rounded-[32px] p-6">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div className="flex-1">
                        <div className="section-kicker">Filters and timeline controls</div>
                        <p className="mt-4 max-w-3xl text-sm leading-7 text-gray-600 dark:text-gray-300">
                            Search across milestone titles, supporting copy, activities, and countries; then tighten by achievement group, sport type, or year. The result is a timeline that feels more like an archive explorer than a static build artifact.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-3 text-sm text-gray-500 dark:text-gray-400">
                        <button
                            type="button"
                            onClick={resetFilters}
                            className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                        >
                            Reset filters
                            <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500 dark:bg-gray-800 dark:text-gray-300">{activeFilterCount}</span>
                        </button>
                        {data ? <span>Preview data refreshed {formatRequestedAt(data.requestedAt)}.</span> : null}
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
                            className="rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition focus:border-orange-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                        />
                    </label>
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-600 dark:text-gray-300">
                        <span>Sport type</span>
                        <select
                            value={filters.sportType}
                            onChange={(event) => setFilters((current) => ({...current, sportType: event.target.value}))}
                            className="rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition focus:border-orange-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
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
                            className="rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition focus:border-orange-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                        >
                            <option value="all">All years</option>
                            {data?.filters.years.map((option) => (
                                <option key={option.value} value={String(option.value)}>{option.label}</option>
                            ))}
                        </select>
                    </label>
                    <div className="rounded-[28px] border border-gray-200 bg-white/80 p-4 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-950/30 dark:text-gray-300">
                        <div className="font-semibold text-gray-900 dark:text-white">This year</div>
                        <div className="mt-1 text-3xl font-semibold tracking-tight text-strava-orange">{formatNumber(data?.summary.achievedThisYear ?? 0)}</div>
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
                                className={`inline-flex items-center gap-2 rounded-full border px-4 py-2 text-sm font-medium transition ${filters.group === 'all'
                                    ? 'border-orange-500 bg-orange-50 text-orange-700 dark:border-orange-400 dark:bg-orange-950/40 dark:text-orange-200'
                                    : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:text-white'}`}
                            >
                                <span>All</span>
                                <span className="rounded-full bg-white/80 px-2 py-0.5 text-xs dark:bg-gray-800">{formatNumber(data.summary.totalMilestones)}</span>
                            </button>
                            {data.filters.groups.map((group) => {
                                const active = filters.group === group.value;

                                return (
                                    <button
                                        key={group.value}
                                        type="button"
                                        onClick={() => setFilters((current) => ({...current, group: group.value}))}
                                        className={`inline-flex items-center gap-2 rounded-full border px-4 py-2 text-sm font-medium transition ${active
                                            ? 'border-orange-500 bg-orange-50 text-orange-700 dark:border-orange-400 dark:bg-orange-950/40 dark:text-orange-200'
                                            : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:text-white'}`}
                                    >
                                        <span>{resolveGroupIcon(group.icon)}</span>
                                        <span>{group.label}</span>
                                        <span className="rounded-full bg-white/80 px-2 py-0.5 text-xs dark:bg-gray-800">{formatNumber(group.count)}</span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                ) : null}
            </section>

            {loading && !data ? (
                <section className="glass-panel rounded-[32px] p-6 text-sm text-gray-600 dark:text-gray-300">
                    Loading milestones preview… polishing the trophy cabinet glass.
                </section>
            ) : null}

            {error ? (
                <section className="rounded-[32px] border border-rose-200 bg-rose-50/90 p-6 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                    {error}
                </section>
            ) : null}

            {data ? (
                <section className="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(320px,0.8fr)]">
                    <div className="glass-panel rounded-[32px] p-6">
                        <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <div className="section-kicker">Timeline</div>
                                <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">Achievement chronology</h2>
                            </div>
                            <div className="text-sm text-gray-500 dark:text-gray-400">
                                {formatNumber(filteredMilestones.length)} milestone{filteredMilestones.length === 1 ? '' : 's'} after filtering.
                            </div>
                        </div>

                        {0 === milestonesByYear.length ? (
                            <div className="mt-6 rounded-[28px] border border-gray-200 bg-white/80 p-6 text-sm leading-7 text-gray-600 dark:border-gray-800 dark:bg-gray-950/35 dark:text-gray-300">
                                No milestones match the current filters. Try reopening the timeline by clearing the group filter or widening the year selection.
                            </div>
                        ) : (
                            <div className="mt-8 space-y-10">
                                {milestonesByYear.map(([year, milestones]) => (
                                    <section key={year} className="space-y-6">
                                        <div className="sticky top-24 z-10 -mx-2 inline-flex rounded-full border border-white/80 bg-white/90 px-4 py-2 text-sm font-semibold tracking-[0.24em] text-gray-600 shadow-sm backdrop-blur-sm dark:border-gray-800 dark:bg-gray-950/85 dark:text-gray-300">
                                            {year}
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
                    </div>

                    <div className="space-y-4 xl:sticky xl:top-28 xl:self-start">
                        <div className="glass-panel rounded-[32px] p-6">
                            <div className="section-kicker">Preview notes</div>
                            <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">What improved here</h2>
                            <div className="mt-4 space-y-3 text-sm leading-7 text-gray-600 dark:text-gray-300">
                                <p>The original page is already useful, but the preview turns the filter state into first-class UI instead of template-only controls.</p>
                                <p>Previous-milestone jumps are preserved and made more obvious, which helps the timeline feel connected instead of isolated card-by-card.</p>
                                <p>Search, sport type, year, and group can all collaborate, so browsing no longer feels like using one switch at a time.</p>
                            </div>
                        </div>

                        <div className="glass-panel rounded-[32px] p-6">
                            <div className="section-kicker">Migration path</div>
                            <div className="mt-4 flex items-center justify-between gap-3">
                                <h2 className="text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">Where this goes next</h2>
                                <Link to="/roadmap" className="text-sm font-semibold text-strava-orange">
                                    See roadmap →
                                </Link>
                            </div>
                            <div className="mt-4 space-y-3">
                                {[
                                    'Timeline card polish and interaction patterns now exist for other achievement-style routes.',
                                    'The milestones API establishes a small, serializer-led pattern for read-only story pages.',
                                    'This keeps momentum high before moving on to heavier seams like challenges or rewind.',
                                ].map((item) => (
                                    <div key={item} className="rounded-2xl border border-gray-200 bg-white/80 p-4 text-sm leading-7 text-gray-600 dark:border-gray-800 dark:bg-gray-950/30 dark:text-gray-300">
                                        {item}
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </section>
            ) : null}
        </div>
    );
}