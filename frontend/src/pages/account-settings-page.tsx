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
            return 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-950/30 dark:text-emerald-100';
        case 'error':
            return 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-800/60 dark:bg-rose-950/30 dark:text-rose-100';
        case 'info':
            return 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800/60 dark:bg-amber-950/30 dark:text-amber-100';
        default:
            return 'border-gray-200 bg-white text-gray-700 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-200';
    }
}

function buildConnectionBadgeTone(connected: boolean): string {
    return connected
        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-100'
        : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-200';
}

function buildGarminBadgeTone(enabled: boolean, configured: boolean): string {
    if (enabled && configured) {
        return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-100';
    }

    if (enabled) {
        return 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-100';
    }

    return 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-200';
}

function SyncStatusCard({title, state}: {title: string; state: SyncState}) {
    if (state.tone === 'idle' && !state.output) {
        return null;
    }

    return (
        <div className={`rounded-lg border px-4 py-4 text-sm leading-7 ${buildStatusTone(state.tone)}`}>
            <div className="text-[10px] font-semibold uppercase tracking-wide opacity-75">{title}</div>
            {state.message ? <div className="mt-2 font-semibold">{state.message}</div> : null}
            {state.output ? (
                <pre className="mt-3 max-h-72 overflow-auto rounded-lg bg-gray-950 px-4 py-3 text-xs leading-6 text-gray-100">{state.output}</pre>
            ) : null}
        </div>
    );
}

function DetailTile({label, value, breakAll = false}: {label: string; value: string; breakAll?: boolean}) {
    return (
        <div className="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/60">
            <div className="text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</div>
            <div className={`mt-1 text-sm font-medium text-gray-900 dark:text-white ${breakAll ? 'break-all' : ''}`}>{value}</div>
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
            : 'Email verification is still pending, so the live follow-up link stays visible here.';
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
            <section className="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div className="grid gap-4 xl:grid-cols-[1.08fr_0.92fr]">
                    <div className="animate-pulse space-y-4 rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
                        <div className="h-4 w-40 rounded-full bg-gray-200 dark:bg-gray-800" />
                        <div className="h-8 w-3/4 rounded-full bg-gray-200 dark:bg-gray-800" />
                        <div className="h-24 rounded-lg bg-gray-100 dark:bg-gray-800" />
                    </div>
                    <div className="animate-pulse space-y-4 rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
                        <div className="h-14 rounded-lg bg-gray-100 dark:bg-gray-800" />
                        <div className="h-14 rounded-lg bg-gray-100 dark:bg-gray-800" />
                        <div className="h-14 rounded-lg bg-gray-100 dark:bg-gray-800" />
                    </div>
                </div>
            </section>
        );
    }

    if (loadError || !data) {
        return (
            <section className="rounded-lg border border-rose-200 bg-rose-50 p-5 text-sm leading-7 text-rose-800 shadow-sm dark:border-rose-800/60 dark:bg-rose-950/30 dark:text-rose-100">
                <div className="text-[10px] font-semibold uppercase tracking-wide">Could not load account settings</div>
                <p className="mt-3">{loadError ?? 'No data was returned.'}</p>
                <div className="mt-4 flex flex-wrap gap-2">
                    <a href={buildAppPath(bootstrap.basePath, 'account/settings')} className="ui-button">
                        Open classic account settings
                    </a>
                </div>
            </section>
        );
    }

    return (
        <div className="space-y-6 pb-6">
            <section className="ui-section">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div className="min-w-0">
                        <h1 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white md:text-2xl">Account settings</h1>
                        <p className="mt-1 max-w-3xl text-sm leading-7 text-gray-500 dark:text-gray-400">
                            Manage your account details, connected services, and manual sync tools.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <a href={buildAppPath(bootstrap.basePath, data.legacyPath)} className="ui-button">
                            Open classic account settings
                        </a>
                        <Link to="/dashboard" className="ui-button">
                            Dashboard
                        </Link>
                        <a href={buildAppPath(bootstrap.basePath, data.actions.logoutPath)} className="ui-button">
                            Sign out
                        </a>
                    </div>
                </div>
            </section>

            <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Email" value={data.account.emailVerified ? 'Verified' : 'Pending'} hint={verificationHint} tone="orange" />
                <StatCard label="Connected services" value={String(data.summary.connectedServices)} hint="Strava and Garmin availability are summarized from the live account state." tone="emerald" />
                <StatCard label="Manual syncs" value={String(data.summary.manualSyncProviders)} hint="Providers that can be triggered immediately from this route." tone="blue" />
                <StatCard label="Garmin import" value={data.summary.garminLastImportedDay ? formatDate(data.summary.garminLastImportedDay) : 'Not yet'} hint={`Updated ${formatRequestedAt(data.requestedAt)}.`} />
            </section>

            <section className="grid gap-4 xl:grid-cols-[minmax(0,1.08fr)_minmax(320px,0.92fr)]">
                <div className="space-y-4">
                    <section className="ui-section">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Account</h2>
                            <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-[10px] font-semibold ${data.account.emailVerified ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-100' : 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-100'}`}>
                                {data.account.emailVerified ? 'Verified' : 'Pending'}
                            </span>
                        </div>
                        <dl className="mt-4 divide-y divide-gray-100 text-sm dark:divide-gray-800">
                            <div className="flex items-center justify-between gap-3 py-3 first:pt-0">
                                <dt className="text-gray-500 dark:text-gray-400">Email</dt>
                                <dd className="text-right font-medium text-gray-900 dark:text-white">{data.account.email}</dd>
                            </div>
                            <div className="flex items-center justify-between gap-3 py-3 last:pb-0">
                                <dt className="text-gray-500 dark:text-gray-400">Email verification</dt>
                                <dd className="text-right">
                                    <div className="flex flex-wrap items-center justify-end gap-2">
                                        <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-[10px] font-semibold ${data.account.emailVerified ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-100' : 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-100'}`}>
                                            {data.account.emailVerificationStatusLabel}
                                        </span>
                                        {!data.account.emailVerified && data.account.verifyEmailPath ? (
                                            <a href={buildAppPath(bootstrap.basePath, data.account.verifyEmailPath)} className="text-xs font-medium text-strava-orange hover:text-orange-600">
                                                Verify now
                                            </a>
                                        ) : null}
                                    </div>
                                </dd>
                            </div>
                        </dl>
                    </section>

                    <section className="ui-section">
                        <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                            <div>
                                <div className="flex items-center gap-2">
                                    <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Strava connection</h2>
                                    <span className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ${buildConnectionBadgeTone(data.strava.connected)}`}>
                                        {data.strava.statusLabel}
                                    </span>
                                </div>
                                <p className="mt-1 text-sm leading-7 text-gray-500 dark:text-gray-400">
                                    Connect your Strava account and trigger a manual refresh whenever you want.
                                </p>
                            </div>
                        </div>
                        <div className="mt-4 flex flex-wrap gap-2">
                            <a href={buildAppPath(bootstrap.basePath, data.actions.connectStravaPath)} className="ui-button ui-button-primary">
                                {data.strava.connected ? 'Reconnect Strava' : 'Connect Strava'}
                            </a>
                            {data.strava.connected ? (
                                <button type="button" onClick={() => void handleDisconnectStrava()} disabled={isBusy} className="ui-button ui-button-danger disabled:cursor-not-allowed disabled:opacity-60">
                                    {disconnecting ? 'Disconnecting…' : 'Disconnect'}
                                </button>
                            ) : null}
                        </div>

                        {data.strava.connected ? (
                            <div className="mt-4 grid gap-3 sm:grid-cols-2">
                                <DetailTile label="Athlete ID" value={data.strava.athleteId ?? 'No linked athlete yet'} />
                                <DetailTile label="Scopes" value={data.strava.scopeLabel ?? 'No scopes until Strava is connected.'} />
                            </div>
                        ) : (
                            <div className="mt-4 rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 py-5 text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-950/25 dark:text-gray-300">
                                No Strava account is linked yet.
                            </div>
                        )}

                        {data.strava.tokenRefreshedAt ? (
                            <div className="mt-4 text-xs text-gray-500 dark:text-gray-400">
                                Last token refresh: {formatDateTime(data.strava.tokenRefreshedAt)}
                            </div>
                        ) : null}

                        <div className="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-gray-950/30">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <div className="text-sm font-medium text-gray-900 dark:text-white">Manual Strava import</div>
                                    <p className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Pull the latest Strava data and rebuild the app now.</p>
                                </div>
                                <button type="button" onClick={() => void handleManualSync('strava')} disabled={!data.strava.canSync || isBusy} className="ui-button disabled:cursor-not-allowed disabled:opacity-60">
                                    {activeProvider === 'strava' ? 'Syncing Strava…' : 'Sync Strava now'}
                                </button>
                            </div>
                        </div>

                        <div className="mt-4">
                            <SyncStatusCard title="Strava sync status" state={stravaSyncState} />
                        </div>
                    </section>
                </div>

                <div className="space-y-4 xl:sticky xl:top-28 xl:self-start">
                    <section className="ui-section">
                        <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                            <div>
                                <div className="flex items-center gap-2">
                                    <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Garmin wellness</h2>
                                    <span className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ${buildGarminBadgeTone(data.garmin.enabled, data.garmin.configured)}`}>
                                        {data.garmin.enabled ? (data.garmin.configured ? 'Connected' : 'Needs setup') : 'Disabled'}
                                    </span>
                                </div>
                                <p className="mt-1 text-sm leading-7 text-gray-500 dark:text-gray-400">
                                    Garmin sync uses the wellness bridge configuration from your app environment and config files.
                                </p>
                            </div>
                        </div>

                        <div className="mt-4 grid gap-3 sm:grid-cols-2">
                            <DetailTile label="Connection mode" value={data.garmin.connectionModeLabel} />
                            <DetailTile label="Wellness import" value={data.garmin.enabled ? 'Enabled' : 'Disabled'} />
                            <DetailTile label="Bridge source file" value={data.garmin.bridgeSourcePath} breakAll />
                            <DetailTile label="Last imported Garmin day" value={data.garmin.lastImportedDay ? formatDate(data.garmin.lastImportedDay) : 'No Garmin wellness days imported yet'} />
                        </div>

                        {!data.garmin.enabled ? (
                            <div className="mt-4 rounded-lg border border-dashed border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800/60 dark:bg-amber-950/30 dark:text-amber-100">
                                Enable wellness integration in the app configuration before Garmin sync can run.
                            </div>
                        ) : null}
                        {data.garmin.enabled && !data.garmin.configured ? (
                            <div className="mt-4 rounded-lg border border-dashed border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800/60 dark:bg-amber-950/30 dark:text-amber-100">
                                Add Garmin credentials or tokens to the environment before running a manual sync.
                            </div>
                        ) : null}

                        <div className="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-gray-950/30">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <div className="text-sm font-medium text-gray-900 dark:text-white">Manual Garmin import</div>
                                    <p className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Refresh Garmin wellness bridge data, import it, and rebuild the app now.</p>
                                </div>
                                <button type="button" onClick={() => void handleManualSync('garmin')} disabled={!data.garmin.canSync || isBusy} className="ui-button disabled:cursor-not-allowed disabled:opacity-60">
                                    {activeProvider === 'garmin' ? 'Syncing Garmin…' : 'Sync Garmin now'}
                                </button>
                            </div>
                        </div>

                        <div className="mt-4">
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
