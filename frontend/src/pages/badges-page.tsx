import {useCallback, useEffect, useMemo, useState} from 'react';
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
        <article className="ui-section overflow-hidden">
            <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-gray-900/40">
                <div className="flex min-h-40 max-h-80 items-center justify-center overflow-hidden p-2">
                    <img src={resolveUrl(bootstrap.basePath, badge.imageUrl)} alt={badge.name} className="max-h-72 max-w-full" loading="lazy" />
                </div>
                <div className="mt-4">
                    <div className="text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{badge.category}</div>
                    <h3 className="mt-1 text-base font-semibold text-gray-900 dark:text-white">{badge.name}</h3>
                </div>
            </div>
            <div className="mt-4 rounded-lg border border-gray-200 bg-gray-950 px-4 py-3 text-xs leading-6 text-gray-100 dark:border-gray-800">
                <code className="block break-all">{badge.embedCode}</code>
            </div>
            <div className="mt-4 flex flex-wrap gap-2">
                <button
                    type="button"
                    onClick={() => onCopy(badge.embedCode, badge.id)}
                    className="ui-button"
                >
                    {isCopied ? 'Copied snippet' : 'Copy embed code'}
                </button>
                <a
                    href={badge.absoluteUrl}
                    target="_blank"
                    rel="noreferrer"
                    className="ui-button"
                >
                    Open raw SVG
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
        <div className="space-y-6 pb-6">
            <section className="ui-section">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div className="min-w-0">
                        <h1 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white md:text-2xl">Badges</h1>
                        <p className="mt-1 max-w-3xl text-sm leading-7 text-gray-500 dark:text-gray-400">
                            These badges are dynamically created and can be used in any <code>&lt;img&gt;</code> tag.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <a href={buildAppPath(bootstrap.basePath, 'badge.html')} className="ui-button">
                            Open classic badges page
                        </a>
                        <button type="button" onClick={reload} className="ui-button">
                            Refresh data
                        </button>
                    </div>
                </div>
            </section>

            <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Badge variants" value={formatNumber(data?.summary.totalBadges ?? 0)} hint="All embeddable SVG badge variants currently exposed by this route." tone="orange" />
                <StatCard label="Badge groups" value={formatNumber(data?.summary.categoryCount ?? 0)} hint="User, PB, and Zwift sections currently available." tone="blue" />
                <StatCard label="PB variants" value={formatNumber(data?.summary.personalBestBadgeCount ?? 0)} hint="Personal-best badge variants generated from supported sport types." tone="emerald" />
                <StatCard label="Zwift badge" value={data?.summary.hasZwiftBadge ? 'Available' : 'Unavailable'} hint="Whether the current account has a generated Zwift badge variant." tone="slate" />
            </section>

            <section className="ui-section">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div>
                        <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Badge sets</h2>
                        <p className="mt-1 max-w-3xl text-sm leading-7 text-gray-500 dark:text-gray-400">
                            The classic view uses tabs inside a modal. This route keeps the same groupings, just with a little more breathing room.
                        </p>
                    </div>
                    <div className="text-sm text-gray-500 dark:text-gray-400">
                        {data ? `Data refreshed ${formatRequestedAt(data.requestedAt)}.` : 'Waiting for badge data.'}
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
                                className={`rounded-lg border px-4 py-2 text-sm font-medium transition ${isActive
                                    ? 'border-orange-300 bg-orange-50 text-orange-700 dark:border-orange-500 dark:bg-orange-950/40 dark:text-orange-200'
                                    : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:text-white'}`}
                            >
                                {section.label} ({formatNumber(section.badgesCount)})
                            </button>
                        );
                    })}
                </div>
            </section>

            {loading && !data ? (
                <section className="ui-section text-sm text-gray-600 dark:text-gray-300">
                    Loading badges.
                </section>
            ) : null}

            {error ? (
                <section className="rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                    {error}
                </section>
            ) : null}

            {activeSection ? (
                <section className="space-y-4">
                    <div className="ui-section">
                        <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">{activeSection.label}</h2>
                        <p className="mt-1 max-w-3xl text-sm leading-7 text-gray-500 dark:text-gray-400">{activeSection.description}</p>
                    </div>
                    <div className="grid gap-4 xl:grid-cols-2">
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
        </div>
    );
}