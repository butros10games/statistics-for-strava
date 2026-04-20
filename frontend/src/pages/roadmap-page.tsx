const phases = [
    {
        title: 'Foundation',
        summary: 'React shell, route boundary, build tooling, and typed bootstrap wiring.',
    },
    {
        title: 'Data contracts',
        summary: 'Map reusable /api outputs, define fetch clients, and add missing bootstrap + CSRF helpers.',
    },
    {
        title: 'Route migration',
        summary: 'Port dashboard, training plans, calendar, and planner surfaces incrementally.',
    },
    {
        title: 'Interactive modules',
        summary: 'Replace chart managers, map flows, modal forms, and table virtualization route by route.',
    },
];

export function RoadmapPage() {
    return (
        <div className="space-y-8 pb-8">
            <section className="glass-panel rounded-[36px] p-6 md:p-8">
                <div className="section-kicker">Roadmap</div>
                <h1 className="mt-5 text-4xl font-semibold tracking-tight text-gray-900 dark:text-white md:text-5xl">
                    The migration stays boring on purpose: coexist, verify, then replace.
                </h1>
                <p className="mt-5 max-w-3xl text-base leading-8 text-gray-600 dark:text-gray-300 md:text-lg">
                    The safest path is to let the new React frontend earn its way into production a route family at a time,
                    while Symfony keeps providing authentication, domain logic, and the current live experience.
                </p>
            </section>

            <section className="grid gap-4 lg:grid-cols-2 xl:grid-cols-4">
                {phases.map((phase, index) => (
                    <article key={phase.title} className="glass-panel rounded-[30px] p-5">
                        <div className="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-gray-900 text-sm font-semibold text-white dark:bg-white dark:text-gray-950">
                            {index + 1}
                        </div>
                        <h2 className="mt-5 text-2xl font-semibold text-gray-900 dark:text-white">{phase.title}</h2>
                        <p className="mt-3 text-sm leading-7 text-gray-600 dark:text-gray-300">{phase.summary}</p>
                    </article>
                ))}
            </section>

            <section className="grid gap-6 xl:grid-cols-2">
                <div className="glass-panel rounded-[32px] p-6">
                    <div className="section-kicker">Implemented in this PR-sized slice</div>
                    <div className="mt-5 space-y-4">
                        {[
                            'A dedicated `/react-preview/*` route served by Symfony so the new app can be explored safely.',
                            'Vite + React + TypeScript foundation with compiled assets published into `public/react/dist`.',
                            'A native-feeling shell with persistent theme + sidebar state and base-path aware legacy links.',
                        ].map((item) => (
                            <div key={item} className="rounded-2xl border border-gray-200 bg-white/80 p-4 text-sm leading-7 text-gray-700 dark:border-gray-800 dark:bg-gray-900/60 dark:text-gray-200">
                                {item}
                            </div>
                        ))}
                    </div>
                </div>
                <div className="glass-panel rounded-[32px] p-6">
                    <div className="section-kicker">Immediate next steps</div>
                    <div className="mt-5 space-y-4">
                        {[
                            'Expand the typed API/bootstrap layer for current-user info, translations, and feature flags.',
                            'Rebuild one modal form end-to-end as a React flow backed by JSON.',
                            'Use the training-plans preview slice as the pattern for dashboard, calendar, and planner route families.',
                        ].map((item) => (
                            <div key={item} className="rounded-2xl border border-gray-200 bg-white/80 p-4 text-sm leading-7 text-gray-700 dark:border-gray-800 dark:bg-gray-900/60 dark:text-gray-200">
                                {item}
                            </div>
                        ))}
                    </div>
                </div>
            </section>
        </div>
    );
}
