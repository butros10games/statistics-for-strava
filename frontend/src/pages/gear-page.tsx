import {useMemo} from 'react';
import {Link} from 'react-router-dom';
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
        <section className="glass-panel rounded-[32px] p-6">
            <div className="section-kicker">{title}</div>
            <div className="mt-5 overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-800">
                    <thead>
                        <tr className="text-xs uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">
                            <th className="px-4 py-3 font-semibold">Gear</th>
                            <th className="px-4 py-3 font-semibold">Workouts</th>
                            <th className="px-4 py-3 font-semibold">Distance</th>
                            <th className="px-4 py-3 font-semibold">Avg distance</th>
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
                                        <div className="flex h-10 w-10 items-center justify-center overflow-hidden rounded-2xl bg-gray-100 text-xs font-semibold text-gray-500 dark:bg-gray-900 dark:text-gray-300">
                                            {row.imageSrc ? <img src={row.imageSrc} alt="" className="h-full w-full object-cover" /> : row.name.slice(0, 2).toUpperCase()}
                                        </div>
                                        <div>
                                            <div className="font-semibold text-gray-900 dark:text-white">{row.name}</div>
                                            {row.isRetired ? <div className="text-xs text-gray-500 dark:text-gray-400">Retired</div> : null}
                                        </div>
                                    </div>
                                </td>
                                <td className="px-4 py-4">{formatNumber(row.numberOfActivities)}</td>
                                <td className="px-4 py-4">{row.distance.value} {row.distance.symbol}</td>
                                <td className="px-4 py-4">{row.averageDistance.value} {row.averageDistance.symbol}</td>
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
        <div className="space-y-8 pb-8">
            <section className="glass-panel rounded-[36px] p-6 md:p-8">
                <div className="grid gap-8 xl:grid-cols-[1.1fr_0.9fr]">
                    <div>
                        <div className="section-kicker">Gear preview</div>
                        <h1 className="mt-5 max-w-3xl text-4xl font-semibold tracking-tight text-gray-900 dark:text-white md:text-5xl">
                            A calmer React home for the kit that has quietly carried a lot of miles.
                        </h1>
                        <p className="mt-5 max-w-2xl text-base leading-8 text-gray-600 dark:text-gray-300 md:text-lg">
                            This slice keeps the route read-only, preserves the chart insights, and turns the legacy dense table into a preview that is easier to scan without losing the nerdy bits.
                        </p>
                        <div className="mt-6 flex flex-wrap gap-3">
                            <a
                                href={buildAppPath(bootstrap.basePath, 'gear')}
                                className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                            >
                                Open the current gear page
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
                    <div className="rounded-[32px] border border-sky-200 bg-[linear-gradient(135deg,rgba(255,255,255,0.95),rgba(240,249,255,0.96))] p-5 shadow-[0_45px_120px_-45px_rgba(15,23,42,0.65)] dark:border-sky-900/40 dark:bg-[linear-gradient(135deg,rgba(17,24,39,0.94),rgba(8,47,73,0.88))]">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-sky-700 dark:text-sky-300">Migration payoff</div>
                        <div className="mt-4 space-y-3 text-sm leading-7 text-gray-700 dark:text-gray-200">
                            {[
                                'The new chart wrapper now supports another analytics route without extra JS globals.',
                                'The backend remains the source of truth for gear math, costs, and chart datasets.',
                                'The next equipment-adjacent slices can reuse this API + table pattern instead of starting from scratch.',
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
                <StatCard label="Tracked gear" value={formatNumber(totalGear)} hint="Active and retired equipment currently included in this preview." tone="orange" />
                <StatCard label="Active gear" value={formatNumber(data?.summary.activeGearCount ?? 0)} hint="Gear still considered active in the legacy route." tone="emerald" />
                <StatCard label="Total workouts" value={formatNumber(data?.summary.totalActivities ?? 0)} hint="Workout count across active gear shown in this slice." tone="blue" />
                <StatCard label="Distance covered" value={data ? `${data.summary.totalDistance} ${data.unitSystem.distanceSymbol}` : '—'} hint="Aggregate distance across active gear." tone="slate" />
            </section>

            {data ? (
                <section className="grid gap-4 lg:grid-cols-3">
                    <div className="rounded-[28px] border border-gray-200 bg-white/90 p-5 text-sm leading-7 text-gray-700 shadow-sm dark:border-gray-800 dark:bg-gray-950/40 dark:text-gray-200">
                        <div className="section-kicker">Configuration</div>
                        <div className="mt-4 font-semibold text-gray-900 dark:text-white">
                            {data.customGearEnabled ? 'Custom gear is enabled.' : 'Custom gear is disabled.'}
                        </div>
                        <div className="mt-2 text-gray-500 dark:text-gray-400">
                            {data.customGearEnabled ? 'Preview data includes the extra custom gear metadata configured in the app.' : 'The preview mirrors the legacy warning and shows stats without requiring custom gear config.'}
                        </div>
                    </div>
                    <div className="rounded-[28px] border border-gray-200 bg-white/90 p-5 text-sm leading-7 text-gray-700 shadow-sm dark:border-gray-800 dark:bg-gray-950/40 dark:text-gray-200">
                        <div className="section-kicker">Maintenance</div>
                        <div className="mt-4 font-semibold text-gray-900 dark:text-white">
                            {data.maintenanceTaskIsDue ? 'At least one maintenance task is due.' : 'No due maintenance tasks were detected.'}
                        </div>
                        <div className="mt-2 text-gray-500 dark:text-gray-400">
                            This keeps the route aware of the same gear maintenance signals the legacy sub-menu uses.
                        </div>
                    </div>
                    <div className="rounded-[28px] border border-gray-200 bg-white/90 p-5 text-sm leading-7 text-gray-700 shadow-sm dark:border-gray-800 dark:bg-gray-950/40 dark:text-gray-200">
                        <div className="section-kicker">Freshness</div>
                        <div className="mt-4 font-semibold text-gray-900 dark:text-white">{formatRequestedAt(data.requestedAt)}</div>
                        <div className="mt-2 text-gray-500 dark:text-gray-400">
                            Generated from the current Symfony data on demand for the preview shell.
                        </div>
                    </div>
                </section>
            ) : null}

            {loading && !data ? (
                <section className="glass-panel rounded-[32px] p-6 text-sm text-gray-600 dark:text-gray-300">
                    Loading gear preview… polishing the bike, charging the watch, the usual.
                </section>
            ) : null}

            {error ? (
                <section className="rounded-[32px] border border-rose-200 bg-rose-50/90 p-6 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
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

            <section className="glass-panel rounded-[32px] p-6">
                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <div className="section-kicker">Where this leads</div>
                        <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">Another chart-heavy route, now with dense table data and no Twig dependency</h2>
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