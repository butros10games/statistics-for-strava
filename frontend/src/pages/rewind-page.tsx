import {useCallback, useMemo} from 'react';
import {Link, useNavigate, useParams} from 'react-router-dom';
import {EChartPanel} from '../components/echart-panel';
import {RewindActivityMap} from '../components/rewind-activity-map';
import {StatCard} from '../components/stat-card';
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
        <article className="glass-panel overflow-hidden rounded-[32px] p-6">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0">
                    <div className="inline-flex items-center gap-3 rounded-full border border-orange-200 bg-orange-50/80 px-3 py-2 text-xs font-semibold uppercase tracking-[0.2em] text-orange-700 dark:border-orange-900/50 dark:bg-orange-950/30 dark:text-orange-200">
                        <span className="inline-flex h-8 w-8 items-center justify-center rounded-full bg-white text-sm text-strava-orange dark:bg-gray-950">
                            {iconGlyphs[item.icon] ?? '•'}
                        </span>
                        {item.title}
                    </div>
                    {item.subTitle ? (
                        <p className="mt-4 max-w-2xl text-sm leading-7 text-gray-600 dark:text-gray-300">
                            {item.subTitle}
                        </p>
                    ) : null}
                </div>
                {item.totalMetric ? (
                    <div className="rounded-[24px] border border-gray-200 bg-white/80 px-4 py-3 text-right dark:border-gray-800 dark:bg-gray-950/30">
                        <div className="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">{formatNumber(item.totalMetric.value)}</div>
                        <div className="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">{item.totalMetric.label}</div>
                    </div>
                ) : null}
            </div>

            <div className="mt-6">
                {'chart' === item.kind ? (
                    <EChartPanel title="" options={item.chartOptions} heightClassName="h-72" chromeless />
                ) : null}

                {'hero-activity' === item.kind ? (
                    <div className="space-y-5">
                        {item.activity.map ? <RewindActivityMap basePath={bootstrap.basePath} map={item.activity.map} /> : null}
                        <div className="grid gap-4 md:grid-cols-3">
                            <div className="rounded-[24px] border border-gray-200 bg-white/85 p-4 text-sm dark:border-gray-800 dark:bg-gray-950/35">
                                <div className="text-gray-500 dark:text-gray-400">Distance</div>
                                <div className="mt-2 text-lg font-semibold text-gray-900 dark:text-white">{item.activity.distanceLabel}</div>
                            </div>
                            <div className="rounded-[24px] border border-gray-200 bg-white/85 p-4 text-sm dark:border-gray-800 dark:bg-gray-950/35">
                                <div className="text-gray-500 dark:text-gray-400">Elevation</div>
                                <div className="mt-2 text-lg font-semibold text-gray-900 dark:text-white">{item.activity.elevationLabel}</div>
                            </div>
                            <div className="rounded-[24px] border border-gray-200 bg-white/85 p-4 text-sm dark:border-gray-800 dark:bg-gray-950/35">
                                <div className="text-gray-500 dark:text-gray-400">Moving time</div>
                                <div className="mt-2 text-lg font-semibold text-gray-900 dark:text-white">{item.activity.movingTimeLabel}</div>
                            </div>
                        </div>
                        <div className="flex flex-wrap gap-3">
                            <a
                                href={resolveUrl(bootstrap.basePath, item.activity.activityUrl)}
                                className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                            >
                                Open activity detail
                                <span aria-hidden="true">↗</span>
                            </a>
                            <a
                                href={item.activity.externalUrl}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                            >
                                Open on Strava
                                <span aria-hidden="true">↗</span>
                            </a>
                        </div>
                    </div>
                ) : null}

                {'socials' === item.kind ? (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="rounded-[26px] border border-gray-200 bg-white/85 p-6 text-center dark:border-gray-800 dark:bg-gray-950/35">
                            <div className="text-4xl font-semibold tracking-tight text-gray-900 dark:text-white">{formatNumber(item.socials.kudoCount)}</div>
                            <div className="mt-2 text-sm font-medium text-gray-500 dark:text-gray-400">Kudos received</div>
                        </div>
                        <div className="rounded-[26px] border border-gray-200 bg-white/85 p-6 text-center dark:border-gray-800 dark:bg-gray-950/35">
                            <div className="text-4xl font-semibold tracking-tight text-gray-900 dark:text-white">{formatNumber(item.socials.commentCount)}</div>
                            <div className="mt-2 text-sm font-medium text-gray-500 dark:text-gray-400">Comments received</div>
                        </div>
                    </div>
                ) : null}

                {'streaks' === item.kind ? (
                    <div className="grid gap-4 md:grid-cols-3">
                        {[
                            {label: 'Days', value: item.streaks.dayStreak},
                            {label: 'Weeks', value: item.streaks.weekStreak},
                            {label: 'Months', value: item.streaks.monthStreak},
                        ].map((entry) => (
                            <div key={entry.label} className="rounded-[26px] border border-gray-200 bg-white/85 p-6 text-center dark:border-gray-800 dark:bg-gray-950/35">
                                <div className="text-4xl font-semibold tracking-tight text-gray-900 dark:text-white">{formatNumber(entry.value)}</div>
                                <div className="mt-2 text-sm font-medium uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">{entry.label}</div>
                            </div>
                        ))}
                    </div>
                ) : null}

                {'carbon-saved' === item.kind ? (
                    <div className="grid gap-4 lg:grid-cols-[1fr_1fr_1fr]">
                        <div className="rounded-[26px] border border-emerald-200 bg-emerald-50/80 p-6 text-center dark:border-emerald-900/40 dark:bg-emerald-950/25">
                            <div className="text-4xl font-semibold tracking-tight text-gray-900 dark:text-white">{item.carbonSaved.kilograms.toFixed(2)}</div>
                            <div className="mt-2 text-sm font-medium text-emerald-800 dark:text-emerald-200">kg CO₂ saved</div>
                        </div>
                        <div className="rounded-[26px] border border-gray-200 bg-white/85 p-6 text-center dark:border-gray-800 dark:bg-gray-950/35">
                            <div className="text-4xl font-semibold tracking-tight text-gray-900 dark:text-white">{formatNumber(item.carbonSaved.petBottlesProduced)}</div>
                            <div className="mt-2 text-sm font-medium text-gray-500 dark:text-gray-400">PET bottles produced</div>
                        </div>
                        <div className="rounded-[26px] border border-gray-200 bg-white/85 p-6 text-center dark:border-gray-800 dark:bg-gray-950/35">
                            <div className="text-4xl font-semibold tracking-tight text-gray-900 dark:text-white">{formatNumber(item.carbonSaved.googleSearches)}</div>
                            <div className="mt-2 text-sm font-medium text-gray-500 dark:text-gray-400">Google searches</div>
                        </div>
                    </div>
                ) : null}

                {'photo' === item.kind ? (
                    <a href={resolveUrl(bootstrap.basePath, item.photo.activityUrl)} className="group block overflow-hidden rounded-[28px] border border-gray-200 bg-white/85 p-3 transition hover:-translate-y-0.5 dark:border-gray-800 dark:bg-gray-950/35">
                        <div className="overflow-hidden rounded-[22px] bg-gray-100 dark:bg-gray-900">
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
        <div className="space-y-8 pb-8">
            <section className="glass-panel overflow-hidden rounded-[36px] p-6 md:p-8">
                <div className="grid gap-8 xl:grid-cols-[1.12fr_0.88fr]">
                    <div>
                        <div className="section-kicker">Rewind preview</div>
                        <h1 className="mt-5 max-w-4xl text-4xl font-semibold tracking-tight text-gray-900 dark:text-white md:text-5xl">
                            The yearly recap route, rebuilt as a polished React story deck without asking Symfony to give up the stats brain.
                        </h1>
                        <p className="mt-5 max-w-3xl text-base leading-8 text-gray-600 dark:text-gray-300 md:text-lg">
                            Rewind is where the migration stops being “a bunch of pages” and starts feeling like a real alternate frontend. Charts, media, map-backed hero cards, and option switching all live here—while the backend keeps owning the actual calculations.
                        </p>
                        <div className="mt-6 flex flex-wrap gap-3">
                            <a
                                href={legacyHref}
                                className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                            >
                                Open the current rewind page
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
                    <div className="rounded-[32px] border border-violet-200 bg-[linear-gradient(135deg,rgba(255,255,255,0.96),rgba(245,243,255,0.96))] p-5 shadow-[0_45px_120px_-45px_rgba(15,23,42,0.65)] dark:border-violet-900/40 dark:bg-[linear-gradient(135deg,rgba(17,24,39,0.94),rgba(49,46,129,0.3))]">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-violet-700 dark:text-violet-200">Why rewind matters</div>
                        <div className="mt-4 space-y-3 text-sm leading-7 text-gray-700 dark:text-gray-200">
                            {[
                                'It proves the preview can handle rich, mixed-content routes instead of just tables and simple charts.',
                                'The page keeps yearly option switching explicit and route-shareable, which is exactly the sort of state React is good at owning.',
                                'Once rewind lands, most remaining complexity is depth and breadth—not whether the preview architecture can cope.',
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
                <StatCard label="Available snapshots" value={formatNumber(data?.summary.optionCount ?? 0)} hint="All-time plus any published yearly rewind editions." tone="orange" />
                <StatCard label="Year editions" value={formatNumber(data?.summary.yearOptionCount ?? 0)} hint="Published year-specific rewind views currently available." tone="blue" />
                <StatCard label="Cards in view" value={formatNumber(data?.selectedOption.cardsCount ?? 0)} hint="Visible recap cards for the selected rewind edition." tone="emerald" />
                <StatCard label="Activities counted" value={formatNumber(data?.selectedOption.totalActivities ?? 0)} hint="Activities contributing to the selected recap edition." tone="slate" />
            </section>

            <section className="glass-panel rounded-[32px] p-6">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div>
                        <div className="section-kicker">Edition switcher</div>
                        <p className="mt-4 max-w-3xl text-sm leading-7 text-gray-600 dark:text-gray-300">
                            The legacy route hides yearly switching inside dropdowns. The preview turns those into direct route-backed controls, which makes the rewind easier to explore and much easier to reason about in code.
                        </p>
                    </div>
                    <div className="text-sm text-gray-500 dark:text-gray-400">
                        {data ? `Preview data refreshed ${formatRequestedAt(data.requestedAt)}.` : 'Waiting for rewind preview data.'}
                    </div>
                </div>

                <div className="mt-6 flex flex-wrap gap-2">
                    {(data?.options ?? []).map((option) => {
                        const isActive = option.value === data?.selectedOption.value;

                        return (
                            <button
                                key={option.value}
                                type="button"
                                onClick={() => navigate(`/rewind/${option.value}`)}
                                className={`rounded-full border px-4 py-2 text-sm font-medium transition ${isActive
                                    ? 'border-strava-orange bg-orange-50 text-orange-700 dark:border-orange-400 dark:bg-orange-950/40 dark:text-orange-200'
                                    : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:text-white'}`}
                            >
                                {option.label}
                            </button>
                        );
                    })}
                </div>

                {data?.summary.comparisonAvailable ? (
                    <div className="mt-5 rounded-[24px] border border-amber-200 bg-amber-50/80 p-4 text-sm leading-7 text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/25 dark:text-amber-100">
                        Comparison routes still belong to the sequel. This first React cut focuses on the primary rewind edition page and keeps the scope crisp.
                    </div>
                ) : null}
            </section>

            {loading && !data ? (
                <section className="glass-panel rounded-[32px] p-6 text-sm text-gray-600 dark:text-gray-300">
                    Loading rewind preview… rummaging through the trophy cabinet and chart attic.
                </section>
            ) : null}

            {error ? (
                <section className="rounded-[32px] border border-rose-200 bg-rose-50/90 p-6 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                    {error}
                </section>
            ) : null}

            {!loading && !error && data && 0 === data.items.length ? (
                <section className="glass-panel rounded-[32px] p-6 text-sm leading-7 text-gray-600 dark:text-gray-300">
                    No rewind cards are available for this edition yet. Once activity data exists for the selected snapshot, the recap deck will appear here.
                </section>
            ) : null}

            {data && data.items.length > 0 ? (
                <section className="grid gap-6 xl:grid-cols-2">
                    {data.items.map((item) => (
                        <RewindCard key={item.id} bootstrap={bootstrap} item={item} />
                    ))}
                </section>
            ) : null}

            <section className="grid gap-6 xl:grid-cols-[0.95fr_1.05fr]">
                <div className="glass-panel rounded-[32px] p-6">
                    <div className="section-kicker">Attribution</div>
                    <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">A recap with good lineage</h2>
                    <p className="mt-4 text-sm leading-7 text-gray-600 dark:text-gray-300">
                        The original rewind concept and chart inspiration are credited in the legacy route to Kai’s work. The React preview keeps that acknowledgment intact while adapting the presentation to the parallel app shell.
                    </p>
                    <div className="mt-5 rounded-[24px] border border-blue-200 bg-blue-50/80 p-4 text-sm leading-7 text-blue-900 dark:border-blue-900/40 dark:bg-blue-950/25 dark:text-blue-100">
                        Your Strava {currentYear} rewind will be available on the 24th of December.
                    </div>
                </div>
                <div className="glass-panel rounded-[32px] p-6">
                    <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div>
                            <div className="section-kicker">Migration readout</div>
                            <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">A bigger route now comfortably inside the preview shell</h2>
                        </div>
                        <Link
                            to="/roadmap"
                            className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                        >
                            Open the migration roadmap
                            <span aria-hidden="true">→</span>
                        </Link>
                    </div>
                    <div className="mt-5 space-y-3 text-sm leading-7 text-gray-600 dark:text-gray-300">
                        {[
                            'Rewind folds chart cards, media cards, and map-backed hero content into one route without re-implementing the domain logic in TypeScript.',
                            'The preview now covers another high-identity page, which makes the React experiment feel less like a demo and more like a legitimate alternate frontend.',
                            'What remains after this is mostly more coverage—not proving the architecture can carry rich routes at all.',
                        ].map((item) => (
                            <div key={item} className="rounded-2xl border border-gray-200 bg-white/80 p-4 dark:border-gray-800 dark:bg-gray-950/30">
                                {item}
                            </div>
                        ))}
                    </div>
                </div>
            </section>
        </div>
    );
}