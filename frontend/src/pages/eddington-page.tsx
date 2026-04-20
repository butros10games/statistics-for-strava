import {useEffect, useMemo, useState} from 'react';
import {Link} from 'react-router-dom';
import {EChartPanel} from '../components/echart-panel';
import {StatCard} from '../components/stat-card';
import {type ReactPreviewBootstrap, buildAppPath} from '../lib/bootstrap';
import {
    fetchEddingtonPreview,
    type EddingtonPreviewItem,
    type EddingtonPreviewResponse,
    type EddingtonPreviewUnitSystem,
    type EddingtonUnitSystemValue,
} from '../lib/eddington-preview-api';
import {useAsyncResource} from '../lib/use-async-resource';

interface EddingtonPageProps {
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

export function EddingtonPage({bootstrap}: EddingtonPageProps) {
    const loadEddington = (signal: AbortSignal): Promise<EddingtonPreviewResponse> => fetchEddingtonPreview(bootstrap.basePath, signal);
    const {data, loading, error, reload} = useAsyncResource(loadEddington);
    const [activeUnitSystem, setActiveUnitSystem] = useState<EddingtonUnitSystemValue>('metric');
    const [selectedEddingtonByUnit, setSelectedEddingtonByUnit] = useState<Partial<Record<EddingtonUnitSystemValue, string>>>({});

    useEffect(() => {
        if (!data) {
            return;
        }

        setActiveUnitSystem(data.activeUnitSystem);
        setSelectedEddingtonByUnit((current) => {
            const next = {...current};

            for (const unitSystem of data.unitSystems) {
                if (!next[unitSystem.value] && unitSystem.eddingtons[0]) {
                    next[unitSystem.value] = unitSystem.eddingtons[0].id;
                }
            }

            return next;
        });
    }, [data]);

    const currentUnitSystem = useMemo<EddingtonPreviewUnitSystem | null>(() => {
        if (!data) {
            return null;
        }

        return data.unitSystems.find((unitSystem) => unitSystem.value === activeUnitSystem) ?? data.unitSystems[0] ?? null;
    }, [activeUnitSystem, data]);

    const activeEddington = useMemo<EddingtonPreviewItem | null>(() => {
        if (!currentUnitSystem) {
            return null;
        }

        const selectedId = selectedEddingtonByUnit[currentUnitSystem.value];

        return currentUnitSystem.eddingtons.find((eddington) => eddington.id === selectedId) ?? currentUnitSystem.eddingtons[0] ?? null;
    }, [currentUnitSystem, selectedEddingtonByUnit]);

    const unitSummary = useMemo(() => {
        if (!currentUnitSystem) {
            return {
                totalNumbers: 0,
                highestNumber: 0,
                nextDays: null as number | null,
                currentDistanceSymbol: '',
            };
        }

        return {
            totalNumbers: currentUnitSystem.eddingtons.length,
            highestNumber: Math.max(0, ...currentUnitSystem.eddingtons.map((eddington) => eddington.number)),
            nextDays: currentUnitSystem.eddingtons.reduce<number | null>((lowest, eddington) => {
                if (null === eddington.daysToNextNumber) {
                    return lowest;
                }

                if (null === lowest || eddington.daysToNextNumber < lowest) {
                    return eddington.daysToNextNumber;
                }

                return lowest;
            }, null),
            currentDistanceSymbol: currentUnitSystem.distanceSymbol,
        };
    }, [currentUnitSystem]);

    return (
        <div className="space-y-8 pb-8">
            <section className="glass-panel overflow-hidden rounded-[36px] p-6 md:p-8">
                <div className="grid gap-8 xl:grid-cols-[1.2fr_0.8fr]">
                    <div>
                        <div className="section-kicker">Eddington preview</div>
                        <h1 className="mt-5 max-w-3xl text-4xl font-semibold tracking-tight text-gray-900 dark:text-white md:text-5xl">
                            A React preview for one of the app’s nerdiest badges of honour.
                        </h1>
                        <p className="mt-5 max-w-2xl text-base leading-8 text-gray-600 dark:text-gray-300 md:text-lg">
                            The Eddington number tracks the maximum number <span className="font-semibold">E</span> such that you have covered at least <span className="font-semibold">E</span> {currentUnitSystem?.distanceSymbol ?? window.statisticsForStrava.unitSystem.distanceSymbol} on at least <span className="font-semibold">E</span> days.
                        </p>
                        <p className="mt-4 max-w-2xl text-sm leading-7 text-gray-500 dark:text-gray-400">
                            It gets harder in a delightfully rude way: moving from 70 to 75 rarely means five more sessions—it means five more sessions that are all at least 75 long.
                        </p>
                        <div className="mt-6 flex flex-wrap gap-3">
                            <a
                                href={buildAppPath(bootstrap.basePath, 'eddington')}
                                className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                            >
                                Open the current Eddington page
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
                    <div className="rounded-[32px] border border-amber-200 bg-[linear-gradient(135deg,rgba(255,255,255,0.95),rgba(255,251,235,0.96))] p-5 shadow-[0_45px_120px_-45px_rgba(15,23,42,0.65)] dark:border-amber-900/40 dark:bg-[linear-gradient(135deg,rgba(17,24,39,0.94),rgba(69,39,0,0.78))]">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-amber-700 dark:text-amber-300">What this proves</div>
                        <div className="mt-4 space-y-3 text-sm leading-7 text-gray-700 dark:text-gray-200">
                            {[
                                'The preview shell can render chart-heavy read-side pages, not just forms and tables.',
                                'Symfony still owns the domain math, while React owns route composition, tabs, and empty/loading states.',
                                'This gives us a reusable chart wrapper for the routes that follow—hello, more graphs.',
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
                <StatCard label="Tracked variants" value={formatNumber(unitSummary.totalNumbers)} hint="Distinct Eddington tracks available in the selected unit system." tone="orange" />
                <StatCard label="Highest E-number" value={formatNumber(unitSummary.highestNumber)} hint="Best current Eddington score in the active tab." tone="emerald" />
                <StatCard label="Fastest next unlock" value={unitSummary.nextDays === null ? '—' : `${formatNumber(unitSummary.nextDays)} days`} hint="Fewest days needed to push any visible track to its next number." tone="blue" />
                <StatCard label="Selected distance cap" value={activeEddington ? `${formatNumber(activeEddington.longestDistanceInADay)} ${unitSummary.currentDistanceSymbol}` : '—'} hint="Longest single-day distance contributing to the selected Eddington." tone="slate" />
            </section>

            <section className="glass-panel rounded-[32px] p-6">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                    <div>
                        <div className="section-kicker">Unit systems</div>
                        <p className="mt-4 max-w-2xl text-sm leading-7 text-gray-600 dark:text-gray-300">
                            The legacy page renders metric and imperial side-by-side. The preview keeps both, but in a calmer route-level layout with explicit state.
                        </p>
                    </div>
                    <div className="text-sm text-gray-500 dark:text-gray-400">
                        {data ? `Preview data refreshed ${formatRequestedAt(data.requestedAt)}.` : 'Waiting for preview data.'}
                    </div>
                </div>

                <div className="mt-6 inline-flex rounded-2xl border border-gray-200 bg-gray-100 p-1 dark:border-gray-700 dark:bg-gray-900">
                    {(data?.unitSystems ?? []).map((unitSystem) => (
                        <button
                            key={unitSystem.value}
                            type="button"
                            onClick={() => setActiveUnitSystem(unitSystem.value)}
                            className={`rounded-[18px] px-4 py-2 text-sm font-medium transition ${activeUnitSystem === unitSystem.value
                                ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-white'
                                : 'text-gray-500 dark:text-gray-400'}`}
                        >
                            {unitSystem.label}
                        </button>
                    ))}
                </div>

                {currentUnitSystem && currentUnitSystem.eddingtons.length > 1 ? (
                    <div className="mt-6 flex flex-wrap gap-2">
                        {currentUnitSystem.eddingtons.map((eddington) => {
                            const isActive = activeEddington?.id === eddington.id;

                            return (
                                <button
                                    key={eddington.id}
                                    type="button"
                                    onClick={() => setSelectedEddingtonByUnit((current) => ({
                                        ...current,
                                        [currentUnitSystem.value]: eddington.id,
                                    }))}
                                    className={`rounded-full border px-4 py-2 text-sm font-medium transition ${isActive
                                        ? 'border-strava-orange bg-orange-50 text-orange-700 dark:border-orange-400 dark:bg-orange-950/40 dark:text-orange-200'
                                        : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:text-white'}`}
                                >
                                    {eddington.label} ({eddington.number})
                                </button>
                            );
                        })}
                    </div>
                ) : null}
            </section>

            {loading && !data ? (
                <section className="glass-panel rounded-[32px] p-6 text-sm text-gray-600 dark:text-gray-300">
                    Loading Eddington preview… counting long days with suspicious enthusiasm.
                </section>
            ) : null}

            {error ? (
                <section className="rounded-[32px] border border-rose-200 bg-rose-50/90 p-6 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                    {error}
                </section>
            ) : null}

            {!loading && !error && currentUnitSystem && currentUnitSystem.eddingtons.length === 0 ? (
                <section className="glass-panel rounded-[32px] p-6 text-sm text-gray-600 dark:text-gray-300">
                    No Eddington tracks are available for this unit system yet.
                </section>
            ) : null}

            {activeEddington ? (
                <>
                    <section className="glass-panel rounded-[32px] p-6">
                        <div className="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                            <div>
                                <div className="section-kicker">Selected track</div>
                                <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">
                                    {activeEddington.label}
                                </h2>
                                <p className="mt-3 text-sm leading-7 text-gray-600 dark:text-gray-300">
                                    Current Eddington: <span className="font-semibold text-gray-900 dark:text-white">{formatNumber(activeEddington.number)}</span>. Next target: <span className="font-semibold text-gray-900 dark:text-white">{formatNumber(activeEddington.nextNumber)}</span> {unitSummary.currentDistanceSymbol} on <span className="font-semibold text-gray-900 dark:text-white">{formatNumber(activeEddington.nextNumber)}</span> days.
                                </p>
                            </div>
                            <div className="grid gap-3 sm:grid-cols-3">
                                <div className="rounded-2xl border border-gray-200 bg-white/85 p-4 text-sm dark:border-gray-800 dark:bg-gray-900/60">
                                    <div className="text-gray-500 dark:text-gray-400">Days to next</div>
                                    <div className="mt-2 text-2xl font-semibold text-gray-900 dark:text-white">
                                        {activeEddington.daysToNextNumber === null ? '—' : formatNumber(activeEddington.daysToNextNumber)}
                                    </div>
                                </div>
                                <div className="rounded-2xl border border-gray-200 bg-white/85 p-4 text-sm dark:border-gray-800 dark:bg-gray-900/60">
                                    <div className="text-gray-500 dark:text-gray-400">History points</div>
                                    <div className="mt-2 text-2xl font-semibold text-gray-900 dark:text-white">{formatNumber(activeEddington.historyLength)}</div>
                                </div>
                                <div className="rounded-2xl border border-gray-200 bg-white/85 p-4 text-sm dark:border-gray-800 dark:bg-gray-900/60">
                                    <div className="text-gray-500 dark:text-gray-400">Longest day</div>
                                    <div className="mt-2 text-2xl font-semibold text-gray-900 dark:text-white">
                                        {formatNumber(activeEddington.longestDistanceInADay)} {unitSummary.currentDistanceSymbol}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section className="grid gap-6 xl:grid-cols-2">
                        <EChartPanel title="Eddington ladder" options={activeEddington.chartOptions} heightClassName="h-80" />
                        <EChartPanel title="History" options={activeEddington.historyChartOptions} heightClassName="h-80" />
                    </section>
                </>
            ) : null}

            <section className="glass-panel rounded-[32px] p-6">
                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <div className="section-kicker">Where this leads</div>
                        <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">Another chart-heavy route, now inside the preview shell</h2>
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