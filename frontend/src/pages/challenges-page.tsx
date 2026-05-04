import {useCallback, useMemo, useState} from 'react';
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
            title={challenge.name}
            className="group block overflow-hidden rounded-lg border border-gray-200 bg-white transition hover:border-orange-300 dark:border-gray-800 dark:bg-gray-950/40 dark:hover:border-gray-700"
        >
            {logoUrl ? (
                <img src={logoUrl} alt={challenge.name} className="aspect-square w-full object-cover transition duration-300 group-hover:scale-[1.02]" />
            ) : (
                <div className="flex aspect-square items-center justify-center bg-gray-50 p-4 text-center text-xs font-semibold leading-5 text-gray-600 dark:bg-gray-900/70 dark:text-gray-300">
                    {challenge.name}
                </div>
            )}
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

    const activeFilterCount = useMemo(
        () => [filters.search, 'all' !== filters.year ? filters.year : ''].filter(Boolean).length,
        [filters],
    );

    function resetFilters() {
        setFilters(initialFilters);
    }

    return (
        <div className="space-y-6 pb-6">
            <section className="ui-section">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div className="min-w-0">
                        <h1 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white md:text-2xl">Challenges</h1>
                        <p className="mt-1 max-w-3xl text-sm leading-7 text-gray-500 dark:text-gray-400">
                            Month-grouped archive of completed Strava challenges.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <a href={buildAppPath(bootstrap.basePath, 'challenges')} className="ui-button">
                            Open classic challenges page
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
                        <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Archive controls</h2>
                        <p className="mt-1 max-w-3xl text-sm leading-7 text-gray-500 dark:text-gray-400">
                            Search by challenge name or narrow the archive to a single year.
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
                        <div className="ui-pill">{formatNumber(visibleChallenges)} results</div>
                        {data ? <span>Refreshed {formatRequestedAt(data.requestedAt)}.</span> : null}
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
                            className="ui-input"
                        />
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
                                <option key={option.value} value={option.value}>{option.label}</option>
                            ))}
                        </select>
                    </label>
                    <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900/40 dark:text-gray-300">
                        <div className="font-semibold text-gray-900 dark:text-white">Imported logos</div>
                        <div className="mt-1 text-2xl font-semibold tracking-tight text-strava-orange">{formatNumber(data?.summary.localLogoCount ?? 0)}</div>
                        <p className="mt-2 leading-6">Local logo assets available in the current archive snapshot.</p>
                    </div>
                </div>
            </section>

            {loading && !data ? (
                <section className="ui-section text-sm text-gray-600 dark:text-gray-300">
                    Loading challenges.
                </section>
            ) : null}

            {error ? (
                <section className="rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                    {error}
                </section>
            ) : null}

            {data ? (
                <section className="space-y-4">
                        {0 === filteredGroups.length ? (
                            <div className="ui-section text-sm leading-7 text-gray-600 dark:text-gray-300">
                                No challenges match the current filters.
                            </div>
                        ) : (
                            filteredGroups.map((group: ChallengePreviewGroup) => (
                                <section key={group.monthId} className="ui-section">
                                    <div className="flex items-center justify-between gap-3">
                                        <h2 className="text-base font-semibold tracking-tight text-gray-900 dark:text-white">{group.monthLabel}</h2>
                                        <div className="ui-pill">
                                            {formatNumber(group.count)}
                                        </div>
                                    </div>

                                    <div className="mt-4 grid grid-cols-4 gap-3 sm:grid-cols-5 lg:grid-cols-6 xl:grid-cols-8">
                                        {group.challenges.map((challenge) => (
                                            <ChallengeCard key={challenge.id} bootstrap={bootstrap} challenge={challenge} />
                                        ))}
                                    </div>
                                </section>
                            ))
                        )}
                </section>
            ) : null}
        </div>
    );
}