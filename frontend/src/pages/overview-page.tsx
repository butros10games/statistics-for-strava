import type {ReactNode} from 'react';
import {Link} from 'react-router-dom';
import {buildAppPath, type ReactPreviewBootstrap} from '../lib/bootstrap';

interface OverviewPageProps {
    bootstrap: ReactPreviewBootstrap;
}

interface InternalShortcut {
    label: string;
    to: string;
    note: string;
    badge?: string | number;
}

interface ExternalShortcut {
    label: string;
    href: string;
    note: string;
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

function ShortcutList({title, description, children}: {title: string; description: string; children: ReactNode}) {
    return (
        <section className="ui-section">
            <div>
                <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">{title}</h2>
                <p className="mt-1 text-sm leading-6 text-gray-500 dark:text-gray-400">{description}</p>
            </div>
            <div className="mt-4 space-y-2">{children}</div>
        </section>
    );
}

function InternalShortcutRow({item}: {item: InternalShortcut}) {
    return (
        <Link
            to={item.to}
            className="flex items-start justify-between gap-3 rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm transition hover:border-gray-300 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:hover:border-gray-700 dark:hover:bg-gray-800"
        >
            <div className="min-w-0">
                <div className="font-medium text-gray-900 dark:text-white">{item.label}</div>
                <div className="mt-0.5 text-[11px] leading-4 text-gray-500 dark:text-gray-400">{item.note}</div>
            </div>
            <div className="flex shrink-0 items-center gap-2">
                {item.badge ? <span className="ui-pill">{item.badge}</span> : null}
                <span className="text-gray-400 dark:text-gray-500">→</span>
            </div>
        </Link>
    );
}

function ExternalShortcutRow({item}: {item: ExternalShortcut}) {
    return (
        <a
            href={item.href}
            className="flex items-start justify-between gap-3 rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm transition hover:border-gray-300 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:hover:border-gray-700 dark:hover:bg-gray-800"
        >
            <div className="min-w-0">
                <div className="font-medium text-gray-900 dark:text-white">{item.label}</div>
                <div className="mt-0.5 text-[11px] leading-4 text-gray-500 dark:text-gray-400">{item.note}</div>
            </div>
            <span className="shrink-0 text-gray-400 dark:text-gray-500">↗</span>
        </a>
    );
}

export function OverviewPage({bootstrap}: OverviewPageProps) {
    const featureBadges = [
        bootstrap.counts.hasGear ? 'Gear enabled' : null,
        bootstrap.counts.hasBestEfforts ? 'Best efforts enabled' : null,
        bootstrap.athlete.name,
    ].filter(Boolean) as string[];

    const trainingShortcuts: InternalShortcut[] = [
        {
            label: 'Plan manager',
            to: '/training-plans',
            note: 'Manage season plans, race builds, and continuity checks.',
        },
        {
            label: 'Race planner',
            to: '/race-planner',
            note: 'Review goal races, projections, and planner recommendations.',
        },
        {
            label: 'Training calendar',
            to: '/monthly-stats',
            note: 'Open the calendar-style training overview.',
        },
        {
            label: 'Training blocks',
            to: '/training-blocks',
            note: 'Edit compact season blocks and supporting notes.',
        },
    ];

    const analysisShortcuts: InternalShortcut[] = [
        {
            label: 'Dashboard',
            to: '/dashboard',
            note: 'Open the live widget dashboard inside the app shell.',
        },
        {
            label: 'Heatmap',
            to: '/heatmap',
            note: 'Inspect route coverage on the simplified map-first screen.',
        },
        {
            label: 'Rewind',
            to: '/rewind',
            note: 'Browse yearly recap cards and mixed-media summaries.',
        },
        {
            label: 'Segments',
            to: '/segments',
            note: 'Search segments and drill into effort history.',
        },
    ];

    const libraryShortcuts: InternalShortcut[] = [
        {
            label: 'Photos',
            to: '/photos',
            note: 'Browse the gallery wall with lightweight filters.',
            badge: bootstrap.counts.photos,
        },
        {
            label: 'Challenges',
            to: '/challenges',
            note: 'Review challenge badges grouped by month.',
            badge: bootstrap.counts.challenges,
        },
        {
            label: 'Badges',
            to: '/badges',
            note: 'Copy badge embeds and export snippets.',
        },
        {
            label: 'Account settings',
            to: '/account/settings',
            note: 'Check sync status and linked service connections.',
        },
    ];

    const classicShortcuts: ExternalShortcut[] = [
        {
            label: 'Classic dashboard',
            href: buildAppPath(bootstrap.basePath, 'dashboard'),
            note: 'Jump back to the original dashboard route.',
        },
        {
            label: 'Classic plan manager',
            href: buildAppPath(bootstrap.basePath, 'training-plans'),
            note: 'Open the current Symfony training plans page.',
        },
        {
            label: 'AI chat',
            href: buildAppPath(bootstrap.basePath, 'ai/chat'),
            note: 'Open the existing assistant route outside this shell.',
        },
    ];

    return (
        <div className="space-y-6 pb-6">
            <section className="ui-section">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="min-w-0">
                    <h1 className="text-[1.8rem] font-bold tracking-tight text-gray-900 dark:text-white">Overview</h1>
                    <p className="mt-1 max-w-3xl text-[13px] text-gray-500 dark:text-gray-400">
                        Workspace launcher for the app shell, with quick access to the routes that already feel closest to the main product.
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Link to="/dashboard" className="ui-button ui-button-primary">
                        Open dashboard
                    </Link>
                    <Link to="/training-plans" className="ui-button">
                        Open plan manager
                    </Link>
                    <a href={buildAppPath(bootstrap.basePath, 'dashboard')} className="ui-button">
                        Classic dashboard
                    </a>
                </div>
                </div>
            </section>

            <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <SummaryTile
                    label="Activities"
                    value={bootstrap.counts.activities}
                    note="Live count from the current bootstrap context."
                />
                <SummaryTile
                    label="Photos"
                    value={bootstrap.counts.photos}
                    note="Available gallery items in the current account."
                />
                <SummaryTile
                    label="Challenges"
                    value={bootstrap.counts.challenges}
                    note="Badge wall entries and challenge history."
                />
                <SummaryTile
                    label="Workspace"
                    value={bootstrap.experience === 'preview' ? 'Preview' : 'App'}
                    note="Theme and sidebar behavior stay aligned with the main app."
                />
            </section>

            <section className="ui-section">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Session snapshot</h2>
                        <p className="mt-1 text-sm leading-6 text-gray-500 dark:text-gray-400">
                            The app shell reuses your current athlete context and available feature flags.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {featureBadges.map((badge) => (
                            <span key={badge} className="ui-pill">
                                {badge}
                            </span>
                        ))}
                    </div>
                </div>
            </section>

            <section className="grid gap-3 xl:grid-cols-2">
                <ShortcutList title="Training" description="Season planning and calendar-heavy routes.">
                    {trainingShortcuts.map((item) => (
                        <InternalShortcutRow key={item.to} item={item} />
                    ))}
                </ShortcutList>

                <ShortcutList title="Analysis" description="Routes centered on metrics, maps, and recap views.">
                    {analysisShortcuts.map((item) => (
                        <InternalShortcutRow key={item.to} item={item} />
                    ))}
                </ShortcutList>

                <ShortcutList title="Library & tools" description="Media, badges, and account-focused utility screens.">
                    {libraryShortcuts.map((item) => (
                        <InternalShortcutRow key={item.to} item={item} />
                    ))}
                </ShortcutList>

                <ShortcutList title="Classic routes" description="Fallback links to the original Symfony pages.">
                    {classicShortcuts.map((item) => (
                        <ExternalShortcutRow key={item.href} item={item} />
                    ))}
                </ShortcutList>
            </section>
        </div>
    );
}
