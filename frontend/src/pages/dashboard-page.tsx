import {useCallback} from 'react';
import {Link} from 'react-router-dom';
import {StatCard} from '../components/stat-card';
import {buildAppPath, type ReactPreviewBootstrap} from '../lib/bootstrap';
import {fetchDashboardPreview, type DashboardPreviewResponse, type DashboardPreviewWidget} from '../lib/dashboard-preview-api';
import {useAsyncResource} from '../lib/use-async-resource';

interface DashboardPageProps {
    bootstrap: ReactPreviewBootstrap;
}

function formatRequestedAt(value: string): string {
    return new Intl.DateTimeFormat('en', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function widgetWidthClass(width: number): string {
    switch (width) {
        case 33:
            return 'xl:col-span-2';
        case 50:
            return 'xl:col-span-3';
        case 66:
            return 'xl:col-span-4';
        default:
            return 'xl:col-span-6';
    }
}

function DashboardWidgetCard({widget}: {widget: DashboardPreviewWidget}) {
    return (
        <article className={`${widgetWidthClass(widget.width)} flex flex-col rounded-[28px] border border-gray-200 bg-white/92 p-5 shadow-sm dark:border-gray-800 dark:bg-gray-950/40`}>
            <div dangerouslySetInnerHTML={{__html: widget.html}} />
        </article>
    );
}

export function DashboardPage({bootstrap}: DashboardPageProps) {
    const loadDashboard = useCallback(
        (signal: AbortSignal): Promise<DashboardPreviewResponse> => fetchDashboardPreview(bootstrap.basePath, signal),
        [bootstrap.basePath],
    );

    const {data, loading, error, reload} = useAsyncResource(loadDashboard);

    return (
        <div className="space-y-8 pb-8">
            <section className="glass-panel overflow-hidden rounded-[36px] p-6 md:p-8">
                <div className="grid gap-8 xl:grid-cols-[1.08fr_0.92fr]">
                    <div>
                        <div className="section-kicker">Dashboard preview</div>
                        <h1 className="mt-5 max-w-4xl text-4xl font-semibold tracking-tight text-gray-900 dark:text-white md:text-5xl">
                            The main dashboard is now reachable as a React route, with the live widget pipeline preserved and wrapped in the preview shell.
                        </h1>
                        <p className="mt-5 max-w-3xl text-base leading-8 text-gray-600 dark:text-gray-300 md:text-lg">
                            This slice deliberately bridges rather than rewrites every widget. React now owns the route, navigation, grouping, and outer layout, while the existing dashboard widgets remain the source of truth for card content. It is a pragmatic seam, and pragmatism is a lovely trait in migrations.
                        </p>
                        <div className="mt-6 flex flex-wrap gap-3">
                            <a
                                href={buildAppPath(bootstrap.basePath, 'dashboard')}
                                className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                            >
                                Compare with the live route
                                <span aria-hidden="true">↗</span>
                            </a>
                            <button
                                type="button"
                                onClick={reload}
                                className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                            >
                                Refresh widget data
                                <span aria-hidden="true">↻</span>
                            </button>
                            <Link
                                to="/roadmap"
                                className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                            >
                                Open the migration roadmap
                                <span aria-hidden="true">→</span>
                            </Link>
                        </div>
                    </div>
                    <div className="rounded-[32px] border border-orange-200 bg-[linear-gradient(135deg,rgba(255,255,255,0.96),rgba(255,244,237,0.96))] p-5 shadow-[0_45px_120px_-45px_rgba(15,23,42,0.65)] dark:border-orange-900/40 dark:bg-[linear-gradient(135deg,rgba(17,24,39,0.94),rgba(49,24,17,0.92))]">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-orange-700 dark:text-orange-200">Why this cut works</div>
                        <div className="mt-4 space-y-3 text-sm leading-7 text-gray-700 dark:text-gray-200">
                            {[
                                'It closes the last major dashboard-style route without waiting for a full widget-by-widget React rewrite.',
                                'The live widget system stays authoritative, which makes this a safe bridge slice after monthly stats.',
                                'React still gains the important wins here: route ownership, app-shell consistency, preview navigation, and cleaner migration boundaries.',
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
                <StatCard label="Widget sections" value={data?.summary.sectionCount ?? 0} hint="Distinct dashboard groupings currently enabled by the live layout." tone="orange" />
                <StatCard label="Visible widgets" value={data?.summary.totalWidgets ?? 0} hint="Rendered cards currently exposed by the live dashboard configuration." tone="emerald" />
                <StatCard label="Bridge mode" value="Live widgets" hint="This route intentionally reuses the existing widget renderers inside the React shell." tone="blue" />
                <StatCard label="Preview refresh" value={data ? formatRequestedAt(data.requestedAt) : 'Waiting…'} hint="Useful when checking rebuilds or auth-related widget output." tone="slate" />
            </section>

            {loading && !data ? (
                <section className="glass-panel rounded-[32px] p-6 text-sm text-gray-600 dark:text-gray-300">
                    Loading dashboard preview… inviting the widget committee into the new shell.
                </section>
            ) : null}

            {error ? (
                <section className="rounded-[32px] border border-rose-200 bg-rose-50/90 p-6 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                    {error}
                </section>
            ) : null}

            {data ? (
                <section className="space-y-8">
                    {data.sections.map((section) => (
                        <section key={section.id} className="glass-panel rounded-[32px] p-6">
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <div className="section-kicker">Dashboard section</div>
                                    <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">{section.label}</h2>
                                </div>
                                <div className="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                    {section.widgets.length} widgets
                                </div>
                            </div>
                            <div className="mt-6 grid grid-cols-1 gap-4 xl:grid-cols-6">
                                {section.widgets.map((widget) => (
                                    <DashboardWidgetCard key={widget.id} widget={widget} />
                                ))}
                            </div>
                        </section>
                    ))}
                </section>
            ) : null}

            <section className="glass-panel rounded-[32px] p-6">
                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <div className="section-kicker">Migration note</div>
                        <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">A deliberate bridge, not a dead end</h2>
                    </div>
                    <Link
                        to="/activities"
                        className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                    >
                        Open a fully typed route
                        <span aria-hidden="true">→</span>
                    </Link>
                </div>
                <div className="mt-5 space-y-3 text-sm leading-7 text-gray-600 dark:text-gray-300">
                    {[
                        'This is the right kind of shortcut: it expands React route coverage while keeping the live dashboard widget internals intact.',
                        'The next dashboard iterations can peel widgets off one by one into typed JSON contracts if and when that becomes worth the effort.',
                        'For now, the migration wins are already real: route parity, cleaner navigation, and one less major live page left outside the preview shell.',
                    ].map((item) => (
                        <div key={item} className="rounded-2xl border border-gray-200 bg-white/80 p-4 dark:border-gray-800 dark:bg-gray-950/30">
                            {item}
                        </div>
                    ))}
                </div>
            </section>
        </div>
    );
}
