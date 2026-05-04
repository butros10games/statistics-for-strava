import {useCallback, useMemo} from 'react';
import {useNavigate, useParams} from 'react-router-dom';
import {EChartPanel} from '../components/echart-panel';
import {RewindActivityMap} from '../components/rewind-activity-map';
import {buildAppPath, type ReactPreviewBootstrap} from '../lib/bootstrap';
import {
    fetchRewindPreview,
    type RewindPreviewItem,
    type RewindPreviewResponse,
} from '../lib/rewind-preview-api';
import {useAsyncResource} from '../lib/use-async-resource';

interface RewindPageProps {
    bootstrap: ReactPreviewBootstrap;
}

const iconGlyphs: Record<string, string> = {
    bed: '☾',
    calendar: '◷',
    carbon: '◌',
    clock: '◎',
    fire: '✺',
    globe: '◍',
    image: '▣',
    medal: '✦',
    mountain: '△',
    muscle: '◉',
    rocket: '↗',
    'thumbs-up': '☺',
    tools: '⬡',
    trophy: '▲',
    watch: '◴',
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

function resolveUrl(basePath: string, url: string): string {
    return url.startsWith('http://') || url.startsWith('https://')
        ? url
        : buildAppPath(basePath, url);
}

function RewindCard({bootstrap, item}: {bootstrap: ReactPreviewBootstrap; item: RewindPreviewItem}) {
    return (
        <article className="ui-section overflow-hidden">
            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0">
                    <div className="inline-flex items-center gap-2 rounded-lg border border-orange-200 bg-orange-50 px-2.5 py-1.5 text-[10px] font-semibold uppercase tracking-[0.18em] text-orange-700 dark:border-orange-900/50 dark:bg-orange-950/30 dark:text-orange-200">
                        <span className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-white text-[13px] text-strava-orange dark:bg-gray-950">
                            {iconGlyphs[item.icon] ?? '•'}
                        </span>
                        {item.title}
                    </div>
                    {item.subTitle ? (
                        <p className="mt-3 max-w-2xl text-[13px] leading-6 text-gray-600 dark:text-gray-300">
                            {item.subTitle}
                        </p>
                    ) : null}
                </div>
                {item.totalMetric ? (
                    <div className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-right dark:border-gray-800 dark:bg-gray-900/40">
                        <div className="text-[1.35rem] font-semibold tracking-tight text-gray-900 dark:text-white">{formatNumber(item.totalMetric.value)}</div>
                        <div className="text-[10px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">{item.totalMetric.label}</div>
                    </div>
                ) : null}
            </div>

            <div className="mt-4">
                {'chart' === item.kind ? (
                    <EChartPanel title="" options={item.chartOptions} heightClassName="h-72" chromeless />
                ) : null}

                {'hero-activity' === item.kind ? (
                    <div className="space-y-4">
                        {item.activity.map ? <RewindActivityMap basePath={bootstrap.basePath} map={item.activity.map} /> : null}
                        <div className="grid gap-3 md:grid-cols-3">
                            <div className="rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-800 dark:bg-gray-900/35">
                                <div className="text-gray-500 dark:text-gray-400">Distance</div>
                                <div className="mt-1 text-base font-semibold text-gray-900 dark:text-white">{item.activity.distanceLabel}</div>
                            </div>
                            <div className="rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-800 dark:bg-gray-900/35">
                                <div className="text-gray-500 dark:text-gray-400">Elevation</div>
                                <div className="mt-1 text-base font-semibold text-gray-900 dark:text-white">{item.activity.elevationLabel}</div>
                            </div>
                            <div className="rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-800 dark:bg-gray-900/35">
                                <div className="text-gray-500 dark:text-gray-400">Moving time</div>
                                <div className="mt-1 text-base font-semibold text-gray-900 dark:text-white">{item.activity.movingTimeLabel}</div>
                            </div>
                        </div>
                        <div className="flex flex-wrap gap-3">
                            <a
                                href={resolveUrl(bootstrap.basePath, item.activity.activityUrl)}
                                className="ui-button"
                            >
                                Open activity detail
                            </a>
                            <a
                                href={item.activity.externalUrl}
                                target="_blank"
                                rel="noreferrer"
                                className="ui-button"
                            >
                                Open on Strava
                            </a>
                        </div>
                    </div>
                ) : null}

                {'socials' === item.kind ? (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 text-center dark:border-gray-800 dark:bg-gray-900/35">
                            <div className="text-[2rem] font-semibold tracking-tight text-gray-900 dark:text-white">{formatNumber(item.socials.kudoCount)}</div>
                            <div className="mt-1 text-[13px] font-medium text-gray-500 dark:text-gray-400">Kudos received</div>
                        </div>
                        <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 text-center dark:border-gray-800 dark:bg-gray-900/35">
                            <div className="text-[2rem] font-semibold tracking-tight text-gray-900 dark:text-white">{formatNumber(item.socials.commentCount)}</div>
                            <div className="mt-1 text-[13px] font-medium text-gray-500 dark:text-gray-400">Comments received</div>
                        </div>
                    </div>
                ) : null}

                {'streaks' === item.kind ? (
                    <div className="grid gap-3 md:grid-cols-3">
                        {[
                            {label: 'Days', value: item.streaks.dayStreak},
                            {label: 'Weeks', value: item.streaks.weekStreak},
                            {label: 'Months', value: item.streaks.monthStreak},
                        ].map((entry) => (
                            <div key={entry.label} className="rounded-lg border border-gray-200 bg-gray-50 p-4 text-center dark:border-gray-800 dark:bg-gray-900/35">
                                <div className="text-[2rem] font-semibold tracking-tight text-gray-900 dark:text-white">{formatNumber(entry.value)}</div>
                                <div className="mt-1 text-[12px] font-medium uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">{entry.label}</div>
                            </div>
                        ))}
                    </div>
                ) : null}

                {'carbon-saved' === item.kind ? (
                    <div className="grid gap-3 lg:grid-cols-[1fr_1fr_1fr]">
                        <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-center dark:border-emerald-900/40 dark:bg-emerald-950/25">
                            <div className="text-[2rem] font-semibold tracking-tight text-gray-900 dark:text-white">{item.carbonSaved.kilograms.toFixed(2)}</div>
                            <div className="mt-1 text-[13px] font-medium text-emerald-800 dark:text-emerald-200">kg CO₂ saved</div>
                        </div>
                        <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 text-center dark:border-gray-800 dark:bg-gray-900/35">
                            <div className="text-[2rem] font-semibold tracking-tight text-gray-900 dark:text-white">{formatNumber(item.carbonSaved.petBottlesProduced)}</div>
                            <div className="mt-1 text-[13px] font-medium text-gray-500 dark:text-gray-400">PET bottles produced</div>
                        </div>
                        <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 text-center dark:border-gray-800 dark:bg-gray-900/35">
                            <div className="text-[2rem] font-semibold tracking-tight text-gray-900 dark:text-white">{formatNumber(item.carbonSaved.googleSearches)}</div>
                            <div className="mt-1 text-[13px] font-medium text-gray-500 dark:text-gray-400">Google searches</div>
                        </div>
                    </div>
                ) : null}

                {'photo' === item.kind ? (
                    <a href={resolveUrl(bootstrap.basePath, item.photo.activityUrl)} className="group block overflow-hidden rounded-lg border border-gray-200 bg-white p-3 transition hover:border-orange-300 dark:border-gray-800 dark:bg-gray-950/35">
                        <div className="overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-900">
                            <img
                                src={resolveUrl(bootstrap.basePath, item.photo.imageUrl)}
                                alt={item.photo.activityName}
                                className={`w-full object-cover transition duration-500 group-hover:scale-[1.02] ${'PORTRAIT' === item.photo.orientation ? 'max-h-[32rem]' : 'max-h-[26rem]'}`}
                                loading="lazy"
                            />
                        </div>
                        <div className="mt-4 flex flex-col gap-2 px-1 pb-1">
                            <div className="text-lg font-semibold text-gray-900 dark:text-white">{item.photo.activityName}</div>
                            <div className="text-sm text-gray-500 dark:text-gray-400">{item.photo.activityDateLabel}</div>
                        </div>
                    </a>
                ) : null}
            </div>
        </article>
    );
}

export function RewindPage({bootstrap}: RewindPageProps) {
    const navigate = useNavigate();
    const params = useParams<{rewindOption?: string}>();
    const requestedOption = params.rewindOption ?? null;

    const loadRewind = useCallback(
        (signal: AbortSignal): Promise<RewindPreviewResponse> => fetchRewindPreview(bootstrap.basePath, requestedOption, signal),
        [bootstrap.basePath, requestedOption],
    );

    const {data, loading, error, reload} = useAsyncResource(loadRewind);

    const legacyHref = useMemo(() => {
        if (!data?.selectedOption) {
            return buildAppPath(bootstrap.basePath, 'rewind');
        }

        return data.selectedOption.isAllTime
            ? buildAppPath(bootstrap.basePath, 'rewind')
            : buildAppPath(bootstrap.basePath, `rewind/${data.selectedOption.value}`);
    }, [bootstrap.basePath, data?.selectedOption]);

    const currentYear = useMemo(
        () => new Date(data?.requestedAt ?? Date.now()).getFullYear(),
        [data?.requestedAt],
    );

    return (
        <div className="space-y-6 pb-6">
            <section className="ui-section">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div className="min-w-0">
                        <h1 className="text-[1.8rem] font-bold tracking-tight text-gray-900 dark:text-white">Rewind</h1>
                        <p className="mt-1 max-w-3xl text-[13px] leading-6 text-gray-500 dark:text-gray-400">
                            Yearly recap deck with route-backed option switching and mixed chart, map, photo, and summary cards.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <a href={legacyHref} className="ui-button">
                            Open classic rewind page
                        </a>
                        <button type="button" onClick={reload} className="ui-button">
                            Refresh data
                        </button>
                    </div>
                </div>
            </section>

            <section className="ui-section">
                <div className="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
                    <div>
                        <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Edition switcher</h2>
                        <p className="mt-1 max-w-3xl text-[13px] leading-6 text-gray-500 dark:text-gray-400">
                            Switch between all-time and yearly recap editions.
                        </p>
                    </div>
                    <div className="text-sm text-gray-500 dark:text-gray-400">
                        {data ? `Refreshed ${formatRequestedAt(data.requestedAt)}.` : 'Waiting for rewind data.'}
                    </div>
                </div>

                <div className="mt-4 flex flex-wrap gap-2">
                    {(data?.options ?? []).map((option) => {
                        const isActive = option.value === data?.selectedOption.value;

                        return (
                            <button
                                key={option.value}
                                type="button"
                                onClick={() => navigate(`/rewind/${option.value}`)}
                                className={`rounded-lg border px-3 py-1.5 text-[13px] font-medium transition ${isActive
                                    ? 'border-orange-300 bg-orange-50 text-orange-700 dark:border-orange-400 dark:bg-orange-950/40 dark:text-orange-200'
                                    : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:text-white'}`}
                            >
                                {option.label}
                            </button>
                        );
                    })}
                </div>

                {data?.summary.comparisonAvailable ? (
                    <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-[13px] leading-6 text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/25 dark:text-amber-100">
                        Comparison routes are not included here yet. This screen focuses on the primary rewind edition page.
                    </div>
                ) : null}
            </section>

            {loading && !data ? (
                <section className="ui-section text-sm text-gray-600 dark:text-gray-300">
                    Loading rewind.
                </section>
            ) : null}

            {error ? (
                <section className="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                    {error}
                </section>
            ) : null}

            {!loading && !error && data && 0 === data.items.length ? (
                <section className="ui-section text-sm leading-7 text-gray-600 dark:text-gray-300">
                    No rewind cards are available for this edition yet. Once activity data exists for the selected snapshot, the recap deck will appear here.
                </section>
            ) : null}

            {data && data.items.length > 0 ? (
                <section className="grid gap-4 lg:grid-cols-2">
                    {data.items.map((item) => (
                        <RewindCard key={item.id} bootstrap={bootstrap} item={item} />
                    ))}
                </section>
            ) : null}

            <section className="grid gap-3.5 xl:grid-cols-[0.95fr_1.05fr]">
                <div className="ui-section">
                    <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Attribution</h2>
                    <p className="mt-2.5 text-[13px] leading-6 text-gray-600 dark:text-gray-300">
                        The original rewind concept and chart inspiration are credited to Kai’s work. This version keeps that acknowledgment intact while adapting the presentation to the current app shell.
                    </p>
                    <div className="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-3 text-[13px] leading-6 text-blue-900 dark:border-blue-900/40 dark:bg-blue-950/25 dark:text-blue-100">
                        Your Strava {currentYear} rewind will be available on the 24th of December.
                    </div>
                </div>
                <div className="ui-section">
                    <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Screen notes</h2>
                    <div className="mt-3 space-y-2 text-[13px] leading-6 text-gray-600 dark:text-gray-300">
                        {[
                            'Rewind folds chart cards, media cards, and map-backed hero content into one route without re-implementing the domain logic in TypeScript.',
                            'It now covers another high-identity page while keeping the backend as the source of truth for the underlying calculations.',
                            'What remains here is mostly incremental cleanup and feature coverage rather than architectural uncertainty.',
                        ].map((item) => (
                            <div key={item} className="rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-900/30">
                                {item}
                            </div>
                        ))}
                    </div>
                </div>
            </section>
        </div>
    );
}