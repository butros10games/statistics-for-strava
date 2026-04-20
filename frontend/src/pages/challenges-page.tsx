import {useCallback, useMemo, useState} from 'react';
import {Link} from 'react-router-dom';
import {StatCard} from '../components/stat-card';
import {buildAppPath, type ReactPreviewBootstrap} from '../lib/bootstrap';
import {
    fetchChallengesPreview,
    type ChallengePreviewChallenge,
    type ChallengePreviewGroup,
    type ChallengesPreviewResponse,
} from '../lib/challenges-preview-api';
import {useAsyncResource} from '../lib/use-async-resource';

interface ChallengesPageProps {
    bootstrap: ReactPreviewBootstrap;
}

interface ChallengeFilters {
    search: string;
    year: string;
}

const initialFilters: ChallengeFilters = {
    search: '',
    year: 'all',
};

function formatRequestedAt(value: string): string {
    return new Intl.DateTimeFormat('en', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function formatNumber(value: number): string {
    return new Intl.NumberFormat('en-US').format(value);
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('en', {
        dateStyle: 'medium',
    }).format(new Date(value));
}

function resolveLogoUrl(basePath: string, logoUrl: string | null): string | null {
    if (!logoUrl) {
        return null;
    }

    return logoUrl.startsWith('http://') || logoUrl.startsWith('https://')
        ? logoUrl
        : buildAppPath(basePath, logoUrl);
}

function matchesSearch(challenge: ChallengePreviewChallenge, search: string): boolean {
    if (!search) {
        return true;
    }

    return challenge.name.toLowerCase().includes(search);
}

function ChallengeCard({
    bootstrap,
    challenge,
}: {
    bootstrap: ReactPreviewBootstrap;
    challenge: ChallengePreviewChallenge;
}) {
    const logoUrl = resolveLogoUrl(bootstrap.basePath, challenge.logoUrl);

    return (
        <a
            href={challenge.externalUrl}
            target="_blank"
            rel="noreferrer"
            className="group overflow-hidden rounded-[28px] border border-white/70 bg-white/88 p-4 shadow-[0_30px_80px_-55px_rgba(15,23,42,0.65)] transition hover:-translate-y-0.5 hover:border-orange-200 dark:border-gray-800 dark:bg-gray-950/35 dark:hover:border-gray-700"
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                    <div className="text-xs font-semibold uppercase tracking-[0.22em] text-gray-500 dark:text-gray-400">
                        Completed {formatDate(challenge.completedDate)}
                    </div>
                    <h3 className="mt-2 line-clamp-2 text-sm font-semibold leading-6 text-gray-900 dark:text-white">
                        {challenge.name}
                    </h3>
                </div>
                <span className="rounded-full border border-orange-200 bg-orange-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-orange-700 dark:border-orange-900/50 dark:bg-orange-950/30 dark:text-orange-200">
                    Badge
                </span>
            </div>

            <div className="mt-4 overflow-hidden rounded-[24px] border border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-gray-900/70">
                {logoUrl ? (
                    <img src={logoUrl} alt={challenge.name} className="aspect-square w-full object-cover transition duration-300 group-hover:scale-[1.03]" />
                ) : (
                    <div className="flex aspect-square items-center justify-center bg-[radial-gradient(circle_at_top_left,rgba(242,103,34,0.18),transparent_32%),radial-gradient(circle_at_80%_10%,rgba(15,23,42,0.07),transparent_28%)] p-6 text-center text-sm font-semibold leading-6 text-gray-600 dark:text-gray-300">
                        {challenge.name}
                    </div>
                )}
            </div>

            <div className="mt-4 flex items-center justify-between gap-3 text-sm text-gray-500 dark:text-gray-400">
                <span>{challenge.hasLocalLogo ? 'Local asset' : 'Imported asset'}</span>
                <span className="font-semibold text-strava-orange">Open on Strava ↗</span>
            </div>
        </a>
    );
}

export function ChallengesPage({bootstrap}: ChallengesPageProps) {
    const [filters, setFilters] = useState<ChallengeFilters>(initialFilters);

    const loadChallenges = useCallback(
        (signal: AbortSignal): Promise<ChallengesPreviewResponse> => fetchChallengesPreview(bootstrap.basePath, signal),
        [bootstrap.basePath],
    );

    const {data, loading, error, reload} = useAsyncResource(loadChallenges);

    const filteredGroups = useMemo(() => {
        if (!data) {
            return [];
        }

        const search = filters.search.trim().toLowerCase();

        return data.groups
            .map((group) => ({
                ...group,
                challenges: group.challenges.filter((challenge) => {
                    if ('all' !== filters.year && String(group.year) !== filters.year) {
                        return false;
                    }

                    return matchesSearch(challenge, search);
                }),
            }))
            .filter((group) => group.challenges.length > 0)
            .map((group) => ({
                ...group,
                count: group.challenges.length,
            }));
    }, [data, filters]);

    const visibleChallenges = useMemo(
        () => filteredGroups.reduce((count, group) => count + group.count, 0),
        [filteredGroups],
    );

    const visibleYears = useMemo(
        () => new Set(filteredGroups.map((group) => group.year)).size,
        [filteredGroups],
    );

    const localLogosVisible = useMemo(
        () => filteredGroups.flatMap((group) => group.challenges).filter((challenge) => challenge.hasLocalLogo).length,
        [filteredGroups],
    );

    const activeFilterCount = useMemo(
        () => [filters.search, 'all' !== filters.year ? filters.year : ''].filter(Boolean).length,
        [filters],
    );

    function resetFilters() {
        setFilters(initialFilters);
    }

    return (
        <div className="space-y-8 pb-8">
            <section className="glass-panel overflow-hidden rounded-[36px] p-6 md:p-8">
                <div className="grid gap-8 xl:grid-cols-[1.08fr_0.92fr]">
                    <div>
                        <div className="section-kicker">Challenges preview</div>
                        <h1 className="mt-5 max-w-3xl text-4xl font-semibold tracking-tight text-gray-900 dark:text-white md:text-5xl">
                            A cleaner challenge cabinet with grouped history, faster browsing, and zero template-era guesswork.
                        </h1>
                        <p className="mt-5 max-w-2xl text-base leading-8 text-gray-600 dark:text-gray-300 md:text-lg">
                            The original challenges page is already straightforward, which makes it a great migration seam. This preview keeps the month-grouped structure, adds explicit search and year filtering, and turns the badge wall into a more polished archive surface.
                        </p>
                        <div className="mt-6 flex flex-wrap gap-3">
                            <a
                                href={buildAppPath(bootstrap.basePath, 'challenges')}
                                className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                            >
                                Open the current challenges page
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
                    <div className="rounded-[32px] border border-emerald-200 bg-[linear-gradient(135deg,rgba(255,255,255,0.95),rgba(236,253,245,0.96))] p-5 shadow-[0_45px_120px_-45px_rgba(15,23,42,0.65)] dark:border-emerald-900/50 dark:bg-[linear-gradient(135deg,rgba(17,24,39,0.94),rgba(0,44,34,0.92))]">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-emerald-700 dark:text-emerald-200">Why this seam works</div>
                        <div className="mt-4 space-y-3 text-sm leading-7 text-gray-700 dark:text-gray-200">
                            {[
                                'One repository and one grouping rule make this an ideal fast slice after milestones.',
                                'It reuses gallery polish from photos while keeping the data model almost comically simple.',
                                'Month-grouped achievement walls are a nice bridge toward future richer archive or calendar experiences.',
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
                <StatCard label="Visible challenges" value={`${formatNumber(visibleChallenges)} / ${formatNumber(data?.summary.totalChallenges ?? 0)}`} hint="The challenge wall filters instantly without leaving the page." tone="orange" />
                <StatCard label="Months in view" value={formatNumber(filteredGroups.length)} hint="Distinct completion months still visible after filtering." tone="blue" />
                <StatCard label="Years represented" value={formatNumber(visibleYears)} hint="Handy when you want to skim challenge eras instead of just totals." tone="emerald" />
                <StatCard label="Local logos" value={formatNumber(localLogosVisible)} hint="Challenges backed by imported local logo assets in the filtered result." tone="slate" />
            </section>

            <section className="glass-panel rounded-[32px] p-6">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div className="flex-1">
                        <div className="section-kicker">Filters and archive controls</div>
                        <p className="mt-4 max-w-3xl text-sm leading-7 text-gray-600 dark:text-gray-300">
                            The preview keeps the existing month grouping, but adds explicit archive controls so challenge history can be narrowed by year or searched by name without relying on visual scanning alone.
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

                <div className="mt-6 grid gap-4 lg:grid-cols-[1.4fr_0.8fr_auto]">
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-600 dark:text-gray-300">
                        <span>Search completed challenges</span>
                        <input
                            type="search"
                            value={filters.search}
                            onChange={(event) => setFilters((current) => ({...current, search: event.target.value}))}
                            placeholder="Search the badge cabinet"
                            className="rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition focus:border-orange-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                        />
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
                                <option key={option.value} value={option.value}>{option.label}</option>
                            ))}
                        </select>
                    </label>
                    <div className="rounded-[28px] border border-gray-200 bg-white/80 p-4 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-950/30 dark:text-gray-300">
                        <div className="font-semibold text-gray-900 dark:text-white">Imported logos</div>
                        <div className="mt-1 text-3xl font-semibold tracking-tight text-strava-orange">{formatNumber(data?.summary.localLogoCount ?? 0)}</div>
                        <p className="mt-2 leading-6">Local logo assets available in the current archive snapshot.</p>
                    </div>
                </div>
            </section>

            {loading && !data ? (
                <section className="glass-panel rounded-[32px] p-6 text-sm text-gray-600 dark:text-gray-300">
                    Loading challenges preview… alphabetizing the trophy shelf.
                </section>
            ) : null}

            {error ? (
                <section className="rounded-[32px] border border-rose-200 bg-rose-50/90 p-6 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                    {error}
                </section>
            ) : null}

            {data ? (
                <section className="grid gap-6 xl:grid-cols-[minmax(0,1.16fr)_minmax(320px,0.84fr)]">
                    <div className="space-y-6">
                        {0 === filteredGroups.length ? (
                            <div className="glass-panel rounded-[32px] p-6 text-sm leading-7 text-gray-600 dark:text-gray-300">
                                No challenges match the current filters. Try widening the archive year or clearing the search term.
                            </div>
                        ) : (
                            filteredGroups.map((group: ChallengePreviewGroup) => (
                                <section key={group.monthId} className="glass-panel rounded-[32px] p-6">
                                    <div className="flex items-center justify-between gap-3">
                                        <div>
                                            <div className="section-kicker">Challenge month</div>
                                            <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">{group.monthLabel}</h2>
                                        </div>
                                        <div className="rounded-full border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                            {formatNumber(group.count)}
                                        </div>
                                    </div>

                                    <div className="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                        {group.challenges.map((challenge) => (
                                            <ChallengeCard key={challenge.id} bootstrap={bootstrap} challenge={challenge} />
                                        ))}
                                    </div>
                                </section>
                            ))
                        )}
                    </div>

                    <div className="space-y-4 xl:sticky xl:top-28 xl:self-start">
                        <div className="glass-panel rounded-[32px] p-6">
                            <div className="section-kicker">Preview notes</div>
                            <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">What changed here</h2>
                            <div className="mt-4 space-y-3 text-sm leading-7 text-gray-600 dark:text-gray-300">
                                <p>The month-grouped structure stays intact, but the controls are promoted into explicit, inspectable page state.</p>
                                <p>Cards are larger and easier to scan, which gives each badge a little more ceremonial dignity. Tiny trophies deserve good lighting too.</p>
                                <p>The page is still gloriously read-only, making it a very low-risk parallel-preview slice.</p>
                            </div>
                        </div>

                        <div className="glass-panel rounded-[32px] p-6">
                            <div className="section-kicker">Migration path</div>
                            <div className="mt-4 flex items-center justify-between gap-3">
                                <h2 className="text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">Next up</h2>
                                <Link to="/roadmap" className="text-sm font-semibold text-strava-orange">
                                    See roadmap →
                                </Link>
                            </div>
                            <div className="mt-4 space-y-3">
                                {[
                                    'Challenges proves the preview can handle grouped image-heavy archives with almost no backend friction.',
                                    'That sets the stage nicely for larger read-heavy seams like rewind, where grouped data and stronger presentation matter even more.',
                                    'The remaining big frontier is richer analytics composition, not basic navigation or gallery state anymore.',
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