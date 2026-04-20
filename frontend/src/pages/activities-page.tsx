import {useCallback, useMemo, useState} from 'react';
import {Link} from 'react-router-dom';
import {StatCard} from '../components/stat-card';
import {type ReactPreviewBootstrap, buildAppPath} from '../lib/bootstrap';
import {
    fetchActivitiesPreview,
    type ActivitiesPreviewFilterOption,
    type ActivitiesPreviewResponse,
    type ActivitiesPreviewRow,
} from '../lib/activities-preview-api';
import {useAsyncResource} from '../lib/use-async-resource';

interface ActivitiesPageProps {
    bootstrap: ReactPreviewBootstrap;
}

type CommuteFilter = 'all' | 'true' | 'false';
type ActivitySortField =
    | 'start-date'
    | 'distance'
    | 'elevation'
    | 'moving-time'
    | 'speed'
    | 'heart-rate'
    | 'calories'
    | 'power';

interface FilterState {
    search: string;
    sportTypes: string[];
    countryCode: string;
    gear: string;
    device: string;
    workoutType: string;
    commute: CommuteFilter;
    startDateFrom: string;
    startDateTo: string;
    distanceFrom: string;
    distanceTo: string;
    elevationFrom: string;
    elevationTo: string;
}

const initialFilters: FilterState = {
    search: '',
    sportTypes: [],
    countryCode: '',
    gear: '',
    device: '',
    workoutType: '',
    commute: 'all',
    startDateFrom: '',
    startDateTo: '',
    distanceFrom: '',
    distanceTo: '',
    elevationFrom: '',
    elevationTo: '',
};

const sortOptions: Array<{value: ActivitySortField; label: string}> = [
    {value: 'start-date', label: 'Date'},
    {value: 'distance', label: 'Distance'},
    {value: 'elevation', label: 'Elevation'},
    {value: 'moving-time', label: 'Time'},
    {value: 'speed', label: 'Speed'},
    {value: 'heart-rate', label: 'Heart rate'},
    {value: 'calories', label: 'Calories'},
    {value: 'power', label: 'Power'},
];

function formatNumber(value: number, maximumFractionDigits = 0): string {
    return new Intl.NumberFormat('en-US', {maximumFractionDigits}).format(value);
}

function formatHours(value: number): string {
    if (value >= 10) {
        return `${formatNumber(value, 0)} h`;
    }

    return `${formatNumber(value, 1)} h`;
}

function formatRequestedAt(value: string): string {
    return new Intl.DateTimeFormat('en', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
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

function parseScaledNumber(value: string): number | null {
    if (!value) {
        return null;
    }

    const parsedValue = Number(value);
    if (!Number.isFinite(parsedValue)) {
        return null;
    }

    return Math.round(parsedValue * 10);
}

function getNumberFilterValue(row: ActivitiesPreviewRow, key: string): number | null {
    const value = row.filterables[key];

    return typeof value === 'number' ? value : null;
}

function getStringFilterValue(row: ActivitiesPreviewRow, key: string): string | null {
    const value = row.filterables[key];

    return typeof value === 'string' ? value.toLowerCase() : null;
}

function getArrayFilterValue(row: ActivitiesPreviewRow, key: string): string[] {
    const value = row.filterables[key];

    return Array.isArray(value) ? value.map((entry) => entry.toLowerCase()) : [];
}

function compareSortValues(left: string | number | undefined, right: string | number | undefined, direction: 'asc' | 'desc'): number {
    if (typeof left === 'undefined') {
        return 1;
    }

    if (typeof right === 'undefined') {
        return -1;
    }

    if (left < right) {
        return direction === 'asc' ? -1 : 1;
    }

    if (left > right) {
        return direction === 'asc' ? 1 : -1;
    }

    return 0;
}

function FiltersSelect({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: string;
    options: ActivitiesPreviewFilterOption[];
    onChange: (value: string) => void;
}) {
    return (
        <label className="flex flex-col gap-2 text-sm font-medium text-gray-600 dark:text-gray-300">
            <span>{label}</span>
            <select
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition focus:border-orange-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
            >
                <option value="">All</option>
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </label>
    );
}

export function ActivitiesPage({bootstrap}: ActivitiesPageProps) {
    const [filters, setFilters] = useState<FilterState>(initialFilters);
    const [sortField, setSortField] = useState<ActivitySortField>('start-date');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('desc');

    const loadActivities = useCallback(
        (signal: AbortSignal): Promise<ActivitiesPreviewResponse> => fetchActivitiesPreview(bootstrap.basePath, signal),
        [bootstrap.basePath],
    );

    const {data, loading, error, reload} = useAsyncResource(loadActivities);
    const unitSystem = window.statisticsForStrava.unitSystem;

    const filteredRows = useMemo(() => {
        if (!data) {
            return [];
        }

        const searchValue = filters.search.trim().toLowerCase();
        const selectedSportTypes = new Set(filters.sportTypes.map((sportType) => sportType.toLowerCase()));
        const startDateFrom = parseDateFloor(filters.startDateFrom);
        const startDateTo = parseDateCeiling(filters.startDateTo);
        const distanceFrom = parseScaledNumber(filters.distanceFrom);
        const distanceTo = parseScaledNumber(filters.distanceTo);
        const elevationFrom = parseScaledNumber(filters.elevationFrom);
        const elevationTo = parseScaledNumber(filters.elevationTo);

        return [...data.rows]
            .filter((row) => {
                if (searchValue && !row.searchables.toLowerCase().includes(searchValue)) {
                    return false;
                }

                if (selectedSportTypes.size > 0) {
                    const sportType = getStringFilterValue(row, 'sportType');
                    if (!sportType || !selectedSportTypes.has(sportType)) {
                        return false;
                    }
                }

                if (filters.countryCode) {
                    const countries = getArrayFilterValue(row, 'countryCode');
                    if (!countries.includes(filters.countryCode.toLowerCase())) {
                        return false;
                    }
                }

                if (filters.gear) {
                    const gear = getStringFilterValue(row, 'gear');
                    if (gear !== filters.gear.toLowerCase()) {
                        return false;
                    }
                }

                if (filters.device) {
                    const device = getStringFilterValue(row, 'device');
                    if (device !== filters.device.toLowerCase()) {
                        return false;
                    }
                }

                if (filters.workoutType) {
                    const workoutType = getStringFilterValue(row, 'workoutType');
                    if (workoutType !== filters.workoutType.toLowerCase()) {
                        return false;
                    }
                }

                if (filters.commute !== 'all') {
                    const isCommute = getStringFilterValue(row, 'isCommute');
                    if (isCommute !== filters.commute) {
                        return false;
                    }
                }

                const startDate = getNumberFilterValue(row, 'start-date');
                if (null !== startDateFrom && (null === startDate || startDate < startDateFrom)) {
                    return false;
                }

                if (null !== startDateTo && (null === startDate || startDate > startDateTo)) {
                    return false;
                }

                const distance = getNumberFilterValue(row, 'distance');
                if (null !== distanceFrom && (null === distance || distance < distanceFrom)) {
                    return false;
                }

                if (null !== distanceTo && (null === distance || distance > distanceTo)) {
                    return false;
                }

                const elevation = getNumberFilterValue(row, 'elevation');
                if (null !== elevationFrom && (null === elevation || elevation < elevationFrom)) {
                    return false;
                }

                if (null !== elevationTo && (null === elevation || elevation > elevationTo)) {
                    return false;
                }

                return true;
            })
            .sort((left, right) => compareSortValues(left.sort[sortField], right.sort[sortField], sortDirection));
    }, [data, filters, sortDirection, sortField]);

    const totals = useMemo(() => {
        return filteredRows.reduce(
            (summary, row) => ({
                distance: summary.distance + (row.summables.distance ?? 0),
                elevation: summary.elevation + (row.summables.elevation ?? 0),
                movingTime: summary.movingTime + (row.summables['moving-time'] ?? 0),
                calories: summary.calories + (row.summables.calories ?? 0),
            }),
            {distance: 0, elevation: 0, movingTime: 0, calories: 0},
        );
    }, [filteredRows]);

    const tableBodyMarkup = useMemo(() => filteredRows.map((row) => row.markup).join(''), [filteredRows]);
    const activeFilterCount = useMemo(() => {
        return [
            filters.search,
            filters.countryCode,
            filters.gear,
            filters.device,
            filters.workoutType,
            filters.commute !== 'all' ? filters.commute : '',
            filters.startDateFrom,
            filters.startDateTo,
            filters.distanceFrom,
            filters.distanceTo,
            filters.elevationFrom,
            filters.elevationTo,
            ...filters.sportTypes,
        ].filter(Boolean).length;
    }, [filters]);

    function updateFilter<Key extends keyof FilterState>(key: Key, value: FilterState[Key]) {
        setFilters((current) => ({
            ...current,
            [key]: value,
        }));
    }

    function toggleSportType(value: string) {
        setFilters((current) => ({
            ...current,
            sportTypes: current.sportTypes.includes(value)
                ? current.sportTypes.filter((entry) => entry !== value)
                : [...current.sportTypes, value],
        }));
    }

    return (
        <div className="space-y-8 pb-8">
            <section className="glass-panel overflow-hidden rounded-[36px] p-6 md:p-8">
                <div className="grid gap-8 xl:grid-cols-[1.2fr_0.8fr]">
                    <div>
                        <div className="section-kicker">Activities preview</div>
                        <h1 className="mt-5 max-w-3xl text-4xl font-semibold tracking-tight text-gray-900 dark:text-white md:text-5xl">
                            A React take on the busiest read-heavy screen in the app.
                        </h1>
                        <p className="mt-5 max-w-2xl text-base leading-8 text-gray-600 dark:text-gray-300 md:text-lg">
                            This slice keeps the proven Symfony activity exports as the source of truth while moving
                            search, filtering, sorting, and route-level polish into the new preview shell.
                        </p>
                        <div className="mt-6 flex flex-wrap gap-3">
                            <a
                                href={buildAppPath(bootstrap.basePath, 'activities')}
                                className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                            >
                                Open the current activities page
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
                    <div className="rounded-[32px] border border-sky-200 bg-[linear-gradient(135deg,rgba(255,255,255,0.95),rgba(240,249,255,0.96))] p-5 shadow-[0_45px_120px_-45px_rgba(15,23,42,0.65)] dark:border-sky-900/40 dark:bg-[linear-gradient(135deg,rgba(17,24,39,0.94),rgba(8,47,73,0.92))]">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-sky-700 dark:text-sky-300">Why this route matters</div>
                        <div className="mt-4 space-y-3 text-sm leading-7 text-gray-700 dark:text-gray-200">
                            {[
                                'Search and filter behavior now lives in typed React state instead of DOM manager orchestration.',
                                'The preview still rides on the existing activity export pipeline, so no write-side logic is duplicated.',
                                'The same route-level patterns can roll into segments, photos, and other data-dense screens next.',
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
                <StatCard label="Visible activities" value={`${formatNumber(filteredRows.length)} / ${formatNumber(data?.rows.length ?? 0)}`} hint="Filtered rows update instantly in the preview shell." tone="orange" />
                <StatCard label="Distance" value={`${formatNumber(totals.distance, 1)} ${unitSystem.distanceSymbol}`} hint="Summed from the same exported rows the legacy screen uses." tone="blue" />
                <StatCard label="Elevation" value={`${formatNumber(totals.elevation, 0)} ${unitSystem.elevationSymbol}`} hint="Great quick check when filters get mountain-bike spicy." tone="emerald" />
                <StatCard label="Moving time" value={formatHours(totals.movingTime)} hint="Computed client-side from the preview rows." tone="slate" />
            </section>

            <section className="glass-panel rounded-[32px] p-6">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div className="flex-1">
                        <div className="section-kicker">Filters and sorting</div>
                        <p className="mt-4 max-w-3xl text-sm leading-7 text-gray-600 dark:text-gray-300">
                            The legacy screen is famously filter-happy. This preview keeps the same filtering model but presents it in a calmer route-level layout.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                        <label className="flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm font-medium text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                            <span>Sort by</span>
                            <select
                                value={sortField}
                                onChange={(event) => setSortField(event.target.value as ActivitySortField)}
                                className="bg-transparent outline-none"
                            >
                                {sortOptions.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <button
                            type="button"
                            onClick={() => setSortDirection((current) => (current === 'asc' ? 'desc' : 'asc'))}
                            className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                        >
                            {sortDirection === 'asc' ? 'Ascending' : 'Descending'}
                            <span aria-hidden="true">{sortDirection === 'asc' ? '↑' : '↓'}</span>
                        </button>
                        <button
                            type="button"
                            onClick={() => setFilters(initialFilters)}
                            className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                        >
                            Reset filters
                            <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500 dark:bg-gray-800 dark:text-gray-300">{activeFilterCount}</span>
                        </button>
                    </div>
                </div>

                <div className="mt-6 grid gap-4 lg:grid-cols-[1.2fr_0.8fr]">
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-600 dark:text-gray-300">
                        <span>Search activities</span>
                        <input
                            type="search"
                            value={filters.search}
                            onChange={(event) => updateFilter('search', event.target.value)}
                            placeholder="Search by activity name"
                            className="rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition focus:border-orange-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                        />
                    </label>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FiltersSelect label="Country" value={filters.countryCode} options={data?.filters.countries ?? []} onChange={(value) => updateFilter('countryCode', value)} />
                        <FiltersSelect label="Workout type" value={filters.workoutType} options={data?.filters.workoutTypes ?? []} onChange={(value) => updateFilter('workoutType', value)} />
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

                <div className="mt-6 grid gap-4 lg:grid-cols-3 xl:grid-cols-6">
                    <FiltersSelect label="Gear" value={filters.gear} options={data?.filters.gears ?? []} onChange={(value) => updateFilter('gear', value)} />
                    <FiltersSelect label="Device" value={filters.device} options={data?.filters.devices ?? []} onChange={(value) => updateFilter('device', value)} />
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-600 dark:text-gray-300">
                        <span>Date from</span>
                        <input
                            type="date"
                            value={filters.startDateFrom}
                            onChange={(event) => updateFilter('startDateFrom', event.target.value)}
                            className="rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition focus:border-orange-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                        />
                    </label>
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-600 dark:text-gray-300">
                        <span>Date to</span>
                        <input
                            type="date"
                            value={filters.startDateTo}
                            onChange={(event) => updateFilter('startDateTo', event.target.value)}
                            className="rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition focus:border-orange-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                        />
                    </label>
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-600 dark:text-gray-300">
                        <span>Distance min</span>
                        <input
                            type="number"
                            min="0"
                            step="0.1"
                            value={filters.distanceFrom}
                            onChange={(event) => updateFilter('distanceFrom', event.target.value)}
                            placeholder={unitSystem.distanceSymbol}
                            className="rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition focus:border-orange-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                        />
                    </label>
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-600 dark:text-gray-300">
                        <span>Distance max</span>
                        <input
                            type="number"
                            min="0"
                            step="0.1"
                            value={filters.distanceTo}
                            onChange={(event) => updateFilter('distanceTo', event.target.value)}
                            placeholder={unitSystem.distanceSymbol}
                            className="rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition focus:border-orange-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                        />
                    </label>
                </div>

                <div className="mt-4 grid gap-4 lg:grid-cols-[1fr_1fr_auto] xl:grid-cols-[1fr_1fr_auto]">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <label className="flex flex-col gap-2 text-sm font-medium text-gray-600 dark:text-gray-300">
                            <span>Elevation min</span>
                            <input
                                type="number"
                                min="0"
                                step="0.1"
                                value={filters.elevationFrom}
                                onChange={(event) => updateFilter('elevationFrom', event.target.value)}
                                placeholder={unitSystem.elevationSymbol}
                                className="rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition focus:border-orange-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                            />
                        </label>
                        <label className="flex flex-col gap-2 text-sm font-medium text-gray-600 dark:text-gray-300">
                            <span>Elevation max</span>
                            <input
                                type="number"
                                min="0"
                                step="0.1"
                                value={filters.elevationTo}
                                onChange={(event) => updateFilter('elevationTo', event.target.value)}
                                placeholder={unitSystem.elevationSymbol}
                                className="rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition focus:border-orange-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                            />
                        </label>
                    </div>
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
                                    onClick={() => updateFilter('commute', option.value as CommuteFilter)}
                                    className={`rounded-[18px] px-4 py-2 text-sm font-medium transition ${filters.commute === option.value
                                        ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-white'
                                        : 'text-gray-500 dark:text-gray-400'}`}
                                >
                                    {option.label}
                                </button>
                            ))}
                        </div>
                    </div>
                    <div className="flex items-end text-sm text-gray-500 dark:text-gray-400">
                        {data ? `Preview data refreshed ${formatRequestedAt(data.requestedAt)}.` : 'Waiting for preview data.'}
                    </div>
                </div>
            </section>

            <section className="glass-panel rounded-[32px] p-6">
                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <div className="section-kicker">Live table</div>
                        <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">Previewed activity rows</h2>
                    </div>
                    <div className="flex items-center gap-3 text-sm text-gray-500 dark:text-gray-400">
                        <span>{formatNumber(filteredRows.length)} visible rows</span>
                        <Link to="/roadmap" className="font-semibold text-strava-orange">
                            See migration roadmap →
                        </Link>
                    </div>
                </div>

                {loading && !data ? (
                    <div className="mt-6 rounded-[28px] border border-gray-200 bg-white/85 p-6 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900/60 dark:text-gray-300">
                        Loading activities preview… one hydration sip at a time.
                    </div>
                ) : null}

                {error ? (
                    <div className="mt-6 rounded-[28px] border border-rose-200 bg-rose-50/90 p-6 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                        {error}
                    </div>
                ) : null}

                {!loading && !error && filteredRows.length === 0 ? (
                    <div className="mt-6 rounded-[28px] border border-gray-200 bg-white/85 p-6 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900/60 dark:text-gray-300">
                        No activities match the current filters. Try loosening the net a little.
                    </div>
                ) : null}

                {filteredRows.length > 0 ? (
                    <div className="mt-6 overflow-hidden rounded-[28px] border border-gray-200 bg-white/92 dark:border-gray-800 dark:bg-gray-950/40">
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[1750px] text-sm text-center text-gray-600 dark:text-gray-200">
                                <thead className="sticky top-0 z-10 bg-gray-50 text-[11px] uppercase tracking-[0.2em] text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                                    <tr>
                                        <th className="px-2 py-3">Date</th>
                                        <th className="bg-gray-50 px-2 py-3 text-left dark:bg-gray-900">Activity</th>
                                        <th className="px-6 py-3">Distance</th>
                                        <th className="px-6 py-3">Elevation</th>
                                        <th className="px-6 py-3">Time</th>
                                        <th className="px-6 py-3">Speed</th>
                                        <th className="px-6 py-3">Heart rate</th>
                                        <th className="px-6 py-3">Calories</th>
                                        <th className="px-6 py-3">Power</th>
                                        <th className="px-4 py-3" colSpan={2}>Best 5s</th>
                                        <th className="px-4 py-3" colSpan={2}>Best 10s</th>
                                        <th className="px-4 py-3" colSpan={2}>Best 30s</th>
                                        <th className="px-4 py-3" colSpan={2}>Best 1m</th>
                                        <th className="px-4 py-3" colSpan={2}>Best 5m</th>
                                        <th className="px-4 py-3" colSpan={2}>Best 8m</th>
                                        <th className="px-4 py-3" colSpan={2}>Best 20m</th>
                                        <th className="px-4 py-3" colSpan={2}>Best 1h</th>
                                    </tr>
                                </thead>
                                <tbody dangerouslySetInnerHTML={{__html: tableBodyMarkup}} />
                            </table>
                        </div>
                    </div>
                ) : null}
            </section>
        </div>
    );
}