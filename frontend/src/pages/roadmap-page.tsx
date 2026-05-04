const phases = [
    {
        title: 'Shell & routing',
        summary: 'App shell, theme persistence, sidebar behavior, and route entry points are all in place.',
        state: 'done',
    },
    {
        title: 'Data-backed screens',
        summary: 'Core routes now read live backend data instead of relying on placeholder content.',
        state: 'done',
    },
    {
        title: 'Visual alignment',
        summary: 'Current work is focused on making each route look much closer to the original frontend.',
        state: 'active',
    },
    {
        title: 'Remaining cleanup',
        summary: 'A few utility surfaces still need consistency passes and copy cleanup.',
        state: 'queued',
    },
];

const completedAreas = [
    'Dashboard widgets and summary layout',
    'Training planner, race planner, and season blocks',
    'Segments, milestones, photos, challenges, and heatmap',
    'Activities table, monthly calendar, rewind, and account tools',
];

const currentFocus = [
    'Remove the last bits of utility copy and one-off buttons that do not belong in the original product feel.',
    'Keep shrinking oversized cards, rounded corners, and showcase styling on the few pages still carrying them.',
    'Preserve the existing backend-driven behavior while tightening the route shells around it.',
];

const nextPasses = [
    'Finish any remaining utility pages that still read like scaffolding instead of product UI.',
    'Do another consistency sweep across headers, pills, empty states, and button treatments.',
    'Keep validating after each pass so the frontend stays production-build clean.',
];

function stateClasses(state: string): string {
    switch (state) {
        case 'done':
            return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-100';
        case 'active':
            return 'border-orange-200 bg-orange-50 text-orange-700 dark:border-orange-900/50 dark:bg-orange-950/30 dark:text-orange-100';
        default:
            return 'border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-800 dark:bg-gray-900/40 dark:text-gray-300';
    }
}

export function RoadmapPage() {
    return (
        <div className="space-y-6 pb-6">
            <section className="ui-section">
                <h1 className="text-[1.8rem] font-bold tracking-tight text-gray-900 dark:text-white">Roadmap</h1>
                <p className="mt-1 max-w-3xl text-[13px] text-gray-500 dark:text-gray-400">
                    Internal status page for what is already covered in the app shell, what is being polished now, and what cleanup is still queued.
                </p>
            </section>

            <section className="grid gap-3 lg:grid-cols-2 xl:grid-cols-4">
                {phases.map((phase) => (
                    <article key={phase.title} className="ui-section">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-sm font-semibold text-gray-900 dark:text-white">{phase.title}</h2>
                            <span className={`rounded-full border px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide ${stateClasses(phase.state)}`}>
                                {phase.state}
                            </span>
                        </div>
                        <p className="mt-2 text-[13px] leading-6 text-gray-600 dark:text-gray-300">{phase.summary}</p>
                    </article>
                ))}
            </section>

            <section className="grid gap-4 xl:grid-cols-2">
                <div className="ui-section">
                    <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Completed areas</h2>
                    <div className="mt-3 space-y-2">
                        {completedAreas.map((item) => (
                            <div key={item} className="rounded-lg border border-gray-200 bg-gray-50 p-3 text-[13px] leading-6 text-gray-700 dark:border-gray-800 dark:bg-gray-900/50 dark:text-gray-200">
                                {item}
                            </div>
                        ))}
                    </div>
                </div>
                <div className="ui-section">
                    <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Current focus</h2>
                    <div className="mt-3 space-y-2">
                        {currentFocus.map((item) => (
                            <div key={item} className="rounded-lg border border-gray-200 bg-gray-50 p-3 text-[13px] leading-6 text-gray-700 dark:border-gray-800 dark:bg-gray-900/50 dark:text-gray-200">
                                {item}
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            <section className="ui-section">
                <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Next passes</h2>
                <div className="mt-3 grid gap-2.5 lg:grid-cols-3">
                    {nextPasses.map((item) => (
                        <div key={item} className="rounded-lg border border-gray-200 bg-gray-50 p-3 text-[13px] leading-6 text-gray-700 dark:border-gray-800 dark:bg-gray-900/50 dark:text-gray-200">
                                {item}
                        </div>
                    ))}
                </div>
            </section>
        </div>
    );
}
