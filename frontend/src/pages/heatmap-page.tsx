import {useCallback, useEffect, useMemo, useState} from 'react';
import {Link} from 'react-router-dom';
import {HeatmapMap} from '../components/heatmap-map';
import {StatCard} from '../components/stat-card';
import {buildAppPath, type ReactPreviewBootstrap} from '../lib/bootstrap';
import {fetchHeatmapPreview, type HeatmapPreviewResponse, type HeatmapPreviewRoute} from '../lib/heatmap-preview-api';
import {useAsyncResource} from '../lib/use-async-resource';

interface HeatmapPageProps {
    bootstrap: ReactPreviewBootstrap;
}

type CommuteFilter = 'all' | 'true' | 'false';

interface FilterState {
    search: string;
    sportTypes: string[];
    workoutType: string;
    commute: CommuteFilter;
    startDateFrom: string;
    startDateTo: string;
}

const initialFilters: FilterState = {
    search: '',
    sportTypes: [],
    workoutType: '',
    commute: 'all',
    startDateFrom: '',
    startDateTo: '',
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

function parseDateFloor(value: string): number | null {
    if (!value) {
        return null;
    }

    const timestamp = new Date(`${value}T00:00:00`).getTime();

    return Number.isFinite(timestamp) ? timestamp : null;
}

function parseDateCeiling(value: string): number | null {
    if (!value) {
        return null;
    }

    const timestamp = new Date(`${value}T23:59:59`).getTime();

    return Number.isFinite(timestamp) ? timestamp : null;
}

function matchesSearch(route: HeatmapPreviewRoute, search: string): boolean {
    if ('' === search) {
        return true;
    }

    const haystack = [
        route.name,
        route.sportType.label,
        route.workoutType?.label,
        route.startLocation.countryName,
        route.startLocation.state,
    ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();

    return haystack.includes(search);
}

function flagUrl(basePath: string, countryCode: string | null): string | null {
    if (!countryCode) {
        return null;
    }

    return buildAppPath(basePath, `assets/images/flags/${countryCode.toLowerCase()}.svg`);
}

function RouteCard({
    bootstrap,
    route,
    selected,
}: {
    bootstrap: ReactPreviewBootstrap;
    route: HeatmapPreviewRoute;
    selected: boolean;
}) {
    const flag = flagUrl(bootstrap.basePath, route.startLocation.countryCode);

    return (
        <div className={`rounded-[26px] border p-4 transition ${selected
            ? 'border-orange-500 bg-orange-50/90 shadow-[0_30px_80px_-55px_rgba(242,103,34,0.42)] dark:border-orange-400 dark:bg-orange-950/30'
            : 'border-gray-200 bg-white/88 dark:border-gray-800 dark:bg-gray-950/35'}`}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="truncate text-base font-semibold text-gray-900 dark:text-white">{route.name}</h3>
                        <span className="rounded-full border border-sky-200 bg-sky-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-sky-700 dark:border-sky-900/50 dark:bg-sky-950/30 dark:text-sky-200">
                            {route.sportType.label}
                        </span>
                        {route.workoutType ? (
                            <span className="rounded-full border border-violet-200 bg-violet-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-violet-700 dark:border-violet-900/50 dark:bg-violet-950/30 dark:text-violet-200">
                                {route.workoutType.label}
                            </span>
                        ) : null}
                        {'true' === route.filterables.isCommute ? (
                            <span className="rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-200">
                                Commute
                            </span>
                        ) : null}
                    </div>
                    <p className="mt-3 text-sm leading-7 text-gray-600 dark:text-gray-300">
                        {route.startDate}
                        {' · '}
                        {route.distance}
                        {route.startLocation.countryName ? ` · ${route.startLocation.countryName}` : ''}
                        {route.startLocation.state ? ` · ${route.startLocation.state}` : ''}
                    </p>
                </div>
                {flag ? <img src={flag} alt={route.startLocation.countryName ?? route.startLocation.countryCode ?? 'Country'} className="h-5 w-7 rounded-sm object-cover shadow-sm" /> : null}
            </div>

            <div className="mt-4 flex flex-wrap gap-3">
                {route.activityUrl ? (
                    <a
                        href={route.activityUrl}
                        className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                    >
                        Open activity
                        <span aria-hidden="true">↗</span>
                    </a>
                ) : null}
            </div>
        </div>
    );
}

export function HeatmapPage({bootstrap}: HeatmapPageProps) {
    const [filters, setFilters] = useState<FilterState>(initialFilters);
    const [selectedRouteIds, setSelectedRouteIds] = useState<string[]>([]);

    const loadHeatmap = useCallback(
        (signal: AbortSignal): Promise<HeatmapPreviewResponse> => fetchHeatmapPreview(bootstrap.basePath, signal),
        [bootstrap.basePath],
    );
    const {data, loading, error, reload} = useAsyncResource(loadHeatmap);

    const filteredRoutes = useMemo(() => {
        if (!data) {
            return [];
        }

        const searchValue = filters.search.trim().toLowerCase();
        const selectedSportTypes = new Set(filters.sportTypes);
        const startDateFrom = parseDateFloor(filters.startDateFrom);
        const startDateTo = parseDateCeiling(filters.startDateTo);

        return [...data.routes]
            .filter((route) => {
                if (!matchesSearch(route, searchValue)) {
                    return false;
                }

                if (selectedSportTypes.size > 0 && !selectedSportTypes.has(route.sportType.value)) {
                    return false;
                }

                if (filters.workoutType && route.workoutType?.value !== filters.workoutType) {
                    return false;
                }

                if ('all' !== filters.commute && route.filterables.isCommute !== filters.commute) {
                    return false;
                }

                if (null !== startDateFrom && route.filterables['start-date'] < startDateFrom) {
                    return false;
                }

                if (null !== startDateTo && route.filterables['start-date'] > startDateTo) {
                    return false;
                }

                return true;
            })
            .sort((left, right) => right.filterables['start-date'] - left.filterables['start-date']);
    }, [data, filters]);

    useEffect(() => {
        if (0 === selectedRouteIds.length) {
            return;
        }

        const visibleRouteIds = new Set(filteredRoutes.map((route) => route.id));

        setSelectedRouteIds((current) => current.filter((routeId) => visibleRouteIds.has(routeId)));
    }, [filteredRoutes, selectedRouteIds.length]);

    const selectedRoutes = useMemo(() => {
        const routeMap = new Map(filteredRoutes.map((route) => [route.id, route]));

        return selectedRouteIds
            .map((routeId) => routeMap.get(routeId) ?? null)
            .filter((route): route is HeatmapPreviewRoute => null !== route);
    }, [filteredRoutes, selectedRouteIds]);

    const inspectionRoutes = useMemo(
        () => selectedRoutes.length > 0 ? selectedRoutes : filteredRoutes.slice(0, 8),
        [filteredRoutes, selectedRoutes],
    );

    const countryHighlights = useMemo(() => {
        const counts = new Map<string, {countryCode: string; label: string; routeCount: number}>();

        for (const route of filteredRoutes) {
            if (!route.startLocation.countryCode || !route.startLocation.countryName) {
                continue;
            }

            const current = counts.get(route.startLocation.countryCode) ?? {
                countryCode: route.startLocation.countryCode,
                label: route.startLocation.countryName,
                routeCount: 0,
            };

            current.routeCount += 1;
            counts.set(route.startLocation.countryCode, current);
        }

        return [...counts.values()]
            .sort((left, right) => right.routeCount - left.routeCount || left.label.localeCompare(right.label))
            .slice(0, 10);
    }, [filteredRoutes]);

    const activeFilterCount = useMemo(() => {
        return [
            filters.search,
            filters.workoutType,
            'all' !== filters.commute ? filters.commute : '',
            filters.startDateFrom,
            filters.startDateTo,
            ...filters.sportTypes,
        ].filter(Boolean).length;
    }, [filters]);

    function toggleSportType(value: string) {
        setFilters((current) => ({
            ...current,
            sportTypes: current.sportTypes.includes(value)
                ? current.sportTypes.filter((entry) => entry !== value)
                : [...current.sportTypes, value],
        }));
    }

    function resetFilters() {
        setFilters(initialFilters);
        setSelectedRouteIds([]);
    }

    return (
        <div className="space-y-8 pb-8">
            <section className="glass-panel overflow-hidden rounded-[36px] p-6 md:p-8">
                <div className="grid gap-8 xl:grid-cols-[1.1fr_0.9fr]">
                    <div>
                        <div className="section-kicker">Heatmap preview</div>
                        <h1 className="mt-5 max-w-3xl text-4xl font-semibold tracking-tight text-gray-900 dark:text-white md:text-5xl">
                            Route density, filter state, and click inspection in a React map surface instead of a DOM manager maze.
                        </h1>
                        <p className="mt-5 max-w-2xl text-base leading-8 text-gray-600 dark:text-gray-300 md:text-lg">
                            The legacy heatmap already ships clean route JSON and a focused rendering concern. This preview keeps that read-only shape, moves filters into typed state, and gives the map a calmer companion panel for nearby-route inspection.
                        </p>
                        <div className="mt-6 flex flex-wrap gap-3">
                            <a
                                href={buildAppPath(bootstrap.basePath, 'heatmap')}
                                className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                            >
                                Open the current heatmap page
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
                    <div className="rounded-[32px] border border-sky-200 bg-[linear-gradient(135deg,rgba(255,255,255,0.95),rgba(240,249,255,0.96))] p-5 shadow-[0_45px_120px_-45px_rgba(15,23,42,0.65)] dark:border-sky-900/50 dark:bg-[linear-gradient(135deg,rgba(17,24,39,0.94),rgba(8,47,73,0.92))]">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-sky-700 dark:text-sky-200">Why this seam works</div>
                        <div className="mt-4 space-y-3 text-sm leading-7 text-gray-700 dark:text-gray-200">
                            {[
                                'The backend already exports route rows and map config, so the preview can stay JSON-driven without inventing new domain logic.',
                                'Leaflet remains the renderer, but route filtering and inspection move into typed React state and route-level composition.',
                                'It expands the preview app beyond tables and charts into map-heavy exploration, which is a healthy stress test for the parallel architecture.',
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
                <StatCard label="Visible routes" value={`${formatNumber(filteredRoutes.length)} / ${formatNumber(data?.summary.totalRoutes ?? 0)}`} hint="Filtered routes update immediately in the preview layer." tone="orange" />
                <StatCard label="Countries" value={formatNumber(new Set(filteredRoutes.map((route) => route.startLocation.countryCode).filter(Boolean)).size)} hint="Distinct starting countries currently represented on the map." tone="emerald" />
                <StatCard label="Commutes" value={formatNumber(filteredRoutes.filter((route) => route.filterables.isCommute === 'true').length)} hint="Commute-tagged routes in the current filtered set." tone="blue" />
                <StatCard label="Workout-tagged" value={formatNumber(filteredRoutes.filter((route) => null !== route.workoutType).length)} hint="Routes attached to an explicit workout type." tone="slate" />
            </section>

            <section className="glass-panel rounded-[32px] p-6">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div className="flex-1">
                        <div className="section-kicker">Filters and route context</div>
                        <p className="mt-4 max-w-3xl text-sm leading-7 text-gray-600 dark:text-gray-300">
                            The preview keeps the heatmap’s sport type, date, commute, and workout-type filtering model, while giving the route explorer a cleaner side panel and a more obvious reset path.
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

                <div className="mt-6 grid gap-4 lg:grid-cols-[1.3fr_repeat(3,minmax(0,1fr))]">
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-600 dark:text-gray-300">
                        <span>Search route, country, state, or sport type</span>
                        <input
                            type="search"
                            value={filters.search}
                            onChange={(event) => setFilters((current) => ({...current, search: event.target.value}))}
                            placeholder="Search the route cloud"
                            className="rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition focus:border-orange-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                        />
                    </label>
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-600 dark:text-gray-300">
                        <span>Workout type</span>
                        <select
                            value={filters.workoutType}
                            onChange={(event) => setFilters((current) => ({...current, workoutType: event.target.value}))}
                            className="rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition focus:border-orange-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                        >
                            <option value="">All workout types</option>
                            {data?.filters.workoutTypes.map((option) => (
                                <option key={option.value} value={option.value}>{option.label}</option>
                            ))}
                        </select>
                    </label>
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-600 dark:text-gray-300">
                        <span>Date from</span>
                        <input
                            type="date"
                            value={filters.startDateFrom}
                            onChange={(event) => setFilters((current) => ({...current, startDateFrom: event.target.value}))}
                            className="rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition focus:border-orange-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                        />
                    </label>
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-600 dark:text-gray-300">
                        <span>Date to</span>
                        <input
                            type="date"
                            value={filters.startDateTo}
                            onChange={(event) => setFilters((current) => ({...current, startDateTo: event.target.value}))}
                            className="rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition focus:border-orange-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                        />
                    </label>
                </div>

                <div className="mt-4 flex flex-wrap gap-3">
                    <div>
                        <div className="text-sm font-medium text-gray-600 dark:text-gray-300">Commute filter</div>
                        <div className="mt-2 inline-flex rounded-2xl border border-gray-200 bg-gray-100 p-1 dark:border-gray-700 dark:bg-gray-900">
                            {[
                                {value: 'all', label: 'All'},
                                {value: 'true', label: 'Commutes only'},
                                {value: 'false', label: 'Exclude commutes'},
                            ].map((option) => (
                                <button
                                    key={option.value}
                                    type="button"
                                    onClick={() => setFilters((current) => ({...current, commute: option.value as CommuteFilter}))}
                                    className={`rounded-[18px] px-4 py-2 text-sm font-medium transition ${filters.commute === option.value
                                        ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-white'
                                        : 'text-gray-500 dark:text-gray-400'}`}
                                >
                                    {option.label}
                                </button>
                            ))}
                        </div>
                    </div>
                </div>

                {data?.filters.sportTypes.length ? (
                    <div className="mt-6">
                        <div className="text-sm font-medium text-gray-600 dark:text-gray-300">Sport types</div>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {data.filters.sportTypes.map((option) => {
                                const active = filters.sportTypes.includes(option.value);

                                return (
                                    <button
                                        key={option.value}
                                        type="button"
                                        onClick={() => toggleSportType(option.value)}
                                        className={`rounded-full border px-4 py-2 text-sm font-medium transition ${active
                                            ? 'border-orange-500 bg-orange-50 text-orange-700 dark:border-orange-400 dark:bg-orange-950/40 dark:text-orange-200'
                                            : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:text-white'}`}
                                    >
                                        {option.label}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                ) : null}

                {countryHighlights.length > 0 ? (
                    <div className="mt-6">
                        <div className="text-sm font-medium text-gray-600 dark:text-gray-300">Top starting countries in the current view</div>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {countryHighlights.map((place) => {
                                const flag = flagUrl(bootstrap.basePath, place.countryCode);

                                return (
                                    <div key={place.countryCode} className="inline-flex items-center gap-2 rounded-full border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                        {flag ? <img src={flag} alt={place.label} className="h-4 w-6 rounded-sm object-cover" /> : null}
                                        <span>{place.label}</span>
                                        <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500 dark:bg-gray-800 dark:text-gray-300">{formatNumber(place.routeCount)}</span>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                ) : null}
            </section>

            {loading && !data ? (
                <section className="glass-panel rounded-[32px] p-6 text-sm text-gray-600 dark:text-gray-300">
                    Loading heatmap preview… tracing your route history one polyline at a time.
                </section>
            ) : null}

            {error ? (
                <section className="rounded-[32px] border border-rose-200 bg-rose-50/90 p-6 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                    {error}
                </section>
            ) : null}

            {data ? (
                <section className="grid gap-6 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)]">
                    <div className="glass-panel rounded-[32px] p-5">
                        <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <div className="section-kicker">Map explorer</div>
                                <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">Filtered route density</h2>
                            </div>
                            <div className="text-sm text-gray-500 dark:text-gray-400">
                                Click near a route line to inspect the closest matches.
                            </div>
                        </div>
                        <div className="mt-5">
                            <HeatmapMap
                                config={data.config}
                                routes={filteredRoutes}
                                selectedRouteIds={selectedRouteIds}
                                onSelectRoutes={setSelectedRouteIds}
                            />
                        </div>
                    </div>

                    <div className="space-y-4">
                        <div className="glass-panel rounded-[32px] p-6">
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <div className="section-kicker">Route inspection</div>
                                    <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">
                                        {selectedRoutes.length > 0 ? 'Routes near your last click' : 'Recent visible routes'}
                                    </h2>
                                </div>
                                <Link to="/roadmap" className="text-sm font-semibold text-strava-orange">
                                    See migration roadmap →
                                </Link>
                            </div>
                            <p className="mt-4 text-sm leading-7 text-gray-600 dark:text-gray-300">
                                {selectedRoutes.length > 0
                                    ? `The map found ${selectedRoutes.length} nearby route${selectedRoutes.length === 1 ? '' : 's'} from your last click.`
                                    : 'No route selection yet, so the panel shows the newest visible routes in the filtered set.'}
                            </p>
                        </div>

                        {0 === inspectionRoutes.length ? (
                            <div className="glass-panel rounded-[32px] p-6 text-sm leading-7 text-gray-600 dark:text-gray-300">
                                No routes match the current filters. Try widening the date range or switching the commute filter back to neutral.
                            </div>
                        ) : (
                            inspectionRoutes.map((route) => (
                                <RouteCard
                                    key={route.id}
                                    bootstrap={bootstrap}
                                    route={route}
                                    selected={selectedRouteIds.includes(route.id)}
                                />
                            ))
                        )}
                    </div>
                </section>
            ) : null}
        </div>
    );
}