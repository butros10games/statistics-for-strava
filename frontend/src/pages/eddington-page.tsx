import {useEffect, useMemo, useState} from 'react';
import {EChartPanel} from '../components/echart-panel';
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
        <div className="space-y-6 pb-6">
            <section className="ui-section">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div className="min-w-0">
                        <h1 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white md:text-2xl">Eddington</h1>
                        <p className="mt-1 max-w-3xl text-sm leading-7 text-gray-500 dark:text-gray-400">
                            The maximum number $E$ such that you have covered at least $E$ {currentUnitSystem?.distanceSymbol ?? 'km'} on at least $E$ days.
                        </p>
                        <p className="mt-2 max-w-3xl text-sm leading-7 text-gray-500 dark:text-gray-400">
                            Moving from 70 to 75 usually means more than five new long workouts, because every day has to clear the new threshold.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <a href={buildAppPath(bootstrap.basePath, 'eddington')} className="ui-button">
                            Open classic Eddington page
                        </a>
                        <button type="button" onClick={reload} className="ui-button">
                            Refresh data
                        </button>
                    </div>
                </div>
            </section>

            <section className="ui-section">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                    <div>
                        <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Unit systems</h2>
                        <p className="mt-1 max-w-2xl text-sm leading-7 text-gray-500 dark:text-gray-400">
                            Switch between metric and imperial, then choose the track you want to inspect.
                        </p>
                    </div>
                    <div className="text-sm text-gray-500 dark:text-gray-400">
                        {data ? `Refreshed ${formatRequestedAt(data.requestedAt)}.` : 'Waiting for data.'}
                    </div>
                </div>

                <div className="mt-4 inline-flex rounded-lg border border-gray-200 bg-gray-100 p-1 dark:border-gray-700 dark:bg-gray-900">
                    {(data?.unitSystems ?? []).map((unitSystem) => (
                        <button
                            key={unitSystem.value}
                            type="button"
                            onClick={() => setActiveUnitSystem(unitSystem.value)}
                            className={`rounded-md px-4 py-2 text-sm font-medium transition ${activeUnitSystem === unitSystem.value
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
                                    className={`rounded-lg border px-4 py-2 text-sm font-medium transition ${isActive
                                        ? 'border-orange-300 bg-orange-50 text-orange-700 dark:border-orange-400 dark:bg-orange-950/40 dark:text-orange-200'
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
                <section className="ui-section text-sm text-gray-600 dark:text-gray-300">
                    Loading Eddington data.
                </section>
            ) : null}

            {error ? (
                <section className="rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                    {error}
                </section>
            ) : null}

            {!loading && !error && currentUnitSystem && currentUnitSystem.eddingtons.length === 0 ? (
                <section className="ui-section text-sm text-gray-600 dark:text-gray-300">
                    No Eddington tracks are available for this unit system yet.
                </section>
            ) : null}

            {activeEddington ? (
                <>
                    <section className="ui-section">
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div>
                                <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Selected track</h2>
                                <div className="mt-1 text-base font-semibold text-gray-900 dark:text-white">{activeEddington.label}</div>
                                <p className="mt-2 text-sm leading-7 text-gray-500 dark:text-gray-400">
                                    Current Eddington: <span className="font-semibold text-gray-900 dark:text-white">{formatNumber(activeEddington.number)}</span>. Next target: <span className="font-semibold text-gray-900 dark:text-white">{formatNumber(activeEddington.nextNumber)}</span> {unitSummary.currentDistanceSymbol} on <span className="font-semibold text-gray-900 dark:text-white">{formatNumber(activeEddington.nextNumber)}</span> days.
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2 text-sm">
                                <div className="ui-pill">{unitSummary.totalNumbers} variants</div>
                                <div className="ui-pill">Highest E {formatNumber(unitSummary.highestNumber)}</div>
                                <div className="ui-pill">{activeEddington.daysToNextNumber === null ? 'No next step' : `${formatNumber(activeEddington.daysToNextNumber)} days to next`}</div>
                                <div className="ui-pill">Longest day {formatNumber(activeEddington.longestDistanceInADay)} {unitSummary.currentDistanceSymbol}</div>
                            </div>
                        </div>
                    </section>

                    <section className="grid gap-4 xl:grid-cols-2">
                        <EChartPanel title="Eddington ladder" options={activeEddington.chartOptions} heightClassName="h-80" />
                        <EChartPanel title="History" options={activeEddington.historyChartOptions} heightClassName="h-80" />
                    </section>
                </>
            ) : null}
        </div>
    );
}