import type {ReactNode} from 'react';
import {NavLink, type NavLinkRenderProps} from 'react-router-dom';
import {buildAppPath, type ReactPreviewBootstrap} from '../lib/bootstrap';

interface AppShellProps {
    bootstrap: ReactPreviewBootstrap;
    sidebarCollapsed: boolean;
    darkMode: boolean;
    onToggleSidebar: () => void;
    onToggleDarkMode: () => void;
    children: ReactNode;
}

const previewLinks = [
    {to: '/', label: 'Workbench', description: 'Foundation slice', icon: '◫'},
    {to: '/activities', label: 'Activities', description: 'Read-heavy preview', icon: '◌'},
    {to: '/badges', label: 'Badges', description: 'Embeddable SVG kit', icon: '▤'},
    {to: '/best-efforts', label: 'Best efforts', description: 'Records matrix', icon: '◍'},
    {to: '/challenges', label: 'Challenges', description: 'Badge archive', icon: '◐'},
    {to: '/eddington', label: 'Eddington', description: 'Chart-heavy preview', icon: '◎'},
    {to: '/gear', label: 'Gear', description: 'Equipment analytics', icon: '⬡'},
    {to: '/heatmap', label: 'Heatmap', description: 'Route map explorer', icon: '◔'},
    {to: '/milestones', label: 'Milestones', description: 'Achievement timeline', icon: '✦'},
    {to: '/photos', label: 'Photos', description: 'Gallery wall', icon: '▣'},
    {to: '/rewind', label: 'Rewind', description: 'Yearly recap deck', icon: '◒'},
    {to: '/segments', label: 'Segments', description: 'Filtered climb browser', icon: '◈'},
    {to: '/race-planner', label: 'Race planner', description: 'Live route spike', icon: '◭'},
    {to: '/training-plans', label: 'Training plans', description: 'Route spike', icon: '⟠'},
    {to: '/roadmap', label: 'Roadmap', description: 'Migration track', icon: '⋯'},
];

function previewLinkClass({isActive}: NavLinkRenderProps) {
    return `sidebar-link ${isActive ? 'sidebar-link-active' : 'hover:bg-gray-100/90 hover:text-gray-900 dark:hover:bg-gray-800 dark:hover:text-white'}`;
}

function iconWrapClass(isActive: boolean) {
    return isActive
        ? 'bg-white/15 text-white dark:bg-gray-950/10 dark:text-gray-950'
        : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-300';
}

export function AppShell({
    bootstrap,
    sidebarCollapsed,
    darkMode,
    onToggleSidebar,
    onToggleDarkMode,
    children,
}: AppShellProps) {
    const legacyLinks = [
        {href: buildAppPath(bootstrap.basePath, 'dashboard'), label: 'Legacy dashboard'},
        {href: buildAppPath(bootstrap.basePath, 'activities'), label: 'Legacy activities'},
        {href: buildAppPath(bootstrap.basePath, 'badge.html'), label: 'Legacy badges'},
        {href: buildAppPath(bootstrap.basePath, 'best-efforts'), label: 'Legacy best efforts'},
        {href: buildAppPath(bootstrap.basePath, 'challenges'), label: 'Legacy challenges'},
        {href: buildAppPath(bootstrap.basePath, 'eddington'), label: 'Legacy Eddington'},
        {href: buildAppPath(bootstrap.basePath, 'gear'), label: 'Legacy gear'},
        {href: buildAppPath(bootstrap.basePath, 'heatmap'), label: 'Legacy heatmap'},
        {href: buildAppPath(bootstrap.basePath, 'milestones'), label: 'Legacy milestones'},
        {href: buildAppPath(bootstrap.basePath, 'photos'), label: 'Legacy photos'},
        {href: buildAppPath(bootstrap.basePath, 'rewind'), label: 'Legacy rewind'},
        {href: buildAppPath(bootstrap.basePath, 'segments'), label: 'Legacy segments'},
        {href: buildAppPath(bootstrap.basePath, 'training-plans'), label: 'Legacy training plans'},
        {href: buildAppPath(bootstrap.basePath, 'race-planner'), label: 'Legacy race planner'},
    ];

    return (
        <div className="relative min-h-screen overflow-hidden dot-grid-bg">
            <div className="pointer-events-none fixed inset-0 bg-[radial-gradient(circle_at_top_left,rgba(242,103,34,0.18),transparent_32%),radial-gradient(circle_at_80%_10%,rgba(15,23,42,0.07),transparent_28%)] dark:bg-[radial-gradient(circle_at_top_left,rgba(242,103,34,0.18),transparent_35%),radial-gradient(circle_at_80%_10%,rgba(249,115,22,0.08),transparent_28%)]" />
            <header className="fixed inset-x-0 top-0 z-40 border-b border-white/70 bg-white/85 backdrop-blur-xl dark:border-gray-800 dark:bg-gray-950/85">
                <div className="mx-auto flex max-w-[1600px] items-center justify-between gap-3 px-4 py-3 md:px-6">
                    <div className="flex min-w-0 items-center gap-3">
                        <button
                            type="button"
                            onClick={onToggleSidebar}
                            className="inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-gray-200 bg-white text-gray-600 shadow-sm transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:border-gray-600 dark:hover:text-white"
                            aria-label={sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'}
                        >
                            <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                        </button>
                        <div className="flex min-w-0 items-center gap-3 rounded-3xl border border-white/60 bg-white/75 px-3 py-2 shadow-sm shadow-orange-100/60 dark:border-gray-800 dark:bg-gray-900/70 dark:shadow-none">
                            <img
                                src={buildAppPath(bootstrap.basePath, 'assets/images/logo.svg')}
                                alt="Tempo"
                                className="h-10 w-10 rounded-2xl border border-orange-200 bg-white p-1.5 dark:border-orange-900/50 dark:bg-gray-950"
                            />
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="truncate text-sm font-semibold uppercase tracking-[0.28em] text-gray-500 dark:text-gray-400">Tempo migration</span>
                                    <span className="rounded-full border border-orange-200 bg-orange-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.24em] text-orange-700 dark:border-orange-900/50 dark:bg-orange-950/50 dark:text-orange-200">
                                        React preview
                                    </span>
                                </div>
                                <div className="truncate text-lg font-semibold text-gray-900 dark:text-white">
                                    {bootstrap.appName}
                                    {bootstrap.subtitle ? <span className="text-gray-400 dark:text-gray-500"> · {bootstrap.subtitle}</span> : null}
                                </div>
                            </div>
                        </div>
                    </div>
                    <div className="flex items-center gap-3">
                        <button
                            type="button"
                            onClick={onToggleDarkMode}
                            className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                        >
                            <span className="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-orange-200">
                                {darkMode ? '☾' : '☀'}
                            </span>
                            <span className="hidden sm:inline">{darkMode ? 'Dark' : 'Light'} mode</span>
                        </button>
                        <div className="flex items-center gap-3 rounded-2xl border border-gray-200 bg-white px-3 py-2 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                            {bootstrap.profilePictureUrl ? (
                                <img src={bootstrap.profilePictureUrl} alt={bootstrap.athlete.name} className="h-10 w-10 rounded-2xl object-cover" />
                            ) : (
                                <div className="flex h-10 w-10 items-center justify-center rounded-2xl bg-strava-orange font-semibold text-white">
                                    {bootstrap.athlete.initial}
                                </div>
                            )}
                            <div className="hidden text-sm sm:block">
                                <div className="font-medium text-gray-900 dark:text-white">{bootstrap.athlete.name}</div>
                                <div className="text-gray-500 dark:text-gray-400">Parallel app shell</div>
                            </div>
                        </div>
                    </div>
                </div>
            </header>
            <aside className="fixed left-0 top-0 z-30 h-screen w-72 border-r border-white/60 bg-white/90 px-4 pb-6 pt-24 backdrop-blur-xl transition-[width] duration-300 dark:border-gray-800 dark:bg-gray-950/88 sidebar-collapsed:w-24">
                <div className="flex h-full flex-col gap-6 overflow-hidden">
                    <div className="glass-panel rounded-[28px] p-4 sidebar-collapsed:px-2">
                        <div className="section-kicker sidebar-collapsed:hidden">Preview routes</div>
                        <nav className="mt-4 flex flex-col gap-2">
                            {previewLinks.map((link) => (
                                <NavLink key={link.to} to={link.to} end={link.to === '/'} className={previewLinkClass}>
                                    {({isActive}) => (
                                        <>
                                            <span className={`inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl text-lg transition ${iconWrapClass(isActive)}`}>
                                                {link.icon}
                                            </span>
                                            <span className="min-w-0 sidebar-collapsed:hidden">
                                                <span className="block truncate text-sm font-semibold">{link.label}</span>
                                                <span className={`block truncate text-xs ${isActive ? 'text-white/75 dark:text-gray-800' : 'text-gray-500 dark:text-gray-400'}`}>
                                                    {link.description}
                                                </span>
                                            </span>
                                        </>
                                    )}
                                </NavLink>
                            ))}
                        </nav>
                    </div>

                    <div className="glass-panel rounded-[28px] p-4 sidebar-collapsed:px-2">
                        <div className="section-kicker sidebar-collapsed:hidden">Legacy app</div>
                        <div className="mt-4 flex flex-col gap-2">
                            {legacyLinks.map((link) => (
                                <a
                                    key={link.href}
                                    href={link.href}
                                    className="sidebar-link hover:bg-gray-100/90 hover:text-gray-900 dark:hover:bg-gray-800 dark:hover:text-white"
                                >
                                    <span className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-300">↗</span>
                                    <span className="truncate sidebar-collapsed:hidden">{link.label}</span>
                                </a>
                            ))}
                        </div>
                    </div>

                    <div className="mt-auto glass-panel rounded-[28px] p-4 sidebar-collapsed:hidden">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Live context</div>
                        <div className="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div className="rounded-2xl border border-gray-200 bg-white/80 p-3 dark:border-gray-800 dark:bg-gray-900/60">
                                <div className="text-gray-500 dark:text-gray-400">Activities</div>
                                <div className="mt-1 text-xl font-semibold text-gray-900 dark:text-white">{bootstrap.counts.activities}</div>
                            </div>
                            <div className="rounded-2xl border border-gray-200 bg-white/80 p-3 dark:border-gray-800 dark:bg-gray-900/60">
                                <div className="text-gray-500 dark:text-gray-400">Photos</div>
                                <div className="mt-1 text-xl font-semibold text-gray-900 dark:text-white">{bootstrap.counts.photos}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </aside>
            <main className="min-h-screen px-4 pb-10 pt-24 transition-[padding] duration-300 md:pl-[19rem] md:pr-6 sidebar-collapsed:md:pl-[7rem]">
                <div className="mx-auto max-w-7xl">{children}</div>
            </main>
        </div>
    );
}
