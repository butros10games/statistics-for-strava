import {useMemo} from 'react';
import {EChartPanel} from '../components/echart-panel';
import {StatCard} from '../components/stat-card';
import {type ReactPreviewBootstrap, buildAppPath} from '../lib/bootstrap';
import {type GearPreviewMoney, type GearPreviewResponse, type GearPreviewRow, fetchGearPreview} from '../lib/gear-preview-api';
import {useAsyncResource} from '../lib/use-async-resource';

interface GearPageProps {
    bootstrap: ReactPreviewBootstrap;
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

function formatMoney(value: GearPreviewMoney | null): string {
    if (!value) {
        return '—';
    }

    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: value.currency,
    }).format(value.amountInCents / 100);
}

function GearTable({title, rows}: {title: string; rows: GearPreviewRow[]}) {
    if (rows.length === 0) {
        return null;
    }

    return (
        <section className="ui-section">
            <div className="flex items-center justify-between gap-3">
                <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">{title}</h2>
                <div className="ui-pill">{rows.length}</div>
            </div>
            <div className="mt-4 overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-800">
                    <thead>
                        <tr className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <th className="px-4 py-3 font-semibold">Gear</th>
                            <th className="px-4 py-3 font-semibold">Workouts</th>
                            <th className="px-4 py-3 font-semibold">Distance</th>
                            <th className="px-4 py-3 font-semibold">Elevation</th>
                            <th className="px-4 py-3 font-semibold">Moving time</th>
                            <th className="px-4 py-3 font-semibold">Avg speed</th>
                            <th className="px-4 py-3 font-semibold">Calories</th>
                            <th className="px-4 py-3 font-semibold">Purchase</th>
                            <th className="px-4 py-3 font-semibold">Per hour</th>
                            <th className="px-4 py-3 font-semibold">Per workout</th>
                            <th className="px-4 py-3 font-semibold">Per distance</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-gray-900/80">
                        {rows.map((row) => (
                            <tr key={row.id} className="align-top text-gray-700 dark:text-gray-200">
                                <td className="px-4 py-4">
                                    <div className="flex items-center gap-3">
                                        <div className="flex h-10 w-10 items-center justify-center overflow-hidden rounded-lg bg-gray-100 text-xs font-semibold text-gray-500 dark:bg-gray-900 dark:text-gray-300">
                                            {row.imageSrc ? <img src={row.imageSrc} alt="" className="h-full w-full object-cover" /> : row.name.slice(0, 2).toUpperCase()}
                                        </div>
                                        <div>
                                            <div className="font-semibold text-gray-900 dark:text-white">{row.name}</div>
                                            {row.isRetired ? <div className="text-xs text-gray-500 dark:text-gray-400">Retired</div> : null}
                                        </div>
                                    </div>
                                </td>
                                <td className="px-4 py-4">{formatNumber(row.numberOfActivities)}</td>
                                <td className="px-4 py-4">
                                    <div>{row.averageDistance.value} {row.averageDistance.symbol} avg</div>
                                    <div className="text-xs text-gray-500 dark:text-gray-400">{row.distance.value} {row.distance.symbol} total</div>
                                </td>
                                <td className="px-4 py-4">{row.elevation.value} {row.elevation.symbol}</td>
                                <td className="px-4 py-4">{row.movingTime.formatted}</td>
                                <td className="px-4 py-4">{row.averageSpeed.value} {row.averageSpeed.symbol}</td>
                                <td className="px-4 py-4">{formatNumber(row.totalCalories)} kcal</td>
                                <td className="px-4 py-4">{formatMoney(row.purchasePrice)}</td>
                                <td className="px-4 py-4">{formatMoney(row.relativeCostPerHour)}</td>
                                <td className="px-4 py-4">{formatMoney(row.relativeCostPerWorkout)}</td>
                                <td className="px-4 py-4">{formatMoney(row.relativeCostPerDistanceUnit)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

export function GearPage({bootstrap}: GearPageProps) {
    const loadGear = (signal: AbortSignal): Promise<GearPreviewResponse> => fetchGearPreview(bootstrap.basePath, signal);
    const {data, loading, error, reload} = useAsyncResource(loadGear);

    const totalGear = useMemo(() => {
        if (!data) {
            return 0;
        }

        return data.summary.activeGearCount + data.summary.retiredGearCount;
    }, [data]);

    return (
        <div className="space-y-6 pb-6">
            <section className="ui-section">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div className="min-w-0">
                        <h1 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white md:text-2xl">Gear</h1>
                        <p className="mt-1 max-w-3xl text-sm leading-7 text-gray-500 dark:text-gray-400">
                            Gear stats, usage trends, and maintenance context in one compact utility screen.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <a href={buildAppPath(bootstrap.basePath, 'gear')} className="ui-button">
                            Open classic gear page
                        </a>
                        <button type="button" onClick={reload} className="ui-button">
                            Refresh data
                        </button>
                    </div>
                </div>

                <div className="mt-4 flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 p-1 dark:border-gray-800 dark:bg-gray-900">
                    <a href={buildAppPath(bootstrap.basePath, 'gear')} className="rounded-md bg-white px-3 py-2 text-sm font-medium text-gray-900 shadow-sm dark:bg-gray-800 dark:text-white">
                        Gear
                    </a>
                    <a href={buildAppPath(bootstrap.basePath, 'gear/maintenance')} className="inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-gray-500 transition hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">
                        {data?.maintenanceTaskIsDue ? <span className="h-2 w-2 rounded-full bg-red-500" aria-hidden="true" /> : null}
                        Maintenance
                    </a>
                    <a href={buildAppPath(bootstrap.basePath, 'gear/recording-devices')} className="rounded-md px-3 py-2 text-sm font-medium text-gray-500 transition hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">
                        Recording devices
                    </a>
                </div>
            </section>

            <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Tracked gear" value={formatNumber(totalGear)} hint="Active and retired equipment in this account." tone="orange" />
                <StatCard label="Active gear" value={formatNumber(data?.summary.activeGearCount ?? 0)} hint="Gear still considered active." tone="emerald" />
                <StatCard label="Total workouts" value={formatNumber(data?.summary.totalActivities ?? 0)} hint="Workout count across active gear." tone="blue" />
                <StatCard label="Distance covered" value={data ? `${data.summary.totalDistance} ${data.unitSystem.distanceSymbol}` : '—'} hint="Aggregate distance across active gear." tone="slate" />
            </section>

            {data ? (
                <section className="grid gap-4 lg:grid-cols-3">
                    <div className="ui-section text-sm leading-7 text-gray-700 dark:text-gray-200">
                        <div className="text-sm font-semibold text-gray-700 dark:text-gray-200">Configuration</div>
                        <div className="mt-2 font-semibold text-gray-900 dark:text-white">
                            {data.customGearEnabled ? 'Custom gear is enabled.' : 'Custom gear is disabled.'}
                        </div>
                        <div className="mt-1 text-gray-500 dark:text-gray-400">
                            {data.customGearEnabled ? 'Includes the extra custom gear metadata configured in the app.' : 'Matches the classic warning and still shows stats without requiring custom gear config.'}
                        </div>
                    </div>
                    <div className="ui-section text-sm leading-7 text-gray-700 dark:text-gray-200">
                        <div className="text-sm font-semibold text-gray-700 dark:text-gray-200">Maintenance</div>
                        <div className="mt-2 font-semibold text-gray-900 dark:text-white">
                            {data.maintenanceTaskIsDue ? 'At least one maintenance task is due.' : 'No due maintenance tasks were detected.'}
                        </div>
                        <div className="mt-1 text-gray-500 dark:text-gray-400">
                            This keeps the route aware of the same gear maintenance signals the classic sub-menu uses.
                        </div>
                    </div>
                    <div className="ui-section text-sm leading-7 text-gray-700 dark:text-gray-200">
                        <div className="text-sm font-semibold text-gray-700 dark:text-gray-200">Freshness</div>
                        <div className="mt-2 font-semibold text-gray-900 dark:text-white">{formatRequestedAt(data.requestedAt)}</div>
                        <div className="mt-1 text-gray-500 dark:text-gray-400">
                            Generated from the current Symfony data on demand.
                        </div>
                    </div>
                </section>
            ) : null}

            {loading && !data ? (
                <section className="ui-section text-sm text-gray-600 dark:text-gray-300">
                    Loading gear data.
                </section>
            ) : null}

            {error ? (
                <section className="rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                    {error}
                </section>
            ) : null}

            {data ? (
                <section className="grid gap-6 xl:grid-cols-2">
                    <EChartPanel title="Distance per month per gear" options={data.charts.distancePerMonthPerGear} heightClassName="h-96" />
                    <EChartPanel title="Distance over time per gear" options={data.charts.distanceOverTimePerGear} heightClassName="h-96" />
                </section>
            ) : null}

            {data ? <GearTable title="Active gear" rows={data.activeGear} /> : null}
            {data ? <GearTable title="Retired gear" rows={data.retiredGear} /> : null}
        </div>
    );
}