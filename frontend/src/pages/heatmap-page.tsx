import {useCallback, useEffect, useMemo, useState} from 'react';
import {HeatmapMap} from '../components/heatmap-map';
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
        <div className={`rounded-lg border p-4 transition ${selected
            ? 'border-orange-300 bg-orange-50 dark:border-orange-400 dark:bg-orange-950/30'
            : 'border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950/35'}`}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="truncate text-sm font-semibold text-gray-900 dark:text-white">{route.name}</h3>
                        <span className="rounded-lg border border-sky-200 bg-sky-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-700 dark:border-sky-900/50 dark:bg-sky-950/30 dark:text-sky-200">
                            {route.sportType.label}
                        </span>
                        {route.workoutType ? (
                            <span className="rounded-lg border border-violet-200 bg-violet-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-violet-700 dark:border-violet-900/50 dark:bg-violet-950/30 dark:text-violet-200">
                                {route.workoutType.label}
                            </span>
                        ) : null}
                        {'true' === route.filterables.isCommute ? (
                            <span className="rounded-lg border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-200">
                                Commute
                            </span>
                        ) : null}
                    </div>
                    <p className="mt-2 text-sm leading-7 text-gray-500 dark:text-gray-400">
                        {route.startDate}
                        {' · '}
                        {route.distance}
                        {route.startLocation.countryName ? ` · ${route.startLocation.countryName}` : ''}
                        {route.startLocation.state ? ` · ${route.startLocation.state}` : ''}
                    </p>
                </div>
                {flag ? <img src={flag} alt={route.startLocation.countryName ?? route.startLocation.countryCode ?? 'Country'} className="h-5 w-7 rounded-sm object-cover shadow-sm" /> : null}
            </div>

            <div className="mt-3 flex flex-wrap gap-3">
                {route.activityUrl ? (
                    <a
                        href={route.activityUrl}
                        className="ui-button"
                    >
                        Open activity
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
        <div className="space-y-6 pb-6">
            <section className="ui-section">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div className="min-w-0">
                        <h1 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white md:text-2xl">Heatmap</h1>
                        <p className="mt-1 max-w-3xl text-sm leading-7 text-gray-500 dark:text-gray-400">
                            Filter route history by sport type, date, commute status, and workout type, then inspect nearby routes on the map.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <a href={buildAppPath(bootstrap.basePath, 'heatmap')} className="ui-button">
                            Open classic heatmap page
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
                            Keep the map visible while refining routes by sport type, date, commute status, and workout type.
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
                        <div className="ui-pill">{formatNumber(filteredRoutes.length)} routes</div>
                        {data ? <span>Refreshed {formatRequestedAt(data.requestedAt)}.</span> : null}
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
                            className="ui-input"
                        />
                    </label>
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-600 dark:text-gray-300">
                        <span>Workout type</span>
                        <select
                            value={filters.workoutType}
                            onChange={(event) => setFilters((current) => ({...current, workoutType: event.target.value}))}
                            className="ui-input"
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
                            className="ui-input"
                        />
                    </label>
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-600 dark:text-gray-300">
                        <span>Date to</span>
                        <input
                            type="date"
                            value={filters.startDateTo}
                            onChange={(event) => setFilters((current) => ({...current, startDateTo: event.target.value}))}
                            className="ui-input"
                        />
                    </label>
                </div>

                <div className="mt-4 flex flex-wrap gap-3">
                    <div>
                        <div className="text-sm font-medium text-gray-600 dark:text-gray-300">Commute filter</div>
                        <div className="mt-2 inline-flex rounded-lg border border-gray-200 bg-gray-100 p-1 dark:border-gray-700 dark:bg-gray-900">
                            {[
                                {value: 'all', label: 'All'},
                                {value: 'true', label: 'Commutes only'},
                                {value: 'false', label: 'Exclude commutes'},
                            ].map((option) => (
                                <button
                                    key={option.value}
                                    type="button"
                                    onClick={() => setFilters((current) => ({...current, commute: option.value as CommuteFilter}))}
                                    className={`rounded-md px-4 py-2 text-sm font-medium transition ${filters.commute === option.value
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
                                        className={`rounded-lg border px-4 py-2 text-sm font-medium transition ${active
                                            ? 'border-orange-300 bg-orange-50 text-orange-700 dark:border-orange-400 dark:bg-orange-950/40 dark:text-orange-200'
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
                                    <div key={place.countryCode} className="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                        {flag ? <img src={flag} alt={place.label} className="h-4 w-6 rounded-sm object-cover" /> : null}
                                        <span>{place.label}</span>
                                        <span className="ui-pill">{formatNumber(place.routeCount)}</span>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                ) : null}
            </section>

            {loading && !data ? (
                <section className="ui-section text-sm text-gray-600 dark:text-gray-300">
                    Loading heatmap.
                </section>
            ) : null}

            {error ? (
                <section className="rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                    {error}
                </section>
            ) : null}

            {data ? (
                <section className="space-y-4">
                    <div className="ui-section p-4">
                        <div className="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Map</h2>
                                <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Click near a route line to inspect the closest matches.</p>
                            </div>
                        </div>
                        <div>
                            <HeatmapMap
                                config={data.config}
                                routes={filteredRoutes}
                                selectedRouteIds={selectedRouteIds}
                                onSelectRoutes={setSelectedRouteIds}
                            />
                        </div>
                    </div>

                    <div className="ui-section">
                        <div className="mb-4 flex items-center justify-between gap-3">
                            <div>
                                <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">
                                    {selectedRoutes.length > 0 ? 'Routes near your last click' : 'Recent visible routes'}
                                </h2>
                                <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {selectedRoutes.length > 0
                                        ? `The map found ${selectedRoutes.length} nearby route${selectedRoutes.length === 1 ? '' : 's'}.`
                                        : 'No route selection yet, so the newest visible routes are shown below.'}
                                </p>
                            </div>
                        </div>
                        {0 === inspectionRoutes.length ? (
                            <div className="text-sm leading-7 text-gray-600 dark:text-gray-300">
                                No routes match the current filters.
                            </div>
                        ) : (
                            <div className="space-y-3">
                                {inspectionRoutes.map((route) => (
                                    <RouteCard
                                        key={route.id}
                                        bootstrap={bootstrap}
                                        route={route}
                                        selected={selectedRouteIds.includes(route.id)}
                                    />
                                ))}
                            </div>
                        )}
                    </div>
                </section>
            ) : null}
        </div>
    );
}