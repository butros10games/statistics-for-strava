import {useCallback, useEffect, useMemo, useState} from 'react';
import {EChartPanel} from '../components/echart-panel';
import {StatCard} from '../components/stat-card';
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
            className={`w-full rounded-[24px] border p-4 text-left transition ${
                isActive
                    ? 'border-orange-500 bg-orange-50/90 shadow-[0_30px_80px_-50px_rgba(242,103,34,0.55)] dark:border-orange-400 dark:bg-orange-950/40'
                    : 'border-gray-200 bg-white/90 hover:border-gray-300 dark:border-gray-800 dark:bg-gray-950/30 dark:hover:border-gray-700'
            }`}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="truncate text-base font-semibold text-gray-900 dark:text-white">{segment.displayName}</h3>
                        {segment.isFavourite ? <span className="rounded-full border border-amber-300 bg-amber-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.2em] text-amber-800 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-200">Starred</span> : null}
                        {segment.isKom ? <span className="rounded-full border border-emerald-300 bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.2em] text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-950/30 dark:text-emerald-200">KOM</span> : null}
                    </div>
                    <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">
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

            <div className="mt-4 grid gap-3 sm:grid-cols-3">
                <div className="rounded-2xl border border-white/80 bg-white/80 p-3 text-sm dark:border-gray-800 dark:bg-gray-950/40">
                    <div className="text-xs uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">Best effort</div>
                    <div className="mt-2 font-semibold text-gray-900 dark:text-white">{segment.bestEffort?.elapsedTimeFormatted ?? '—'}</div>
                    <div className="mt-1 text-gray-600 dark:text-gray-300">
                        {segment.bestEffort ? `${segment.bestEffort.averageSpeed.value} ${segment.bestEffort.averageSpeed.symbol}` : 'No effort yet'}
                    </div>
                </div>
                <div className="rounded-2xl border border-white/80 bg-white/80 p-3 text-sm dark:border-gray-800 dark:bg-gray-950/40">
                    <div className="text-xs uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">Average gradient</div>
                    <div className="mt-2 font-semibold text-gray-900 dark:text-white">{segment.averageGradient ?? '—'}%</div>
                    <div className="mt-1 text-gray-600 dark:text-gray-300">Max {segment.maxGradient}%</div>
                </div>
                <div className="rounded-2xl border border-white/80 bg-white/80 p-3 text-sm dark:border-gray-800 dark:bg-gray-950/40">
                    <div className="text-xs uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">Segment link</div>
                    <div className="mt-2 font-semibold text-gray-900 dark:text-white">Open in Strava</div>
                    <div className="mt-1 text-gray-600 dark:text-gray-300">Compare the preview against the source.</div>
                </div>
            </div>
        </button>
    );
}

function SegmentEffortsTable({efforts}: {efforts: SegmentEffortRow[]}) {
    if (0 === efforts.length) {
        return (
            <div className="rounded-[28px] border border-gray-200 bg-white/92 p-5 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-950/40 dark:text-gray-300">
                No efforts available for this segment yet.
            </div>
        );
    }

    return (
        <div className="rounded-[28px] border border-gray-200 bg-white/92 p-5 shadow-sm dark:border-gray-800 dark:bg-gray-950/40">
            <h3 className="text-lg font-semibold text-gray-900 dark:text-white">Top efforts</h3>
            <div className="mt-4 overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-800">
                    <thead>
                        <tr className="text-xs uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">
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
    if (loading && !detail) {
        return (
            <div className="glass-panel rounded-[32px] p-6 text-sm text-gray-600 dark:text-gray-300">
                Loading segment detail… chasing PRs without having to actually suffer on the climb right now.
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

    if (!detail) {
        return (
            <div className="glass-panel rounded-[32px] p-6 text-sm text-gray-600 dark:text-gray-300">
                Pick a segment to inspect its effort history, heart-rate scatter, and the top ten leaderboard.
            </div>
        );
    }

    const segment = detail.segment;

    return (
        <div className="space-y-6">
            <section className="glass-panel rounded-[32px] p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <div className="section-kicker">Selected segment</div>
                        <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">{segment.displayName}</h2>
                        <p className="mt-3 text-sm leading-7 text-gray-600 dark:text-gray-300">
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
                            className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                        >
                            Open in Strava
                            <span aria-hidden="true">↗</span>
                        </a>
                    </div>
                </div>

                <div className="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <StatCard label="Distance" value={`${segment.distance.value} ${segment.distance.symbol}`} hint="Current segment length in the active unit system." tone="orange" />
                    <StatCard label="Ride count" value={formatNumber(segment.numberOfTimesRidden)} hint="How many times this segment has been completed." tone="emerald" />
                    <StatCard label="Average gradient" value={null === segment.averageGradient ? '—' : `${segment.averageGradient}%`} hint="Average gradient reported by the segment metadata." tone="blue" />
                    <StatCard label="Max gradient" value={`${segment.maxGradient}%`} hint="Peak gradient captured for the segment." tone="slate" />
                </div>
            </section>

            <section className="grid gap-6 xl:grid-cols-2">
                <EChartPanel title="Effort history" options={detail.charts.history} heightClassName="h-96" />
                {detail.charts.effortVsHeartRate ? (
                    <EChartPanel title="Effort vs heart rate" options={detail.charts.effortVsHeartRate} heightClassName="h-96" />
                ) : (
                    <div className="rounded-[28px] border border-gray-200 bg-white/92 p-5 text-sm text-gray-600 shadow-sm dark:border-gray-800 dark:bg-gray-950/40 dark:text-gray-300">
                        <h3 className="text-lg font-semibold text-gray-900 dark:text-white">Effort vs heart rate</h3>
                        <p className="mt-4 leading-7">
                            There are no heart-rate samples for this segment yet, so the scatter chart is sitting this interval out.
                        </p>
                    </div>
                )}
            </section>

            <SegmentEffortsTable efforts={detail.topEfforts} />
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
        <div className="space-y-8 pb-8">
            <section className="glass-panel rounded-[36px] p-6 md:p-8">
                <div className="grid gap-8 xl:grid-cols-[1.1fr_0.9fr]">
                    <div>
                        <div className="section-kicker">Segments preview</div>
                        <h1 className="mt-5 max-w-3xl text-4xl font-semibold tracking-tight text-gray-900 dark:text-white md:text-5xl">
                            Filterable segment hunting, now with React driving the table and charts instead of a modal maze.
                        </h1>
                        <p className="mt-5 max-w-2xl text-base leading-8 text-gray-600 dark:text-gray-300 md:text-lg">
                            This slice keeps the route read-only, preserves the leaderboard and chart analysis, and turns segment exploration into a quicker compare-and-inspect workflow.
                        </p>
                        <div className="mt-6 flex flex-wrap gap-3">
                            <a
                                href={buildAppPath(bootstrap.basePath, 'segments')}
                                className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                            >
                                Open the current segments page
                                <span aria-hidden="true">↗</span>
                            </a>
                            <button
                                type="button"
                                onClick={() => {
                                    reload();
                                    reloadDetail();
                                }}
                                className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                            >
                                Refresh preview data
                                <span aria-hidden="true">↻</span>
                            </button>
                        </div>
                    </div>
                    <div className="rounded-[32px] border border-violet-200 bg-violet-50/80 p-5 shadow-[0_45px_120px_-45px_rgba(15,23,42,0.65)] dark:border-violet-900/50 dark:bg-violet-950/30">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-violet-700 dark:text-violet-200">Why this seam works</div>
                        <div className="mt-4 space-y-3 text-sm leading-7 text-gray-700 dark:text-gray-200">
                            {[
                                'It reuses the filter-heavy table pattern from activities without dragging in write flows.',
                                'It exercises the reusable chart wrapper on another route, this time with a focused detail view.',
                                'It sets up a sturdy pattern for other inspectable analytics routes such as best efforts and photos.',
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
                <StatCard label="Visible segments" value={formatNumber(filteredSegments.length)} hint="Rows matching the currently active preview filters." tone="orange" />
                <StatCard label="Starred" value={formatNumber(filteredSegments.filter((segment) => segment.isFavourite).length)} hint="Favourite segments in the current filtered result set." tone="emerald" />
                <StatCard label="KOM markers" value={formatNumber(filteredSegments.filter((segment) => segment.isKom).length)} hint="Segments flagged as KOMs or climb-category standouts." tone="blue" />
                <StatCard label="Countries" value={formatNumber(new Set(filteredSegments.map((segment) => segment.countryCode).filter(Boolean)).size)} hint="Distinct countries represented in the filtered set." tone="slate" />
            </section>

            <section className="glass-panel rounded-[32px] p-6">
                <div className="grid gap-4 lg:grid-cols-[minmax(0,1.6fr)_repeat(4,minmax(0,1fr))] xl:grid-cols-[minmax(0,1.8fr)_repeat(6,minmax(0,1fr))]">
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                        Search
                        <input
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search segment, sport type, or country"
                            className="rounded-2xl border border-gray-300 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition focus:border-orange-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                        />
                    </label>
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                        Sport type
                        <select value={sportType} onChange={(event) => setSportType(event.target.value)} className="rounded-2xl border border-gray-300 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition focus:border-orange-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                            <option value="all">All sport types</option>
                            {data?.filters.sportTypes.map((option) => (
                                <option key={option.value} value={option.value}>{option.label}</option>
                            ))}
                        </select>
                    </label>
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                        Country
                        <select value={countryCode} onChange={(event) => setCountryCode(event.target.value)} className="rounded-2xl border border-gray-300 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition focus:border-orange-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                            <option value="all">All countries</option>
                            {data?.filters.countries.map((option) => (
                                <option key={option.value} value={option.value}>{option.label}</option>
                            ))}
                        </select>
                    </label>
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                        Distance min
                        <input type="number" min="0" step="0.1" value={distanceMin} onChange={(event) => setDistanceMin(event.target.value)} className="rounded-2xl border border-gray-300 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition focus:border-orange-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
                    </label>
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                        Distance max
                        <input type="number" min="0" step="0.1" value={distanceMax} onChange={(event) => setDistanceMax(event.target.value)} className="rounded-2xl border border-gray-300 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition focus:border-orange-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
                    </label>
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                        Gradient min
                        <input type="number" step="0.1" value={gradientMin} onChange={(event) => setGradientMin(event.target.value)} className="rounded-2xl border border-gray-300 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition focus:border-orange-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
                    </label>
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                        Gradient max
                        <input type="number" step="0.1" value={gradientMax} onChange={(event) => setGradientMax(event.target.value)} className="rounded-2xl border border-gray-300 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition focus:border-orange-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
                    </label>
                </div>

                <div className="mt-4 flex flex-wrap items-center gap-3 text-sm">
                    <label className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        <input type="checkbox" checked={onlyFavourites} onChange={(event) => setOnlyFavourites(event.target.checked)} />
                        Only favourites
                    </label>
                    <label className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
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
                        className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                    >
                        Clear filters
                    </button>
                    {data ? <div className="text-gray-500 dark:text-gray-400">Data refreshed {formatRequestedAt(data.requestedAt)}</div> : null}
                </div>
            </section>

            {loading && !data ? (
                <section className="glass-panel rounded-[32px] p-6 text-sm text-gray-600 dark:text-gray-300">
                    Loading segments preview… one suspiciously specific climb at a time.
                </section>
            ) : null}

            {error ? (
                <section className="rounded-[32px] border border-rose-200 bg-rose-50/90 p-6 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                    {error}
                </section>
            ) : null}

            <section className="grid gap-6 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
                <div className="space-y-4">
                    {0 === filteredSegments.length ? (
                        <div className="glass-panel rounded-[32px] p-6 text-sm text-gray-600 dark:text-gray-300">
                            No segments match the current filters. Time to loosen the net a little.
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

                <SegmentDetailPanel detail={selectedSegment ? visibleDetail : null} loading={detailLoading} error={detailError} />
            </section>
        </div>
    );
}