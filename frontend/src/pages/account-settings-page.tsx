import {useEffect, useMemo, useState} from 'react';
import {Link} from 'react-router-dom';
import {StatCard} from '../components/stat-card';
import {buildAppPath, type ReactPreviewBootstrap} from '../lib/bootstrap';
import {
    disconnectStravaPreview,
    fetchAccountSettingsPreview,
    runManualSyncAction,
    type AccountSettingsPreviewResponse,
    type ManualSyncResult,
} from '../lib/account-settings-preview-api';

interface AccountSettingsPageProps {
    bootstrap: ReactPreviewBootstrap;
}

type SyncTone = 'idle' | 'info' | 'success' | 'error';
type SyncProvider = 'strava' | 'garmin';

interface SyncState {
    tone: SyncTone;
    message: string;
    output: string;
}

function formatRequestedAt(value: string): string {
    return new Intl.DateTimeFormat('en', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('en', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(new Date(`${value}T12:00:00`));
}

function formatDateTime(value: string): string {
    return new Intl.DateTimeFormat('en', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function buildStatusTone(tone: SyncTone): string {
    switch (tone) {
        case 'success':
            return 'border-emerald-200 bg-emerald-50/90 text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-950/30 dark:text-emerald-100';
        case 'error':
            return 'border-rose-200 bg-rose-50/90 text-rose-800 dark:border-rose-800/60 dark:bg-rose-950/30 dark:text-rose-100';
        case 'info':
            return 'border-amber-200 bg-amber-50/90 text-amber-800 dark:border-amber-800/60 dark:bg-amber-950/30 dark:text-amber-100';
        default:
            return 'border-gray-200 bg-white/85 text-gray-700 dark:border-gray-800 dark:bg-gray-950/40 dark:text-gray-200';
    }
}

function buildServiceTone(connected: boolean): string {
    return connected
        ? 'border-emerald-200 bg-emerald-50/90 dark:border-emerald-800/60 dark:bg-emerald-950/30'
        : 'border-gray-200 bg-white/85 dark:border-gray-800 dark:bg-gray-950/40';
}

function SyncStatusCard({title, state}: {title: string; state: SyncState}) {
    if (state.tone === 'idle' && !state.output) {
        return null;
    }

    return (
        <div className={`rounded-[24px] border px-4 py-4 text-sm leading-7 ${buildStatusTone(state.tone)}`}>
            <div className="text-xs font-semibold uppercase tracking-[0.24em] opacity-75">{title}</div>
            {state.message ? <div className="mt-2 font-semibold">{state.message}</div> : null}
            {state.output ? (
                <pre className="mt-3 max-h-72 overflow-auto rounded-[20px] bg-gray-950 px-4 py-3 text-xs leading-6 text-gray-100">{state.output}</pre>
            ) : null}
        </div>
    );
}

export function AccountSettingsPage({bootstrap}: AccountSettingsPageProps) {
    const [data, setData] = useState<AccountSettingsPreviewResponse | null>(null);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [activeProvider, setActiveProvider] = useState<SyncProvider | null>(null);
    const [disconnecting, setDisconnecting] = useState(false);
    const [stravaSyncState, setStravaSyncState] = useState<SyncState>({tone: 'idle', message: '', output: ''});
    const [garminSyncState, setGarminSyncState] = useState<SyncState>({tone: 'idle', message: '', output: ''});

    useEffect(() => {
        const abortController = new AbortController();

        setLoading(true);
        setLoadError(null);

        fetchAccountSettingsPreview(bootstrap.basePath, abortController.signal)
            .then((response) => {
                if (abortController.signal.aborted) {
                    return;
                }

                setData(response);
                setLoading(false);
            })
            .catch((error: unknown) => {
                if (abortController.signal.aborted) {
                    return;
                }

                setLoadError(error instanceof Error ? error.message : 'Could not load account settings.');
                setLoading(false);
            });

        return () => abortController.abort();
    }, [bootstrap.basePath]);

    const isBusy = activeProvider !== null || disconnecting;
    const verificationHint = useMemo(() => {
        if (!data) {
            return 'Loading account verification status.';
        }

        return data.account.emailVerified
            ? 'Your login is fully verified and ready for long-term app access.'
            : 'Email verification is still pending, so the React route keeps the live follow-up link visible.';
    }, [data]);

    async function handleManualSync(provider: SyncProvider) {
        if (!data) {
            return;
        }

        setActiveProvider(provider);
        const setState = provider === 'strava' ? setStravaSyncState : setGarminSyncState;
        setState({tone: 'info', message: `Running ${provider} sync…`, output: ''});

        try {
            const actionPath = provider === 'strava' ? data.actions.syncStravaPath : data.actions.syncGarminPath;
            const result = await runManualSyncAction(bootstrap.basePath, actionPath);
            setState({
                tone: 'success',
                message: buildSuccessMessage(provider, result),
                output: result.output || '',
            });
            if (provider === 'garmin' && result.lastImportedDay) {
                setData((current) => current ? {
                    ...current,
                    garmin: {
                        ...current.garmin,
                        lastImportedDay: result.lastImportedDay ?? current.garmin.lastImportedDay,
                    },
                    summary: {
                        ...current.summary,
                        garminLastImportedDay: result.lastImportedDay ?? current.summary.garminLastImportedDay,
                    },
                } : current);
            }
        } catch (error) {
            const errorWithOutput = error as Error & {output?: string};
            setState({
                tone: 'error',
                message: errorWithOutput.message,
                output: errorWithOutput.output || '',
            });
        } finally {
            setActiveProvider(null);
        }
    }

    async function handleDisconnectStrava() {
        if (!data?.strava.connected) {
            return;
        }

        const confirmed = window.confirm('Disconnect Strava from the live account settings?');
        if (!confirmed) {
            return;
        }

        setDisconnecting(true);
        setStravaSyncState({tone: 'idle', message: '', output: ''});

        try {
            const response = await disconnectStravaPreview(bootstrap.basePath);
            setData(response);
            setStravaSyncState({
                tone: 'success',
                message: 'Strava was disconnected from the live account.',
                output: '',
            });
        } catch (error) {
            setStravaSyncState({
                tone: 'error',
                message: error instanceof Error ? error.message : 'Could not disconnect Strava.',
                output: '',
            });
        } finally {
            setDisconnecting(false);
        }
    }

    if (loading) {
        return (
            <section className="glass-panel rounded-[36px] p-6 md:p-8">
                <div className="section-kicker">Loading</div>
                <div className="mt-5 grid gap-4 xl:grid-cols-[1.08fr_0.92fr]">
                    <div className="animate-pulse space-y-4 rounded-[28px] border border-gray-200 bg-white/90 p-6 dark:border-gray-800 dark:bg-gray-900/60">
                        <div className="h-4 w-40 rounded-full bg-gray-200 dark:bg-gray-800" />
                        <div className="h-10 w-3/4 rounded-full bg-gray-200 dark:bg-gray-800" />
                        <div className="h-28 rounded-[24px] bg-gray-100 dark:bg-gray-800" />
                    </div>
                    <div className="animate-pulse space-y-4 rounded-[28px] border border-gray-200 bg-white/90 p-6 dark:border-gray-800 dark:bg-gray-900/60">
                        <div className="h-14 rounded-[20px] bg-gray-100 dark:bg-gray-800" />
                        <div className="h-14 rounded-[20px] bg-gray-100 dark:bg-gray-800" />
                        <div className="h-14 rounded-[20px] bg-gray-100 dark:bg-gray-800" />
                    </div>
                </div>
            </section>
        );
    }

    if (loadError || !data) {
        return (
            <section className="rounded-[32px] border border-rose-200 bg-rose-50/90 p-6 text-sm leading-7 text-rose-800 dark:border-rose-800/60 dark:bg-rose-950/30 dark:text-rose-100">
                <div className="text-xs font-semibold uppercase tracking-[0.24em]">Could not load account settings</div>
                <p className="mt-3">{loadError ?? 'No data was returned.'}</p>
                <div className="mt-5 flex flex-wrap gap-3">
                    <a
                        href={buildAppPath(bootstrap.basePath, 'account/settings')}
                        className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                    >
                        Open legacy account settings
                        <span aria-hidden="true">↗</span>
                    </a>
                </div>
            </section>
        );
    }

    return (
        <div className="space-y-8 pb-8">
            <section className="glass-panel rounded-[36px] p-6 md:p-8">
                <div className="grid gap-8 xl:grid-cols-[1.08fr_0.92fr]">
                    <div>
                        <div className="section-kicker">Account settings preview</div>
                        <h1 className="mt-5 max-w-4xl text-4xl font-semibold tracking-tight text-gray-900 dark:text-white md:text-5xl">
                            Manage identity, service links, and manual syncs in a route-sized React control room.
                        </h1>
                        <p className="mt-5 max-w-3xl text-base leading-8 text-gray-600 dark:text-gray-300 md:text-lg">
                            This slice keeps the live account actions intact, but replaces the fragment-oriented legacy settings surface with a proper preview route that can own connection status, manual imports, and service diagnostics without hiding in a sidebar panel.
                        </p>
                        <div className="mt-6 flex flex-wrap gap-3">
                            <a
                                href={buildAppPath(bootstrap.basePath, data.legacyPath)}
                                className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                            >
                                Compare with the live route
                                <span aria-hidden="true">↗</span>
                            </a>
                            <Link
                                to="/dashboard"
                                className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                            >
                                Back to dashboard
                                <span aria-hidden="true">←</span>
                            </Link>
                            <a
                                href={buildAppPath(bootstrap.basePath, data.actions.logoutPath)}
                                className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                            >
                                Sign out
                                <span aria-hidden="true">⇢</span>
                            </a>
                        </div>
                    </div>
                    <div className="rounded-[32px] border border-sky-200 bg-[linear-gradient(135deg,rgba(255,255,255,0.96),rgba(239,246,255,0.96))] p-5 shadow-[0_45px_120px_-45px_rgba(15,23,42,0.65)] dark:border-sky-900/40 dark:bg-[linear-gradient(135deg,rgba(17,24,39,0.94),rgba(8,47,73,0.42))]">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-sky-700 dark:text-sky-200">Why this seam works</div>
                        <div className="mt-4 space-y-3 text-sm leading-7 text-gray-700 dark:text-gray-200">
                            {[
                                'Account settings is still legacy-only, but the live actions already exist in well-defined endpoints, which makes it a clean bridge slice.',
                                'It adds visible migration progress outside the planner domain, helping the React preview feel more like a full app and less like a sports-science annex.',
                                'Manual Strava and Garmin syncs are operationally useful, so this route carries real user value even before broader auth flows move over.',
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
                <StatCard label="Email" value={data.account.emailVerified ? 'Verified' : 'Pending'} hint={verificationHint} tone="orange" />
                <StatCard label="Connected services" value={String(data.summary.connectedServices)} hint="Strava and Garmin availability are summarized from the live account state." tone="emerald" />
                <StatCard label="Manual syncs" value={String(data.summary.manualSyncProviders)} hint="Providers that can be triggered immediately from this route." tone="blue" />
                <StatCard label="Garmin import" value={data.summary.garminLastImportedDay ? formatDate(data.summary.garminLastImportedDay) : 'Not yet'} hint={`Preview refreshed ${formatRequestedAt(data.requestedAt)}.`} />
            </section>

            <section className="grid gap-6 xl:grid-cols-[minmax(0,1.08fr)_minmax(320px,0.92fr)]">
                <div className="space-y-6">
                    <section className="glass-panel rounded-[32px] p-6 md:p-7">
                        <div className="section-kicker">Account</div>
                        <div className="mt-5 grid gap-4 md:grid-cols-2">
                            <div className="rounded-[24px] border border-gray-200 bg-white/85 p-5 dark:border-gray-800 dark:bg-gray-950/40">
                                <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Email</div>
                                <div className="mt-3 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">{data.account.email}</div>
                            </div>
                            <div className={`rounded-[24px] border p-5 ${data.account.emailVerified ? 'border-emerald-200 bg-emerald-50/90 dark:border-emerald-800/60 dark:bg-emerald-950/30' : 'border-amber-200 bg-amber-50/90 dark:border-amber-800/60 dark:bg-amber-950/30'}`}>
                                <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Verification</div>
                                <div className="mt-3 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">{data.account.emailVerificationStatusLabel}</div>
                                {!data.account.emailVerified && data.account.verifyEmailPath ? (
                                    <a
                                        href={buildAppPath(bootstrap.basePath, data.account.verifyEmailPath)}
                                        className="mt-4 inline-flex items-center gap-2 rounded-2xl border border-white/80 bg-white/90 px-4 py-3 text-sm font-semibold text-amber-900 transition hover:bg-white dark:border-gray-800 dark:bg-gray-950/40 dark:text-amber-100"
                                    >
                                        Verify now
                                        <span aria-hidden="true">→</span>
                                    </a>
                                ) : null}
                            </div>
                        </div>
                    </section>

                    <section className={`glass-panel rounded-[32px] p-6 md:p-7 ${buildServiceTone(data.strava.connected)}`}>
                        <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                            <div>
                                <div className="section-kicker">Strava connection</div>
                                <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">Live activity import link</h2>
                                <p className="mt-3 text-sm leading-7 text-gray-600 dark:text-gray-300">
                                    Connect or disconnect the source of truth for activity imports, then kick off a manual refresh without leaving the preview shell.
                                </p>
                            </div>
                            <div className="rounded-full border border-white/80 bg-white/80 px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-gray-700 dark:border-gray-800 dark:bg-gray-950/40 dark:text-gray-100">
                                {data.strava.statusLabel}
                            </div>
                        </div>
                        <div className="mt-6 grid gap-4 md:grid-cols-2">
                            <div className="rounded-[24px] border border-gray-200 bg-white/90 p-4 dark:border-gray-800 dark:bg-gray-950/40">
                                <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Athlete ID</div>
                                <div className="mt-2 text-lg font-semibold text-gray-900 dark:text-white">{data.strava.athleteId ?? 'No linked athlete yet'}</div>
                            </div>
                            <div className="rounded-[24px] border border-gray-200 bg-white/90 p-4 dark:border-gray-800 dark:bg-gray-950/40">
                                <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Scopes</div>
                                <div className="mt-2 text-sm leading-7 text-gray-700 dark:text-gray-200">{data.strava.scopeLabel ?? 'No scopes until Strava is connected.'}</div>
                            </div>
                        </div>
                        {data.strava.tokenRefreshedAt ? (
                            <div className="mt-4 rounded-[24px] border border-gray-200 bg-white/90 p-4 text-sm leading-7 text-gray-600 dark:border-gray-800 dark:bg-gray-950/40 dark:text-gray-300">
                                Last token refresh: {formatDateTime(data.strava.tokenRefreshedAt)}
                            </div>
                        ) : null}
                        <div className="mt-5 flex flex-wrap gap-3">
                            <a
                                href={buildAppPath(bootstrap.basePath, data.actions.connectStravaPath)}
                                className="inline-flex items-center gap-2 rounded-2xl bg-strava-orange px-5 py-3 text-sm font-semibold text-white transition hover:bg-orange-600"
                            >
                                {data.strava.connected ? 'Reconnect Strava' : 'Connect Strava'}
                                <span aria-hidden="true">→</span>
                            </a>
                            {data.strava.connected ? (
                                <button
                                    type="button"
                                    onClick={() => void handleDisconnectStrava()}
                                    disabled={isBusy}
                                    className="inline-flex items-center gap-2 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800 transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-60 dark:border-rose-800/60 dark:bg-rose-950/30 dark:text-rose-100"
                                >
                                    {disconnecting ? 'Disconnecting…' : 'Disconnect'}
                                </button>
                            ) : null}
                            <button
                                type="button"
                                onClick={() => void handleManualSync('strava')}
                                disabled={!data.strava.canSync || isBusy}
                                className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                            >
                                {activeProvider === 'strava' ? 'Syncing Strava…' : 'Sync Strava now'}
                            </button>
                        </div>
                        <div className="mt-5">
                            <SyncStatusCard title="Strava sync status" state={stravaSyncState} />
                        </div>
                    </section>
                </div>

                <div className="space-y-6 xl:sticky xl:top-28 xl:self-start">
                    <section className={`glass-panel rounded-[32px] p-6 md:p-7 ${buildServiceTone(data.garmin.enabled && data.garmin.configured)}`}>
                        <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                            <div>
                                <div className="section-kicker">Garmin wellness</div>
                                <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">Recovery data bridge</h2>
                                <p className="mt-3 text-sm leading-7 text-gray-600 dark:text-gray-300">
                                    The React route surfaces the same environment-driven Garmin configuration as the live account page, including connection mode, source file, and manual import controls.
                                </p>
                            </div>
                            <div className="rounded-full border border-white/80 bg-white/80 px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-gray-700 dark:border-gray-800 dark:bg-gray-950/40 dark:text-gray-100">
                                {data.garmin.enabled ? (data.garmin.configured ? 'Connected' : 'Needs setup') : 'Disabled'}
                            </div>
                        </div>
                        <div className="mt-6 space-y-4">
                            <div className="rounded-[24px] border border-gray-200 bg-white/90 p-4 dark:border-gray-800 dark:bg-gray-950/40">
                                <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Connection mode</div>
                                <div className="mt-2 text-lg font-semibold text-gray-900 dark:text-white">{data.garmin.connectionModeLabel}</div>
                            </div>
                            <div className="rounded-[24px] border border-gray-200 bg-white/90 p-4 dark:border-gray-800 dark:bg-gray-950/40">
                                <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Bridge source file</div>
                                <div className="mt-2 break-all text-sm leading-7 text-gray-700 dark:text-gray-200">{data.garmin.bridgeSourcePath}</div>
                            </div>
                            <div className="rounded-[24px] border border-gray-200 bg-white/90 p-4 dark:border-gray-800 dark:bg-gray-950/40">
                                <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">Last imported Garmin day</div>
                                <div className="mt-2 text-lg font-semibold text-gray-900 dark:text-white">{data.garmin.lastImportedDay ? formatDate(data.garmin.lastImportedDay) : 'No Garmin wellness days imported yet'}</div>
                            </div>
                        </div>
                        {!data.garmin.enabled ? (
                            <div className="mt-4 rounded-[24px] border border-amber-200 bg-amber-50/90 p-4 text-sm leading-7 text-amber-800 dark:border-amber-800/60 dark:bg-amber-950/30 dark:text-amber-100">
                                Garmin wellness sync is disabled in the current app configuration.
                            </div>
                        ) : null}
                        {data.garmin.enabled && !data.garmin.configured ? (
                            <div className="mt-4 rounded-[24px] border border-amber-200 bg-amber-50/90 p-4 text-sm leading-7 text-amber-800 dark:border-amber-800/60 dark:bg-amber-950/30 dark:text-amber-100">
                                Add Garmin credentials or tokens to the environment before running a manual sync.
                            </div>
                        ) : null}
                        <div className="mt-5 flex flex-wrap gap-3">
                            <button
                                type="button"
                                onClick={() => void handleManualSync('garmin')}
                                disabled={!data.garmin.canSync || isBusy}
                                className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                            >
                                {activeProvider === 'garmin' ? 'Syncing Garmin…' : 'Sync Garmin now'}
                            </button>
                        </div>
                        <div className="mt-5">
                            <SyncStatusCard title="Garmin sync status" state={garminSyncState} />
                        </div>
                    </section>
                </div>
            </section>
        </div>
    );
}

function buildSuccessMessage(provider: SyncProvider, result: ManualSyncResult): string {
    if (typeof result.durationInSeconds === 'number') {
        return `${result.message} (${result.durationInSeconds.toFixed(1)}s)`;
    }

    return provider === 'garmin' && result.lastImportedDay
        ? `${result.message} Imported through ${result.lastImportedDay}.`
        : result.message;
}
