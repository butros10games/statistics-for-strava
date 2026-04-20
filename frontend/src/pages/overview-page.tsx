import {Link} from 'react-router-dom';
import {StatCard} from '../components/stat-card';
import {buildAppPath, type ReactPreviewBootstrap} from '../lib/bootstrap';

interface OverviewPageProps {
    bootstrap: ReactPreviewBootstrap;
}

const userImprovements = [
    'Faster-feeling navigation with a stable app shell and route-level rendering.',
    'Consistent loading, error, and modal patterns instead of page-specific behavior.',
    'Better state preservation for filters, sidebars, route context, and multi-step flows.',
];

const developerImprovements = [
    'Vite-powered iteration with hot reload instead of rebuild-heavy asset loops.',
    'Component-level state and route composition instead of DOM manager orchestration.',
    'A clearer path to testing screens, hooks, and route flows in isolation.',
];

export function OverviewPage({bootstrap}: OverviewPageProps) {
    return (
        <div className="space-y-8 pb-8">
            <section className="glass-panel overflow-hidden rounded-[36px] p-6 md:p-8">
                <div className="grid gap-8 xl:grid-cols-[1.4fr_0.95fr]">
                    <div>
                        <div className="section-kicker">Migration workbench</div>
                        <h1 className="mt-5 max-w-3xl text-4xl font-semibold tracking-tight text-gray-900 dark:text-white md:text-5xl">
                            A parallel React shell for the next iteration of Statistics for Strava.
                        </h1>
                        <p className="mt-5 max-w-2xl text-base leading-8 text-gray-600 dark:text-gray-300 md:text-lg">
                            This first implementation slice proves the new frontend can coexist safely with Symfony,
                            preserve current theme and sidebar preferences, and grow route-by-route without breaking the live app.
                        </p>
                        <div className="mt-6 flex flex-wrap gap-3">
                            <Link
                                to="/training-plans"
                                className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                            >
                                Explore the training plans spike
                                <span aria-hidden="true">→</span>
                            </Link>
                            <Link
                                to="/training-plan-editor"
                                className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                            >
                                Open the plan editor route
                                <span aria-hidden="true">→</span>
                            </Link>
                            <a
                                href={buildAppPath(bootstrap.basePath, 'training-plans')}
                                className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                            >
                                Open the current training plans page
                                <span aria-hidden="true">↗</span>
                            </a>
                        </div>
                    </div>
                    <div className="rounded-[32px] border border-orange-200/70 bg-[linear-gradient(135deg,rgba(255,255,255,0.92),rgba(255,244,237,0.96))] p-5 shadow-[0_30px_80px_-50px_rgba(242,103,34,0.55)] dark:border-orange-900/40 dark:bg-[linear-gradient(135deg,rgba(17,24,39,0.94),rgba(49,24,17,0.92))]">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-orange-700 dark:text-orange-300">Implemented now</div>
                        <div className="mt-4 space-y-3 text-sm leading-7 text-gray-700 dark:text-gray-200">
                            <div className="rounded-2xl border border-white/80 bg-white/80 p-4 dark:border-gray-800 dark:bg-gray-950/40">
                                React Router shell with a dedicated Symfony preview route.
                            </div>
                            <div className="rounded-2xl border border-white/80 bg-white/80 p-4 dark:border-gray-800 dark:bg-gray-950/40">
                                Theme + sidebar persistence mapped to the existing localStorage keys.
                            </div>
                            <div className="rounded-2xl border border-white/80 bg-white/80 p-4 dark:border-gray-800 dark:bg-gray-950/40">
                                Shared build pipeline hooks so React assets ship alongside the current frontend.
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Activities" value={bootstrap.counts.activities} hint="Live count from the existing Symfony index bootstrap." tone="orange" />
                <StatCard label="Photos" value={bootstrap.counts.photos} hint="Shows the new app can reuse current top-level context." tone="blue" />
                <StatCard label="Challenges" value={bootstrap.counts.challenges} hint="Useful for nav badges and shared shell metadata." tone="emerald" />
                <StatCard label="Capabilities" value={`${bootstrap.counts.hasGear ? 'Gear' : 'No gear'} · ${bootstrap.counts.hasBestEfforts ? 'Best efforts' : 'No best efforts'}`} hint="Feature flags can move into typed bootstrap/config next." />
            </section>

            <section className="grid gap-6 xl:grid-cols-2">
                <div className="glass-panel rounded-[32px] p-6">
                    <div className="section-kicker">User-facing upside</div>
                    <div className="mt-5 space-y-4">
                        {userImprovements.map((item) => (
                            <div key={item} className="rounded-2xl border border-gray-200 bg-white/80 p-4 text-sm leading-7 text-gray-700 dark:border-gray-800 dark:bg-gray-900/60 dark:text-gray-200">
                                {item}
                            </div>
                        ))}
                    </div>
                </div>
                <div className="glass-panel rounded-[32px] p-6">
                    <div className="section-kicker">Developer-facing upside</div>
                    <div className="mt-5 space-y-4">
                        {developerImprovements.map((item) => (
                            <div key={item} className="rounded-2xl border border-gray-200 bg-white/80 p-4 text-sm leading-7 text-gray-700 dark:border-gray-800 dark:bg-gray-900/60 dark:text-gray-200">
                                {item}
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            <section className="glass-panel rounded-[32px] p-6">
                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <div className="section-kicker">Next implementation slice</div>
                        <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">What this unlocks immediately</h2>
                    </div>
                    <Link
                        to="/roadmap"
                        className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                    >
                        Open the migration roadmap
                        <span aria-hidden="true">→</span>
                    </Link>
                </div>
                <div className="mt-6 grid gap-4 lg:grid-cols-3">
                    {[
                        'Promote the training-plan preview fetch layer into shared route-level query utilities.',
                        'Convert one modal flow end-to-end as a React flow backed by JSON instead of HTML fragments.',
                        'Wrap chart, table, and planner surfaces in reusable route-level components.',
                    ].map((item) => (
                        <div key={item} className="rounded-[28px] border border-gray-200 bg-white/85 p-5 text-sm leading-7 text-gray-700 shadow-sm dark:border-gray-800 dark:bg-gray-900/60 dark:text-gray-200">
                            {item}
                        </div>
                    ))}
                </div>
            </section>
        </div>
    );
}
