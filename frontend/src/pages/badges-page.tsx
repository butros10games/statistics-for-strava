import {useCallback, useEffect, useMemo, useState} from 'react';
import {Link} from 'react-router-dom';
import {StatCard} from '../components/stat-card';
import {buildAppPath, type ReactPreviewBootstrap} from '../lib/bootstrap';
import {
    fetchBadgesPreview,
    type BadgePreviewSection,
    type BadgesPreviewResponse,
} from '../lib/badges-preview-api';
import {useAsyncResource} from '../lib/use-async-resource';

interface BadgesPageProps {
    bootstrap: ReactPreviewBootstrap;
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

function resolveUrl(basePath: string, url: string): string {
    return url.startsWith('http://') || url.startsWith('https://')
        ? url
        : buildAppPath(basePath, url);
}

function BadgeCard({
    bootstrap,
    badge,
    isCopied,
    onCopy,
}: {
    bootstrap: ReactPreviewBootstrap;
    badge: BadgePreviewSection['badges'][number];
    isCopied: boolean;
    onCopy: (embedCode: string, badgeId: string) => void;
}) {
    return (
        <article className="glass-panel overflow-hidden rounded-[30px] p-5">
            <div className="rounded-[24px] border border-gray-200 bg-white/85 p-4 dark:border-gray-800 dark:bg-gray-950/35">
                <div className="flex min-h-[16rem] items-center justify-center overflow-hidden rounded-[20px] bg-[radial-gradient(circle_at_top_left,rgba(242,103,34,0.14),transparent_35%),radial-gradient(circle_at_80%_10%,rgba(15,23,42,0.08),transparent_25%)] p-4 dark:bg-[radial-gradient(circle_at_top_left,rgba(242,103,34,0.2),transparent_34%),radial-gradient(circle_at_80%_10%,rgba(249,115,22,0.08),transparent_28%)]">
                    <img src={resolveUrl(bootstrap.basePath, badge.imageUrl)} alt={badge.name} className="max-h-72 max-w-full" loading="lazy" />
                </div>
                <div className="mt-4">
                    <div className="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">{badge.category}</div>
                    <h3 className="mt-2 text-xl font-semibold text-gray-900 dark:text-white">{badge.name}</h3>
                </div>
            </div>
            <div className="mt-4 rounded-[24px] border border-gray-200 bg-gray-950 px-4 py-4 text-xs leading-6 text-gray-100 dark:border-gray-800">
                <code className="block break-all">{badge.embedCode}</code>
            </div>
            <div className="mt-4 flex flex-wrap gap-3">
                <button
                    type="button"
                    onClick={() => onCopy(badge.embedCode, badge.id)}
                    className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                >
                    {isCopied ? 'Copied snippet' : 'Copy embed code'}
                    <span aria-hidden="true">{isCopied ? '✓' : '⧉'}</span>
                </button>
                <a
                    href={badge.absoluteUrl}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                >
                    Open raw SVG
                    <span aria-hidden="true">↗</span>
                </a>
            </div>
        </article>
    );
}

export function BadgesPage({bootstrap}: BadgesPageProps) {
    const loadBadges = useCallback(
        (signal: AbortSignal): Promise<BadgesPreviewResponse> => fetchBadgesPreview(bootstrap.basePath, signal),
        [bootstrap.basePath],
    );

    const {data, loading, error, reload} = useAsyncResource(loadBadges);
    const [activeSectionId, setActiveSectionId] = useState<string>('');
    const [copiedBadgeId, setCopiedBadgeId] = useState<string | null>(null);

    useEffect(() => {
        if (!data?.sections.length) {
            return;
        }

        if (!activeSectionId || !data.sections.some((section) => section.id === activeSectionId)) {
            setActiveSectionId(data.sections[0].id);
        }
    }, [activeSectionId, data?.sections]);

    const activeSection = useMemo(
        () => data?.sections.find((section) => section.id === activeSectionId) ?? data?.sections[0] ?? null,
        [activeSectionId, data?.sections],
    );

    function handleCopy(embedCode: string, badgeId: string) {
        if (!navigator.clipboard) {
            return;
        }

        void navigator.clipboard.writeText(embedCode).then(() => {
            setCopiedBadgeId(badgeId);
            window.setTimeout(() => {
                setCopiedBadgeId((current) => current === badgeId ? null : current);
            }, 1800);
        });
    }

    return (
        <div className="space-y-8 pb-8">
            <section className="glass-panel overflow-hidden rounded-[36px] p-6 md:p-8">
                <div className="grid gap-8 xl:grid-cols-[1.08fr_0.92fr]">
                    <div>
                        <div className="section-kicker">Badges preview</div>
                        <h1 className="mt-5 max-w-3xl text-4xl font-semibold tracking-tight text-gray-900 dark:text-white md:text-5xl">
                            A tiny route with outsized charm: embeddable SVG badges, now surfaced as a proper React gallery instead of a tucked-away modal.
                        </h1>
                        <p className="mt-5 max-w-2xl text-base leading-8 text-gray-600 dark:text-gray-300 md:text-lg">
                            This is a deliberately small seam. The data is simple, the assets already exist, and the preview mostly needs to turn a modal-centric experience into a route that feels polished, browsable, and easier to share.
                        </p>
                        <div className="mt-6 flex flex-wrap gap-3">
                            <a
                                href={buildAppPath(bootstrap.basePath, 'badge.html')}
                                className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                            >
                                Open the current badges modal page
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
                    <div className="rounded-[32px] border border-cyan-200 bg-[linear-gradient(135deg,rgba(255,255,255,0.96),rgba(236,254,255,0.96))] p-5 shadow-[0_45px_120px_-45px_rgba(15,23,42,0.65)] dark:border-cyan-900/40 dark:bg-[linear-gradient(135deg,rgba(17,24,39,0.94),rgba(8,47,73,0.55))]">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-700 dark:text-cyan-200">Why this one next</div>
                        <div className="mt-4 space-y-3 text-sm leading-7 text-gray-700 dark:text-gray-200">
                            {[
                                'It is read-only, self-contained, and built on files that already exist in the current app.',
                                'Small seams like this keep migration velocity high between larger routes such as rewind and future detail pages.',
                                'It also proves the preview can absorb modal-oriented UI and promote it into a cleaner route-level experience.',
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
                <StatCard label="Badge variants" value={formatNumber(data?.summary.totalBadges ?? 0)} hint="All embeddable SVG badge variants currently exposed by the preview." tone="orange" />
                <StatCard label="Badge groups" value={formatNumber(data?.summary.categoryCount ?? 0)} hint="User, PB, and Zwift sections currently available." tone="blue" />
                <StatCard label="PB variants" value={formatNumber(data?.summary.personalBestBadgeCount ?? 0)} hint="Personal-best badge variants generated from supported sport types." tone="emerald" />
                <StatCard label="Zwift badge" value={data?.summary.hasZwiftBadge ? 'Available' : 'Unavailable'} hint="Whether the current account has a generated Zwift badge variant." tone="slate" />
            </section>

            <section className="glass-panel rounded-[32px] p-6">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div>
                        <div className="section-kicker">Badge sets</div>
                        <p className="mt-4 max-w-3xl text-sm leading-7 text-gray-600 dark:text-gray-300">
                            The legacy experience uses tabs inside a modal. The preview promotes those groupings into explicit route state with larger previews and ready-to-copy embed code.
                        </p>
                    </div>
                    <div className="text-sm text-gray-500 dark:text-gray-400">
                        {data ? `Preview data refreshed ${formatRequestedAt(data.requestedAt)}.` : 'Waiting for badges preview data.'}
                    </div>
                </div>

                <div className="mt-6 flex flex-wrap gap-2">
                    {(data?.sections ?? []).map((section) => {
                        const isActive = section.id === activeSection?.id;

                        return (
                            <button
                                key={section.id}
                                type="button"
                                onClick={() => setActiveSectionId(section.id)}
                                className={`rounded-full border px-4 py-2 text-sm font-medium transition ${isActive
                                    ? 'border-strava-orange bg-orange-50 text-orange-700 dark:border-orange-400 dark:bg-orange-950/40 dark:text-orange-200'
                                    : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:text-white'}`}
                            >
                                {section.label} ({formatNumber(section.badgesCount)})
                            </button>
                        );
                    })}
                </div>
            </section>

            {loading && !data ? (
                <section className="glass-panel rounded-[32px] p-6 text-sm text-gray-600 dark:text-gray-300">
                    Loading badges preview… dusting off the SVG trophy case.
                </section>
            ) : null}

            {error ? (
                <section className="rounded-[32px] border border-rose-200 bg-rose-50/90 p-6 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                    {error}
                </section>
            ) : null}

            {activeSection ? (
                <section className="space-y-6">
                    <div className="glass-panel rounded-[32px] p-6">
                        <div className="section-kicker">Current section</div>
                        <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">{activeSection.label}</h2>
                        <p className="mt-3 max-w-3xl text-sm leading-7 text-gray-600 dark:text-gray-300">{activeSection.description}</p>
                    </div>
                    <div className="grid gap-6 xl:grid-cols-2">
                        {activeSection.badges.map((badge) => (
                            <BadgeCard
                                key={badge.id}
                                bootstrap={bootstrap}
                                badge={badge}
                                isCopied={copiedBadgeId === badge.id}
                                onCopy={handleCopy}
                            />
                        ))}
                    </div>
                </section>
            ) : null}

            <section className="glass-panel rounded-[32px] p-6">
                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <div className="section-kicker">Migration note</div>
                        <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">A small route that keeps momentum high</h2>
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
                        'Badges is a tidy proof that not every migrated route needs a giant payload or complex state machine to be worth doing.',
                        'The preview now covers another user-facing corner of the app that used to live behind modal interaction instead of direct route navigation.',
                        'Next up, the codebase is well-positioned for a richer detail-oriented seam such as activity detail.',
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