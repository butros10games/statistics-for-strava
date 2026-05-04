import {useCallback} from 'react';
import {Link} from 'react-router-dom';
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
        <article className={`${widgetWidthClass(widget.width)} flex flex-col rounded-lg border border-gray-200 bg-white p-4 shadow-xs transition-shadow duration-150 hover:shadow-sm dark:border-gray-800 dark:bg-gray-900`}>
            <div dangerouslySetInnerHTML={{__html: widget.html}} />
        </article>
    );
}

function SummaryTile({label, value, note}: {label: string; value: string | number; note: string}) {
    return (
        <div className="metric-card">
            <div className="text-[10px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">{label}</div>
            <div className="mt-1 text-[1.1rem] font-semibold text-gray-900 dark:text-white">{value}</div>
            <div className="mt-1 text-[11px] leading-4 text-gray-500 dark:text-gray-400">{note}</div>
        </div>
    );
}

export function DashboardPage({bootstrap}: DashboardPageProps) {
    const isPreview = bootstrap.experience === 'preview';
    const loadDashboard = useCallback(
        (signal: AbortSignal): Promise<DashboardPreviewResponse> => fetchDashboardPreview(bootstrap.basePath, signal),
        [bootstrap.basePath],
    );

    const {data, loading, error, reload} = useAsyncResource(loadDashboard);

    return (
        <div className="space-y-6 pb-6">
            <section className="ui-section">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="min-w-0">
                    <h1 className="text-[1.8rem] font-bold tracking-tight text-gray-900 dark:text-white">Dashboard</h1>
                    <p className="mt-1 text-[13px] text-gray-500 dark:text-gray-400">Your training widgets, grouped the same way as the original dashboard.</p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <a href={buildAppPath(bootstrap.basePath, 'dashboard')} className="ui-button">
                        Open classic dashboard
                    </a>
                    <button type="button" onClick={reload} className="ui-button">
                        Refresh widget data
                    </button>
                    <Link to="/recovery-check-in" className="ui-button">
                        Recovery check-in
                    </Link>
                </div>
                </div>
            </section>

            <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <SummaryTile
                    label="Sections"
                    value={data?.summary.sectionCount ?? 0}
                    note="Distinct dashboard groups enabled by the live layout."
                />
                <SummaryTile
                    label="Visible widgets"
                    value={data?.summary.totalWidgets ?? 0}
                    note="Cards currently rendered by the live widget pipeline."
                />
                <SummaryTile
                    label="Widget source"
                    value="Live widgets"
                    note="The original widget renderers still drive the card content here."
                />
                <SummaryTile
                    label="Last refresh"
                    value={data ? formatRequestedAt(data.requestedAt) : 'Waiting…'}
                    note="Handy when checking fresh data or rebuilds."
                />
            </section>

            {loading && !data ? (
                <section className="ui-section text-sm text-gray-600 dark:text-gray-300">
                    Loading dashboard widgets…
                </section>
            ) : null}

            {error ? (
                <section className="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/30 dark:text-rose-100">
                    {error}
                </section>
            ) : null}

            {data ? (
                <section className="space-y-6">
                    {data.sections.map((section, index) => (
                        <section key={section.id}>
                            <div className={`mb-2 ${index > 0 ? 'mt-6' : ''}`}>
                                <h2 className="text-sm font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">{section.label}</h2>
                            </div>
                            <div className="grid grid-cols-1 gap-4 xl:grid-cols-6">
                                {section.widgets.map((widget) => (
                                    <DashboardWidgetCard key={widget.id} widget={widget} />
                                ))}
                            </div>
                        </section>
                    ))}
                </section>
            ) : null}
        </div>
    );
}
