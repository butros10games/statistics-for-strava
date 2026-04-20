import {useCallback, useEffect, useMemo, useState} from 'react';
import {Link} from 'react-router-dom';
import {EChartPanel} from '../components/echart-panel';
import {StatCard} from '../components/stat-card';
import {type ReactPreviewBootstrap, buildAppPath} from '../lib/bootstrap';
import {
    type BestEffortActivityTypePreview,
    type BestEffortCell,
    type BestEffortDistanceRow,
    type BestEffortHistoryResponse,
    type BestEffortPeriodPreview,
    fetchBestEffortHistory,
    fetchBestEffortsPreview,
} from '../lib/best-efforts-preview-api';
import {useAsyncResource} from '../lib/use-async-resource';

interface BestEffortsPageProps {
    bootstrap: ReactPreviewBootstrap;
}

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat('en', {
        dateStyle: 'medium',
    }).format(new Date(value));
}

function formatRequestedAt(value: string): string {
    return new Intl.DateTimeFormat('en', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function formatNumber(value: number): string {
    return new Intl.NumberFormat('en-US').format(value);
}

function renderEffortCell(cell: BestEffortCell) {
    if (!cell.effort) {
        return <span className="text-gray-400 dark:text-gray-500">—</span>;
    }

    return (
        <div className="space-y-1">
            <div className="font-semibold text-gray-900 dark:text-white">{cell.effort.formattedTime}</div>
            <div className="text-xs text-gray-500 dark:text-gray-400">{formatDate(cell.effort.startDate)}</div>
            <div className="text-xs text-strava-orange">
                {cell.effort.activityUrl ? (
                    <a href={cell.effort.activityUrl} target="_blank" rel="noreferrer" className="hover:underline">
                        {cell.effort.activityName}
                    </a>
                ) : (
                    cell.effort.activityName
                )}
            </div>
        </div>
    );
}

function BestEffortHistoryPanel({
    history,
    loading,
    error,
}: {
    history: BestEffortHistoryResponse | null;
    loading: boolean;
    error: string | null;
}) {
    if (loading && !history) {
        return (
            <div className="glass-panel rounded-[32px] p-6 text-sm text-gray-600 dark:text-gray-300">
                Loading history panel… chasing old records without forcing you to re-run the workout. A humane compromise.
            </div>
        );
    }

    if (error) {
        return (
            <div className="rounded-[32px] border border-rose-200 bg-rose-50/90 p-6 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                {error}
            </div>
        );
    }

    if (!history) {
        return (
            <div className="glass-panel rounded-[32px] p-6 text-sm text-gray-600 dark:text-gray-300">
                Select a distance row to inspect the all-time top ten history across the sport types that support it.
            </div>
        );
    }

    return (
        <div className="rounded-[32px] border border-gray-200 bg-white/92 p-5 shadow-sm dark:border-gray-800 dark:bg-gray-950/40">
            <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <div className="section-kicker">Distance history</div>
                    <h3 className="mt-4 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">{history.distance.label}</h3>
                    <p className="mt-2 text-sm leading-7 text-gray-600 dark:text-gray-300">
                        All-time leaderboard for {history.activityType.label.toLowerCase()} best efforts, grouped by sport type.
                    </p>
                </div>
                <div className="text-sm text-gray-500 dark:text-gray-400">
                    {history.sportTypes.length} sport types · refreshed {formatRequestedAt(history.requestedAt)}
                </div>
            </div>

            <div className="mt-5 overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-800">
                    <thead>
                        <tr className="text-xs uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">
                            <th className="px-3 py-3 font-semibold">#</th>
                            {history.sportTypes.map((sportType) => (
                                <th key={sportType.value} className="px-3 py-3 font-semibold">{sportType.label}</th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-gray-900/80">
                        {history.rankings.map((ranking) => (
                            <tr key={ranking.rank}>
                                <td className="px-3 py-3 font-semibold text-gray-900 dark:text-white">{ranking.rank}</td>
                                {ranking.efforts.map((cell) => (
                                    <td key={`${ranking.rank}-${cell.sportType.value}`} className="px-3 py-3 align-top text-gray-700 dark:text-gray-200">
                                        {renderEffortCell(cell)}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

export function BestEffortsPage({bootstrap}: BestEffortsPageProps) {
    const loadBestEfforts = useCallback(
        (signal: AbortSignal) => fetchBestEffortsPreview(bootstrap.basePath, signal),
        [bootstrap.basePath],
    );
    const {data, loading, error, reload} = useAsyncResource(loadBestEfforts);

    const [activeActivityType, setActiveActivityType] = useState<string>('');
    const [activePeriodByActivityType, setActivePeriodByActivityType] = useState<Record<string, string>>({});
    const [selectedDistanceByActivityType, setSelectedDistanceByActivityType] = useState<Record<string, string>>({});

    useEffect(() => {
        if (!data || 0 === data.activityTypes.length) {
            return;
        }

        setActiveActivityType((current) => current || data.activityTypes[0].value);
        setActivePeriodByActivityType((current) => {
            const next = {...current};

            for (const activityType of data.activityTypes) {
                if (!next[activityType.value] && activityType.periods[0]) {
                    next[activityType.value] = activityType.periods[0].value;
                }
            }

            return next;
        });
        setSelectedDistanceByActivityType((current) => {
            const next = {...current};

            for (const activityType of data.activityTypes) {
                const firstRow = activityType.periods[0]?.rows[0];

                if (!next[activityType.value] && firstRow) {
                    next[activityType.value] = firstRow.distance.key;
                }
            }

            return next;
        });
    }, [data]);

    const currentActivityType = useMemo<BestEffortActivityTypePreview | null>(() => {
        if (!data) {
            return null;
        }

        return data.activityTypes.find((activityType) => activityType.value === activeActivityType) ?? data.activityTypes[0] ?? null;
    }, [activeActivityType, data]);

    const currentPeriod = useMemo<BestEffortPeriodPreview | null>(() => {
        if (!currentActivityType) {
            return null;
        }

        const activePeriod = activePeriodByActivityType[currentActivityType.value];

        return currentActivityType.periods.find((period) => period.value === activePeriod) ?? currentActivityType.periods[0] ?? null;
    }, [activePeriodByActivityType, currentActivityType]);

    const selectedDistance = useMemo<BestEffortDistanceRow | null>(() => {
        if (!currentActivityType || !currentPeriod) {
            return null;
        }

        const selectedKey = selectedDistanceByActivityType[currentActivityType.value];

        return currentPeriod.rows.find((row) => row.distance.key === selectedKey) ?? currentPeriod.rows[0] ?? null;
    }, [currentActivityType, currentPeriod, selectedDistanceByActivityType]);

    useEffect(() => {
        if (!currentActivityType || !currentPeriod) {
            return;
        }

        const selectedKey = selectedDistanceByActivityType[currentActivityType.value];

        if (!selectedKey || !currentPeriod.rows.some((row) => row.distance.key === selectedKey)) {
            const firstRow = currentPeriod.rows[0];

            if (!firstRow) {
                return;
            }

            setSelectedDistanceByActivityType((current) => ({
                ...current,
                [currentActivityType.value]: firstRow.distance.key,
            }));
        }
    }, [currentActivityType, currentPeriod, selectedDistanceByActivityType]);

    const loadHistory = useCallback(
        (signal: AbortSignal) => {
            if (!currentActivityType || !selectedDistance) {
                return Promise.resolve(null);
            }

            return fetchBestEffortHistory(
                bootstrap.basePath,
                currentActivityType.value,
                selectedDistance.distance.value,
                selectedDistance.distance.symbol,
                signal,
            );
        },
        [bootstrap.basePath, currentActivityType, selectedDistance],
    );
    const {
        data: history,
        loading: historyLoading,
        error: historyError,
        reload: reloadHistory,
    } = useAsyncResource<BestEffortHistoryResponse | null>(loadHistory);

    const visibleHistory = history && selectedDistance && history.distance.key === selectedDistance.distance.key ? history : null;

    const summary = useMemo(() => {
        const activityTypeCount = data?.activityTypes.length ?? 0;
        const periodCount = currentActivityType?.periods.length ?? 0;
        const sportTypeCount = currentPeriod?.sportTypes.length ?? 0;
        const distanceCount = currentPeriod?.rows.length ?? 0;

        return {
            activityTypeCount,
            periodCount,
            sportTypeCount,
            distanceCount,
        };
    }, [currentActivityType, currentPeriod, data?.activityTypes.length]);

    return (
        <div className="space-y-8 pb-8">
            <section className="glass-panel overflow-hidden rounded-[36px] p-6 md:p-8">
                <div className="grid gap-8 xl:grid-cols-[1.2fr_0.8fr]">
                    <div>
                        <div className="section-kicker">Best efforts preview</div>
                        <h1 className="mt-5 max-w-3xl text-4xl font-semibold tracking-tight text-gray-900 dark:text-white md:text-5xl">
                            A React take on the records matrix, with faster pivots between activity types, periods, and distance history.
                        </h1>
                        <p className="mt-5 max-w-2xl text-base leading-8 text-gray-600 dark:text-gray-300 md:text-lg">
                            The legacy page packs charts, tabs, and modal drill-downs into one dense screen. This preview keeps the same backend calculations but gives the route cleaner state, more readable table scanning, and an inline history panel.
                        </p>
                        <div className="mt-6 flex flex-wrap gap-3">
                            <a
                                href={buildAppPath(bootstrap.basePath, 'best-efforts')}
                                className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                            >
                                Open the current best efforts page
                                <span aria-hidden="true">↗</span>
                            </a>
                            <button
                                type="button"
                                onClick={() => {
                                    reload();
                                    reloadHistory();
                                }}
                                className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                            >
                                Refresh preview data
                                <span aria-hidden="true">↻</span>
                            </button>
                        </div>
                    </div>
                    <div className="rounded-[32px] border border-sky-200 bg-[linear-gradient(135deg,rgba(255,255,255,0.95),rgba(240,249,255,0.96))] p-5 shadow-[0_45px_120px_-45px_rgba(15,23,42,0.65)] dark:border-sky-900/40 dark:bg-[linear-gradient(135deg,rgba(17,24,39,0.94),rgba(8,47,73,0.88))]">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-sky-700 dark:text-sky-300">What this proves</div>
                        <div className="mt-4 space-y-3 text-sm leading-7 text-gray-700 dark:text-gray-200">
                            {[
                                'The preview shell can handle matrix-style analytics, not only single-chart dashboards.',
                                'Split APIs work nicely here: one payload for charts and summary tables, another for the all-time history drill-down.',
                                'This pattern should transfer cleanly to more leaderboard-style routes such as peak powers or photos metadata views.',
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
                <StatCard label="Activity families" value={formatNumber(summary.activityTypeCount)} hint="Activity types currently exposing best-effort calculations." tone="orange" />
                <StatCard label="Periods" value={formatNumber(summary.periodCount)} hint="Available time windows for the selected activity family." tone="emerald" />
                <StatCard label="Sport types" value={formatNumber(summary.sportTypeCount)} hint="Columns shown in the active best-efforts matrix." tone="blue" />
                <StatCard label="Distances" value={formatNumber(summary.distanceCount)} hint="Configured best-effort distances for the active view." tone="slate" />
            </section>

            <section className="glass-panel rounded-[32px] p-6">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                    <div>
                        <div className="section-kicker">Route state</div>
                        <p className="mt-4 max-w-2xl text-sm leading-7 text-gray-600 dark:text-gray-300">
                            Each activity type keeps its own selected period and distance, so swapping between running and cycling no longer feels like juggling modal state with oven mitts on.
                        </p>
                    </div>
                    <div className="text-sm text-gray-500 dark:text-gray-400">
                        {data ? `Preview data refreshed ${formatRequestedAt(data.requestedAt)}.` : 'Waiting for preview data.'}
                    </div>
                </div>

                <div className="mt-6 flex flex-wrap gap-2">
                    {(data?.activityTypes ?? []).map((activityType) => {
                        const isActive = currentActivityType?.value === activityType.value;

                        return (
                            <button
                                key={activityType.value}
                                type="button"
                                onClick={() => setActiveActivityType(activityType.value)}
                                className={`rounded-full border px-4 py-2 text-sm font-medium transition ${isActive
                                    ? 'border-strava-orange bg-orange-50 text-orange-700 dark:border-orange-400 dark:bg-orange-950/40 dark:text-orange-200'
                                    : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:text-white'}`}
                            >
                                {activityType.label}
                            </button>
                        );
                    })}
                </div>

                {currentActivityType && currentActivityType.periods.length > 1 ? (
                    <div className="mt-6 inline-flex rounded-2xl border border-gray-200 bg-gray-100 p-1 dark:border-gray-700 dark:bg-gray-900">
                        {currentActivityType.periods.map((period) => {
                            const isActive = currentPeriod?.value === period.value;

                            return (
                                <button
                                    key={period.value}
                                    type="button"
                                    onClick={() => setActivePeriodByActivityType((current) => ({
                                        ...current,
                                        [currentActivityType.value]: period.value,
                                    }))}
                                    className={`rounded-[18px] px-4 py-2 text-sm font-medium transition ${isActive
                                        ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-white'
                                        : 'text-gray-500 dark:text-gray-400'}`}
                                >
                                    {period.label}
                                </button>
                            );
                        })}
                    </div>
                ) : null}
            </section>

            {loading && !data ? (
                <section className="glass-panel rounded-[32px] p-6 text-sm text-gray-600 dark:text-gray-300">
                    Loading best efforts preview… timing your old heroics with great respect and zero lactic acid.
                </section>
            ) : null}

            {error ? (
                <section className="rounded-[32px] border border-rose-200 bg-rose-50/90 p-6 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                    {error}
                </section>
            ) : null}

            {!loading && !error && !currentActivityType ? (
                <section className="glass-panel rounded-[32px] p-6 text-sm text-gray-600 dark:text-gray-300">
                    No best-efforts data is available yet.
                </section>
            ) : null}

            {currentPeriod ? (
                <>
                    <section>
                        <EChartPanel title={`${currentActivityType?.label ?? 'Selected'} best efforts`} options={currentPeriod.chartOptions} heightClassName="h-96" />
                    </section>

                    <section className="grid gap-6 xl:grid-cols-[minmax(0,1.05fr)_minmax(0,0.95fr)]">
                        <div className="rounded-[32px] border border-gray-200 bg-white/92 p-5 shadow-sm dark:border-gray-800 dark:bg-gray-950/40">
                            <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                <div>
                                    <div className="section-kicker">Matrix view</div>
                                    <h2 className="mt-4 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Distance records</h2>
                                    <p className="mt-2 text-sm leading-7 text-gray-600 dark:text-gray-300">
                                        Pick any distance to open the all-time history panel. The active row stays pinned to the selected activity type.
                                    </p>
                                </div>
                                {selectedDistance ? (
                                    <div className="rounded-2xl border border-orange-200 bg-orange-50 px-4 py-3 text-sm font-semibold text-orange-700 dark:border-orange-900/50 dark:bg-orange-950/40 dark:text-orange-200">
                                        Selected: {selectedDistance.distance.label}
                                    </div>
                                ) : null}
                            </div>

                            <div className="mt-5 overflow-x-auto">
                                <table className="min-w-[1750px] divide-y divide-gray-200 text-left text-sm dark:divide-gray-800">
                                    <thead>
                                        <tr className="text-xs uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">
                                            <th className="px-3 py-3 font-semibold">Distance</th>
                                            {currentPeriod.sportTypes.map((sportType) => (
                                                <th key={sportType.value} className="px-3 py-3 font-semibold">{sportType.label}</th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100 dark:divide-gray-900/80">
                                        {currentPeriod.rows.map((row) => {
                                            const isActive = row.distance.key === selectedDistance?.distance.key;

                                            return (
                                                <tr key={row.distance.key} className={isActive ? 'bg-orange-50/50 dark:bg-orange-950/20' : ''}>
                                                    <th className="px-3 py-3 align-top">
                                                        <button
                                                            type="button"
                                                            onClick={() => setSelectedDistanceByActivityType((current) => ({
                                                                ...current,
                                                                [currentActivityType!.value]: row.distance.key,
                                                            }))}
                                                            className={`rounded-2xl border px-4 py-3 text-left transition ${isActive
                                                                ? 'border-strava-orange bg-orange-50 text-orange-700 dark:border-orange-400 dark:bg-orange-950/40 dark:text-orange-200'
                                                                : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:border-gray-600 dark:hover:text-white'}`}
                                                        >
                                                            <div className="font-semibold">{row.distance.label}</div>
                                                            <div className="mt-1 text-xs text-gray-500 dark:text-gray-400">Open history panel</div>
                                                        </button>
                                                    </th>
                                                    {row.efforts.map((cell) => (
                                                        <td key={`${row.distance.key}-${cell.sportType.value}`} className="px-3 py-3 align-top text-gray-700 dark:text-gray-200">
                                                            {renderEffortCell(cell)}
                                                        </td>
                                                    ))}
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <BestEffortHistoryPanel history={visibleHistory} loading={historyLoading} error={historyError} />
                    </section>
                </>
            ) : null}

            <section className="glass-panel rounded-[32px] p-6">
                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <div className="section-kicker">Where this leads</div>
                        <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">Another dense analytics route, now broken into clearer UI states</h2>
                    </div>
                    <Link
                        to="/roadmap"
                        className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                    >
                        Open the migration roadmap
                        <span aria-hidden="true">→</span>
                    </Link>
                </div>
            </section>
        </div>
    );
}