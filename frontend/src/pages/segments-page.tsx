import {useCallback, useEffect, useMemo, useState} from 'react';
import {EChartPanel} from '../components/echart-panel';
import {type ReactPreviewBootstrap, buildAppPath} from '../lib/bootstrap';
import {
    type SegmentDetailResponse,
    type SegmentEffortRow,
    type SegmentPreviewRow,
    fetchSegmentDetail,
    fetchSegmentsPreview,
} from '../lib/segments-preview-api';
import {useAsyncResource} from '../lib/use-async-resource';

interface SegmentsPageProps {
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

function matchesSearch(segment: SegmentPreviewRow, search: string): boolean {
    if ('' === search) {
        return true;
    }

    const haystack = [
        segment.displayName,
        segment.sportType.label,
        segment.countryName,
    ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();

    return haystack.includes(search);
}

function matchesRange(value: number | null, minimum: string, maximum: string): boolean {
    if (null === value) {
        return '' === minimum && '' === maximum;
    }

    const min = '' === minimum ? null : Number(minimum);
    const max = '' === maximum ? null : Number(maximum);

    if (null !== min && value < min) {
        return false;
    }

    if (null !== max && value > max) {
        return false;
    }

    return true;
}

function SegmentRow({
    segment,
    isActive,
    onSelect,
}: {
    segment: SegmentPreviewRow;
    isActive: boolean;
    onSelect: (segmentId: string) => void;
}) {
    return (
        <button
            type="button"
            onClick={() => onSelect(segment.id)}
            className={`w-full rounded-lg border px-4 py-3 text-left transition ${
                isActive
                    ? 'border-orange-300 bg-orange-50 dark:border-orange-400 dark:bg-orange-950/30'
                    : 'border-gray-200 bg-white hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-950 dark:hover:bg-gray-900'
            }`}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="truncate text-sm font-semibold text-gray-900 dark:text-white">{segment.displayName}</h3>
                        {segment.isFavourite ? <span className="rounded-full border border-amber-300 bg-amber-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-800 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-200">Starred</span> : null}
                        {segment.isKom ? <span className="rounded-full border border-emerald-300 bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-950/30 dark:text-emerald-200">KOM</span> : null}
                    </div>
                    <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {segment.sportType.label}
                        {segment.countryName ? ` · ${segment.countryName}` : ''}
                        {segment.lastEffortDate ? ` · last effort ${formatDate(segment.lastEffortDate)}` : ''}
                    </p>
                </div>
                <div className="text-right text-xs text-gray-500 dark:text-gray-400">
                    <div>{segment.numberOfTimesRidden} rides</div>
                    <div className="mt-1">{segment.distance.value} {segment.distance.symbol}</div>
                </div>
            </div>

            <div className="mt-3 grid gap-2 sm:grid-cols-4">
                <div className="rounded-lg border border-gray-200 bg-gray-50 p-2.5 text-xs dark:border-gray-800 dark:bg-gray-900/60">
                    <div className="uppercase tracking-wide text-gray-500 dark:text-gray-400">Best effort</div>
                    <div className="mt-1 font-semibold text-gray-900 dark:text-white">{segment.bestEffort?.elapsedTimeFormatted ?? '—'}</div>
                    <div className="mt-0.5 text-gray-600 dark:text-gray-300">{segment.bestEffort ? `${segment.bestEffort.averageSpeed.value} ${segment.bestEffort.averageSpeed.symbol}` : 'No effort yet'}</div>
                </div>
                <div className="rounded-lg border border-gray-200 bg-gray-50 p-2.5 text-xs dark:border-gray-800 dark:bg-gray-900/60">
                    <div className="uppercase tracking-wide text-gray-500 dark:text-gray-400">Distance</div>
                    <div className="mt-1 font-semibold text-gray-900 dark:text-white">{segment.distance.value} {segment.distance.symbol}</div>
                </div>
                <div className="rounded-lg border border-gray-200 bg-gray-50 p-2.5 text-xs dark:border-gray-800 dark:bg-gray-900/60">
                    <div className="uppercase tracking-wide text-gray-500 dark:text-gray-400">Avg gradient</div>
                    <div className="mt-1 font-semibold text-gray-900 dark:text-white">{segment.averageGradient ?? '—'}%</div>
                </div>
                <div className="rounded-lg border border-gray-200 bg-gray-50 p-2.5 text-xs dark:border-gray-800 dark:bg-gray-900/60">
                    <div className="uppercase tracking-wide text-gray-500 dark:text-gray-400">Max gradient</div>
                    <div className="mt-1 font-semibold text-gray-900 dark:text-white">{segment.maxGradient}%</div>
                </div>
            </div>
        </button>
    );
}

function SegmentEffortsTable({efforts}: {efforts: SegmentEffortRow[]}) {
    if (0 === efforts.length) {
        return (
            <div className="rounded-lg border border-gray-200 bg-white p-5 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-950/40 dark:text-gray-300">
                No efforts available for this segment yet.
            </div>
        );
    }

    return (
        <div className="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-950/40">
            <h3 className="text-sm font-semibold text-gray-900 dark:text-white">Top efforts</h3>
            <div className="mt-4 overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-800">
                    <thead>
                        <tr className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <th className="px-3 py-3 font-semibold">#</th>
                            <th className="px-3 py-3 font-semibold">Date</th>
                            <th className="px-3 py-3 font-semibold">Activity</th>
                            <th className="px-3 py-3 font-semibold">Time</th>
                            <th className="px-3 py-3 font-semibold">Speed</th>
                            <th className="px-3 py-3 font-semibold">Heart rate</th>
                            <th className="px-3 py-3 font-semibold">Watts</th>
                            <th className="px-3 py-3 font-semibold">Gear</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-gray-900/80">
                        {efforts.map((effort) => (
                            <tr key={effort.id} className="text-gray-700 dark:text-gray-200">
                                <td className="px-3 py-3 font-semibold text-gray-900 dark:text-white">{effort.ranking}</td>
                                <td className="px-3 py-3">{formatDate(effort.startDate)}</td>
                                <td className="px-3 py-3">
                                    {effort.activityUrl ? (
                                        <a href={effort.activityUrl} className="font-medium text-strava-orange hover:underline" target="_blank" rel="noreferrer">
                                            {effort.activityName}
                                        </a>
                                    ) : (
                                        effort.activityName
                                    )}
                                </td>
                                <td className="px-3 py-3">{effort.elapsedTimeFormatted}</td>
                                <td className="px-3 py-3">{effort.averageSpeed.value} {effort.averageSpeed.symbol}</td>
                                <td className="px-3 py-3">{effort.averageHeartRate ?? '—'}</td>
                                <td className="px-3 py-3">{null === effort.averageWatts ? '—' : `${Math.round(effort.averageWatts)}w`}</td>
                                <td className="px-3 py-3">{effort.gearName ?? '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function SegmentDetailPanel({
    detail,
    loading,
    error,
}: {
    detail: SegmentDetailResponse | null;
    loading: boolean;
    error: string | null;
}) {
    const [activeTab, setActiveTab] = useState<'top-ten' | 'heart-rate' | 'history'>('top-ten');

    useEffect(() => {
        setActiveTab('top-ten');
    }, [detail?.segment.id]);

    if (loading && !detail) {
        return (
            <div className="ui-section text-sm text-gray-600 dark:text-gray-300">
                Loading segment detail.
            </div>
        );
    }

    if (error) {
        return (
            <div className="rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                {error}
            </div>
        );
    }

    if (!detail) {
        return (
            <div className="ui-section text-sm text-gray-600 dark:text-gray-300">
                Pick a segment to inspect its top efforts and charts.
            </div>
        );
    }

    const segment = detail.segment;

    return (
        <div className="space-y-4">
            <section className="ui-section">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Selected segment</h2>
                        <div className="mt-1 text-base font-semibold text-gray-900 dark:text-white">{segment.displayName}</div>
                        <p className="mt-2 text-sm leading-7 text-gray-500 dark:text-gray-400">
                            {segment.sportType.label}
                            {segment.countryName ? ` · ${segment.countryName}` : ''}
                            {segment.lastEffortDate ? ` · last effort ${formatDate(segment.lastEffortDate)}` : ''}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-3">
                        <a
                            href={segment.url}
                            target="_blank"
                            rel="noreferrer"
                            className="ui-button"
                        >
                            Open in Strava
                        </a>
                    </div>
                </div>

                <div className="mt-4 grid gap-2 sm:grid-cols-4">
                    <div className="rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs dark:border-gray-800 dark:bg-gray-900/60">
                        <div className="uppercase tracking-wide text-gray-500 dark:text-gray-400">Distance</div>
                        <div className="mt-1 font-semibold text-gray-900 dark:text-white">{segment.distance.value} {segment.distance.symbol}</div>
                    </div>
                    <div className="rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs dark:border-gray-800 dark:bg-gray-900/60">
                        <div className="uppercase tracking-wide text-gray-500 dark:text-gray-400">Ride count</div>
                        <div className="mt-1 font-semibold text-gray-900 dark:text-white">{formatNumber(segment.numberOfTimesRidden)}</div>
                    </div>
                    <div className="rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs dark:border-gray-800 dark:bg-gray-900/60">
                        <div className="uppercase tracking-wide text-gray-500 dark:text-gray-400">Average gradient</div>
                        <div className="mt-1 font-semibold text-gray-900 dark:text-white">{null === segment.averageGradient ? '—' : `${segment.averageGradient}%`}</div>
                    </div>
                    <div className="rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs dark:border-gray-800 dark:bg-gray-900/60">
                        <div className="uppercase tracking-wide text-gray-500 dark:text-gray-400">Max gradient</div>
                        <div className="mt-1 font-semibold text-gray-900 dark:text-white">{segment.maxGradient}%</div>
                    </div>
                </div>
            </section>

            <section className="ui-section">
                <div className="inline-flex rounded-lg border border-gray-200 bg-gray-100 p-1 dark:border-gray-700 dark:bg-gray-900">
                    {[
                        {key: 'top-ten', label: 'Top 10'},
                        {key: 'heart-rate', label: 'Effort vs heart rate'},
                        {key: 'history', label: 'History'},
                    ].map((tab) => (
                        <button
                            key={tab.key}
                            type="button"
                            onClick={() => setActiveTab(tab.key as 'top-ten' | 'heart-rate' | 'history')}
                            className={`rounded-md px-4 py-2 text-sm font-medium transition ${activeTab === tab.key
                                ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-white'
                                : 'text-gray-500 dark:text-gray-400'}`}
                        >
                            {tab.label}
                        </button>
                    ))}
                </div>

                <div className="mt-4">
                    {activeTab === 'top-ten' ? <SegmentEffortsTable efforts={detail.topEfforts} /> : null}
                    {activeTab === 'heart-rate' ? (
                        detail.charts.effortVsHeartRate ? (
                            <EChartPanel title="Effort vs heart rate" options={detail.charts.effortVsHeartRate} heightClassName="h-96" />
                        ) : (
                            <div className="rounded-lg border border-gray-200 bg-white p-5 text-sm text-gray-600 shadow-sm dark:border-gray-800 dark:bg-gray-950/40 dark:text-gray-300">
                                <h3 className="text-sm font-semibold text-gray-900 dark:text-white">Effort vs heart rate</h3>
                                <p className="mt-3 leading-7">
                                    There are no heart-rate samples for this segment yet.
                                </p>
                            </div>
                        )
                    ) : null}
                    {activeTab === 'history' ? <EChartPanel title="History" options={detail.charts.history} heightClassName="h-96" /> : null}
                </div>
            </section>
        </div>
    );
}

export function SegmentsPage({bootstrap}: SegmentsPageProps) {
    const [search, setSearch] = useState('');
    const [sportType, setSportType] = useState('all');
    const [countryCode, setCountryCode] = useState('all');
    const [onlyFavourites, setOnlyFavourites] = useState(false);
    const [onlyKom, setOnlyKom] = useState(false);
    const [distanceMin, setDistanceMin] = useState('');
    const [distanceMax, setDistanceMax] = useState('');
    const [gradientMin, setGradientMin] = useState('');
    const [gradientMax, setGradientMax] = useState('');
    const [selectedSegmentId, setSelectedSegmentId] = useState<string | null>(null);

    const loadSegments = useCallback((signal: AbortSignal) => fetchSegmentsPreview(bootstrap.basePath, signal), [bootstrap.basePath]);
    const {data, loading, error, reload} = useAsyncResource(loadSegments);

    const filteredSegments = useMemo(() => {
        return (data?.segments ?? []).filter((segment) => {
            if (!matchesSearch(segment, search.trim().toLowerCase())) {
                return false;
            }

            if ('all' !== sportType && segment.sportType.value !== sportType) {
                return false;
            }

            if ('all' !== countryCode && segment.countryCode !== countryCode) {
                return false;
            }

            if (onlyFavourites && !segment.isFavourite) {
                return false;
            }

            if (onlyKom && !segment.isKom) {
                return false;
            }

            if (!matchesRange(segment.distance.value, distanceMin, distanceMax)) {
                return false;
            }

            if (!matchesRange(segment.averageGradient, gradientMin, gradientMax)) {
                return false;
            }

            return true;
        });
    }, [countryCode, data?.segments, distanceMax, distanceMin, gradientMax, gradientMin, onlyFavourites, onlyKom, search, sportType]);

    useEffect(() => {
        if (0 === filteredSegments.length) {
            setSelectedSegmentId(null);

            return;
        }

        if (!selectedSegmentId || !filteredSegments.some((segment) => segment.id === selectedSegmentId)) {
            setSelectedSegmentId(filteredSegments[0].id);
        }
    }, [filteredSegments, selectedSegmentId]);

    const loadSegmentDetail = useCallback(
        (signal: AbortSignal) => {
            if (!selectedSegmentId) {
                return Promise.resolve(null);
            }

            return fetchSegmentDetail(bootstrap.basePath, selectedSegmentId, signal);
        },
        [bootstrap.basePath, selectedSegmentId],
    );
    const {
        data: detail,
        loading: detailLoading,
        error: detailError,
        reload: reloadDetail,
    } = useAsyncResource<SegmentDetailResponse | null>(loadSegmentDetail);

    const selectedSegment = useMemo(
        () => filteredSegments.find((segment) => segment.id === selectedSegmentId) ?? null,
        [filteredSegments, selectedSegmentId],
    );

    const visibleDetail = detail && detail.segment.id === selectedSegmentId ? detail : null;

    return (
        <div className="space-y-6 pb-6">
            <section className="ui-section">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div className="min-w-0">
                        <h1 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white md:text-2xl">Segments</h1>
                        <p className="mt-1 max-w-3xl text-sm leading-7 text-gray-500 dark:text-gray-400">
                            Search segments, narrow the results, and inspect the selected segment’s top ten and charts.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <a href={buildAppPath(bootstrap.basePath, 'segments')} className="ui-button">
                            Open classic segments page
                        </a>
                        <button
                            type="button"
                            onClick={() => {
                                reload();
                                reloadDetail();
                            }}
                            className="ui-button"
                        >
                            Refresh data
                        </button>
                    </div>
                </div>
            </section>

            <section className="ui-section">
                <div className="grid gap-4 lg:grid-cols-[minmax(0,1.6fr)_repeat(4,minmax(0,1fr))] xl:grid-cols-[minmax(0,1.8fr)_repeat(6,minmax(0,1fr))]">
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                        Search
                        <input
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search segment, sport type, or country"
                            className="ui-input"
                        />
                    </label>
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                        Sport type
                        <select value={sportType} onChange={(event) => setSportType(event.target.value)} className="ui-input">
                            <option value="all">All sport types</option>
                            {data?.filters.sportTypes.map((option) => (
                                <option key={option.value} value={option.value}>{option.label}</option>
                            ))}
                        </select>
                    </label>
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                        Country
                        <select value={countryCode} onChange={(event) => setCountryCode(event.target.value)} className="ui-input">
                            <option value="all">All countries</option>
                            {data?.filters.countries.map((option) => (
                                <option key={option.value} value={option.value}>{option.label}</option>
                            ))}
                        </select>
                    </label>
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                        Distance min
                        <input type="number" min="0" step="0.1" value={distanceMin} onChange={(event) => setDistanceMin(event.target.value)} className="ui-input" />
                    </label>
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                        Distance max
                        <input type="number" min="0" step="0.1" value={distanceMax} onChange={(event) => setDistanceMax(event.target.value)} className="ui-input" />
                    </label>
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                        Gradient min
                        <input type="number" step="0.1" value={gradientMin} onChange={(event) => setGradientMin(event.target.value)} className="ui-input" />
                    </label>
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                        Gradient max
                        <input type="number" step="0.1" value={gradientMax} onChange={(event) => setGradientMax(event.target.value)} className="ui-input" />
                    </label>
                </div>

                <div className="mt-4 flex flex-wrap items-center gap-3 text-sm">
                    <label className="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2.5 text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        <input type="checkbox" checked={onlyFavourites} onChange={(event) => setOnlyFavourites(event.target.checked)} />
                        Only favourites
                    </label>
                    <label className="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2.5 text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        <input type="checkbox" checked={onlyKom} onChange={(event) => setOnlyKom(event.target.checked)} />
                        Only KOMs
                    </label>
                    <button
                        type="button"
                        onClick={() => {
                            setSearch('');
                            setSportType('all');
                            setCountryCode('all');
                            setOnlyFavourites(false);
                            setOnlyKom(false);
                            setDistanceMin('');
                            setDistanceMax('');
                            setGradientMin('');
                            setGradientMax('');
                        }}
                        className="ui-button"
                    >
                        Clear filters
                    </button>
                    <div className="ui-pill">{formatNumber(filteredSegments.length)} results</div>
                    {data ? <div className="text-gray-500 dark:text-gray-400">Refreshed {formatRequestedAt(data.requestedAt)}</div> : null}
                </div>
            </section>

            {loading && !data ? (
                <section className="ui-section text-sm text-gray-600 dark:text-gray-300">
                    Loading segments.
                </section>
            ) : null}

            {error ? (
                <section className="rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                    {error}
                </section>
            ) : null}

            <section className="grid gap-4 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
                <div className="ui-section">
                    <div className="mb-4 flex items-center justify-between gap-3">
                        <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Results</h2>
                        <div className="ui-pill">{formatNumber(filteredSegments.length)}</div>
                    </div>
                    <div className="space-y-2">
                    {0 === filteredSegments.length ? (
                        <div className="text-sm text-gray-600 dark:text-gray-300">
                            No segments match the current filters.
                        </div>
                    ) : (
                        filteredSegments.map((segment) => (
                            <SegmentRow
                                key={segment.id}
                                segment={segment}
                                isActive={segment.id === selectedSegmentId}
                                onSelect={setSelectedSegmentId}
                            />
                        ))
                    )}
                    </div>
                </div>

                <SegmentDetailPanel detail={selectedSegment ? visibleDetail : null} loading={detailLoading} error={detailError} />
            </section>
        </div>
    );
}