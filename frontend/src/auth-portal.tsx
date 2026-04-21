import {buildPortalPath, getReactPortalBootstrap, type ForgotPasswordPortalBootstrap, type LoginPortalBootstrap, type ReactPortalBootstrap, type RegisterPortalBootstrap, type ResetPasswordPortalBootstrap, type SetupPortalBootstrap} from './lib/auth-bootstrap';

function Notice({tone = 'neutral', children}: {tone?: 'neutral' | 'success' | 'error'; children: React.ReactNode}) {
    const toneClass = tone === 'success'
        ? 'border-emerald-200 bg-emerald-50/95 text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-950/30 dark:text-emerald-100'
        : tone === 'error'
            ? 'border-rose-200 bg-rose-50/95 text-rose-800 dark:border-rose-800/60 dark:bg-rose-950/30 dark:text-rose-100'
            : 'border-white/70 bg-white/70 text-gray-700 dark:border-gray-800 dark:bg-gray-950/40 dark:text-gray-200';

    return <div className={`rounded-[22px] border px-4 py-3 text-sm leading-7 ${toneClass}`}>{children}</div>;
}

function Field({label, id, ...props}: {label: string; id: string} & React.InputHTMLAttributes<HTMLInputElement>) {
    return (
        <label className="block space-y-2" htmlFor={id}>
            <span className="block text-sm font-semibold text-gray-700 dark:text-gray-100">{label}</span>
            <input
                id={id}
                {...props}
                className="block w-full rounded-[20px] border border-gray-200 bg-white/95 px-4 py-3 text-sm text-gray-900 shadow-[0_20px_50px_-40px_rgba(15,23,42,0.75)] outline-none transition placeholder:text-gray-400 focus:border-orange-300 focus:ring-4 focus:ring-orange-100 dark:border-gray-700 dark:bg-gray-950/70 dark:text-white dark:placeholder:text-gray-500 dark:focus:border-orange-500 dark:focus:ring-orange-950/40"
            />
        </label>
    );
}

function PrimaryButton({children}: {children: React.ReactNode}) {
    return (
        <button
            type="submit"
            className="inline-flex w-full items-center justify-center rounded-[20px] bg-strava-orange px-4 py-3.5 text-sm font-semibold text-white shadow-[0_30px_60px_-30px_rgba(242,103,34,0.85)] transition hover:translate-y-[-1px] hover:bg-orange-600 focus:outline-none focus:ring-4 focus:ring-orange-200 dark:focus:ring-orange-950/40"
        >
            {children}
        </button>
    );
}

function SecondaryLink({href, children}: {href: string; children: React.ReactNode}) {
    return (
        <a href={href} className="text-sm font-semibold text-orange-700 transition hover:text-orange-600 dark:text-orange-300 dark:hover:text-orange-200">
            {children}
        </a>
    );
}

function PortalFrame({eyebrow, title, subtitle, sideTitle, sideBody, children}: {
    eyebrow: string;
    title: string;
    subtitle: string;
    sideTitle: string;
    sideBody: React.ReactNode;
    children: React.ReactNode;
}) {
    return (
        <div className="relative min-h-screen overflow-hidden bg-[radial-gradient(circle_at_top_left,rgba(242,103,34,0.24),transparent_30%),radial-gradient(circle_at_85%_15%,rgba(15,23,42,0.14),transparent_35%),linear-gradient(135deg,#fff7ed_0%,#fff 40%,#fffbeb 100%)] dark:bg-[radial-gradient(circle_at_top_left,rgba(242,103,34,0.22),transparent_30%),radial-gradient(circle_at_85%_15%,rgba(56,189,248,0.08),transparent_35%),linear-gradient(135deg,#020617_0%,#111827 40%,#1f2937 100%)]">
            <div className="pointer-events-none absolute inset-0 opacity-[0.07] [background-image:radial-gradient(circle_at_1px_1px,rgba(15,23,42,0.45)_1px,transparent_0)] [background-size:22px_22px] dark:opacity-[0.11]" />
            <div className="relative mx-auto flex min-h-screen max-w-7xl flex-col justify-center px-4 py-10 sm:px-6 lg:px-8">
                <div className="grid gap-8 xl:grid-cols-[minmax(0,1.04fr)_minmax(360px,0.96fr)] xl:items-stretch">
                    <section className="glass-panel rounded-[38px] p-6 md:p-8 xl:p-10">
                        <div className="section-kicker">{eyebrow}</div>
                        <h1 className="mt-5 max-w-3xl text-4xl font-semibold tracking-tight text-gray-900 dark:text-white md:text-5xl">
                            {title}
                        </h1>
                        <p className="mt-5 max-w-2xl text-base leading-8 text-gray-600 dark:text-gray-300 md:text-lg">
                            {subtitle}
                        </p>
                        <div className="mt-8 grid gap-4 sm:grid-cols-2">
                            {[
                                {
                                    title: 'Training calendar',
                                    body: 'Plan the coming weeks with route-sized month and session editing instead of modal gymnastics.',
                                },
                                {
                                    title: 'Race planning',
                                    body: 'Build and regenerate periodized plans while Symfony keeps the calculation engine firmly bolted down.',
                                },
                                {
                                    title: 'Performance trends',
                                    body: 'Review best efforts, Eddington progress, milestones, and year rewinds in the same navigation system.',
                                },
                                {
                                    title: 'Connected account tools',
                                    body: 'Keep manual Strava and Garmin sync actions, service status, and account controls close at hand.',
                                },
                            ].map((item) => (
                                <div key={item.title} className="rounded-[28px] border border-white/75 bg-white/80 p-5 shadow-[0_30px_80px_-55px_rgba(15,23,42,0.55)] dark:border-gray-800 dark:bg-gray-950/45 dark:shadow-none">
                                    <div className="text-sm font-semibold text-gray-900 dark:text-white">{item.title}</div>
                                    <div className="mt-2 text-sm leading-7 text-gray-600 dark:text-gray-300">{item.body}</div>
                                </div>
                            ))}
                        </div>
                    </section>

                    <aside className="rounded-[38px] border border-white/70 bg-white/85 p-6 shadow-[0_45px_120px_-55px_rgba(15,23,42,0.65)] backdrop-blur-xl dark:border-gray-800 dark:bg-gray-950/82 dark:shadow-none md:p-8">
                        <div className="text-xs font-semibold uppercase tracking-[0.28em] text-orange-700 dark:text-orange-300">{sideTitle}</div>
                        <div className="mt-4 space-y-4">{sideBody}</div>
                        <div className="mt-8">{children}</div>
                    </aside>
                </div>
            </div>
        </div>
    );
}

function LoginPortal({bootstrap}: {bootstrap: LoginPortalBootstrap}) {
    const loginPath = buildPortalPath(bootstrap.basePath, bootstrap.actions.loginPath);
    const registerPath = buildPortalPath(bootstrap.basePath, bootstrap.actions.registerPath);
    const forgotPasswordPath = buildPortalPath(bootstrap.basePath, bootstrap.actions.forgotPasswordPath);

    return (
        <PortalFrame
            eyebrow="Sign in"
            title="Step back into your training cockpit."
            subtitle="The frontend may be graduating to React, but the mission stays the same: get you back to the dashboard, calendar, and planner with as little fuss as possible."
            sideTitle="Welcome back"
            sideBody={
                <>
                    {bootstrap.notices.registered ? <Notice tone="success">Your account was created. Sign in to continue.</Notice> : null}
                    {bootstrap.notices.passwordReset ? <Notice tone="success">Your password was updated. Sign in with the new one.</Notice> : null}
                    {bootstrap.error ? <Notice tone="error">{bootstrap.error}</Notice> : null}
                    <Notice>
                        This sign-in flow still uses Symfony session auth under the hood, so you keep the established security behavior while the UI catches up.
                    </Notice>
                </>
            }
        >
            <form method="post" action={loginPath} className="space-y-4">
                <input type="hidden" name="_csrf_token" value={bootstrap.csrfToken} />
                <Field id="username" label="Email" name="_username" type="email" autoComplete="email" defaultValue={bootstrap.lastUsername} required />
                <Field id="password" label="Password" name="_password" type="password" autoComplete="current-password" required />
                <PrimaryButton>Sign in</PrimaryButton>
            </form>
            <div className="mt-5 flex items-center justify-between gap-4">
                <SecondaryLink href={registerPath}>Create an account</SecondaryLink>
                <SecondaryLink href={forgotPasswordPath}>Forgot password?</SecondaryLink>
            </div>
        </PortalFrame>
    );
}

function RegisterPortal({bootstrap}: {bootstrap: RegisterPortalBootstrap}) {
    const submitPath = buildPortalPath(bootstrap.basePath, bootstrap.actions.submitPath);
    const loginPath = buildPortalPath(bootstrap.basePath, bootstrap.actions.loginPath);

    return (
        <PortalFrame
            eyebrow="Create account"
            title="Claim your training data before your future self gets smug about consistency."
            subtitle="Registration stays server-backed, but the entry experience now matches the newer route-sized React direction and points directly toward connected, accountable planning."
            sideTitle="What happens next"
            sideBody={
                <>
                    {bootstrap.error ? <Notice tone="error">{bootstrap.error}</Notice> : null}
                    <Notice>
                        The first registered user can still inherit legacy single-user data automatically, so this migration does not strand existing installs in a half-converted limbo.
                    </Notice>
                </>
            }
        >
            <form method="post" action={submitPath} className="space-y-4">
                <Field id="email" label="Email" name="email" type="email" autoComplete="email" defaultValue={bootstrap.email} required />
                <Field id="password" label="Password" name="password" type="password" autoComplete="new-password" required />
                <Field id="passwordConfirmation" label="Confirm password" name="passwordConfirmation" type="password" autoComplete="new-password" required />
                <PrimaryButton>Create account</PrimaryButton>
            </form>
            <div className="mt-5 text-sm text-gray-600 dark:text-gray-300">
                Already have an account?{' '}
                <SecondaryLink href={loginPath}>Sign in</SecondaryLink>
            </div>
        </PortalFrame>
    );
}

function ForgotPasswordPortal({bootstrap}: {bootstrap: ForgotPasswordPortalBootstrap}) {
    const submitPath = buildPortalPath(bootstrap.basePath, bootstrap.actions.submitPath);
    const loginPath = buildPortalPath(bootstrap.basePath, bootstrap.actions.loginPath);
    const resetLink = bootstrap.resetLink ? buildPortalPath(bootstrap.basePath, bootstrap.resetLink) : null;

    return (
        <PortalFrame
            eyebrow="Password recovery"
            title="Reset the password, keep the momentum."
            subtitle="This page keeps the current no-email short-circuit behavior for local installs while giving the flow a proper first-class React surface."
            sideTitle="Recovery details"
            sideBody={
                <>
                    {bootstrap.submitted ? (
                        <Notice tone="success">
                            If that account exists, a reset link is ready.
                            {resetLink ? (
                                <div className="mt-2 break-all">
                                    <a className="font-semibold underline" href={resetLink}>{resetLink}</a>
                                </div>
                            ) : null}
                        </Notice>
                    ) : null}
                    <Notice>
                        The backend still decides whether a user exists. The UI never leaks that decision through a different response shape.
                    </Notice>
                </>
            }
        >
            <form method="post" action={submitPath} className="space-y-4">
                <Field id="email" label="Email" name="email" type="email" autoComplete="email" required />
                <PrimaryButton>Create reset link</PrimaryButton>
            </form>
            <div className="mt-5">
                <SecondaryLink href={loginPath}>Back to sign in</SecondaryLink>
            </div>
        </PortalFrame>
    );
}

function ResetPasswordPortal({bootstrap}: {bootstrap: ResetPasswordPortalBootstrap}) {
    const loginPath = buildPortalPath(bootstrap.basePath, bootstrap.actions.loginPath);
    const submitPath = buildPortalPath(bootstrap.basePath, bootstrap.actions.submitPath);

    return (
        <PortalFrame
            eyebrow="Set a new password"
            title="Give the account a fresh lock and keep going."
            subtitle="Reset links still expire and validate on the server, but the UI now speaks the same design language as the rest of the migration work."
            sideTitle="Link status"
            sideBody={
                <>
                    {bootstrap.error ? <Notice tone="error">{bootstrap.error}</Notice> : null}
                    {!bootstrap.token ? (
                        <Notice tone="error">
                            That reset link is not valid anymore.
                        </Notice>
                    ) : (
                        <Notice>
                            Choose a strong password and confirm it once. The backend will keep ownership of hashing, token lookup, and final redirect behavior.
                        </Notice>
                    )}
                </>
            }
        >
            {bootstrap.token ? (
                <form method="post" action={submitPath} className="space-y-4">
                    <Field id="password" label="New password" name="password" type="password" autoComplete="new-password" required />
                    <Field id="passwordConfirmation" label="Confirm new password" name="passwordConfirmation" type="password" autoComplete="new-password" required />
                    <PrimaryButton>Update password</PrimaryButton>
                </form>
            ) : null}
            <div className="mt-5">
                <SecondaryLink href={loginPath}>Back to sign in</SecondaryLink>
            </div>
        </PortalFrame>
    );
}

function SetupPortal({bootstrap}: {bootstrap: SetupPortalBootstrap}) {
    const accountSettingsPath = buildPortalPath(bootstrap.basePath, bootstrap.actions.accountSettingsPath);
    const logoutPath = buildPortalPath(bootstrap.basePath, bootstrap.actions.logoutPath);
    const connectStravaPath = buildPortalPath(bootstrap.basePath, bootstrap.actions.connectStravaPath);

    return (
        <PortalFrame
            eyebrow="Account setup"
            title="One last bridge before the dashboard opens up."
            subtitle="The account exists, but there is no athlete profile yet. Connect Strava and the React app can immediately start feeding the calendar, dashboard, and planning routes with real data."
            sideTitle="Current state"
            sideBody={
                <>
                    <Notice>
                        Signed in as <span className="font-semibold">{bootstrap.user.email}</span>.
                    </Notice>
                    <Notice tone={bootstrap.strava.connected ? 'success' : 'neutral'}>
                        Strava status: <span className="font-semibold">{bootstrap.strava.connected ? 'Connected' : 'Not connected'}</span>.
                    </Notice>
                </>
            }
        >
            <div className="rounded-[28px] border border-orange-200 bg-orange-50/90 p-5 dark:border-orange-800/60 dark:bg-orange-950/30">
                <div className="text-sm font-semibold text-gray-900 dark:text-white">Connect Strava</div>
                <p className="mt-2 text-sm leading-7 text-gray-700 dark:text-gray-200">
                    This links the returned athlete and refresh token to the authenticated account instead of relying on the old global copy-paste token flow.
                </p>
                <a
                    href={connectStravaPath}
                    className="mt-4 inline-flex items-center gap-2 rounded-[18px] bg-strava-orange px-4 py-3 text-sm font-semibold text-white shadow-[0_24px_40px_-24px_rgba(242,103,34,0.9)] transition hover:translate-y-[-1px] hover:bg-orange-600"
                >
                    Connect Strava
                    <span aria-hidden="true">→</span>
                </a>
            </div>
            <div className="mt-5 flex flex-wrap gap-3">
                <a href={accountSettingsPath} className="inline-flex items-center gap-2 rounded-[18px] border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-950/70 dark:text-gray-100 dark:hover:border-gray-600">
                    Account settings
                </a>
                <a href={logoutPath} className="inline-flex items-center gap-2 rounded-[18px] border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-950/70 dark:text-gray-100 dark:hover:border-gray-600">
                    Log out
                </a>
            </div>
        </PortalFrame>
    );
}

export function AuthPortalApp() {
    const bootstrap = getReactPortalBootstrap();

    switch (bootstrap.kind) {
        case 'login':
            return <LoginPortal bootstrap={bootstrap} />;
        case 'register':
            return <RegisterPortal bootstrap={bootstrap} />;
        case 'forgot-password':
            return <ForgotPasswordPortal bootstrap={bootstrap} />;
        case 'reset-password':
            return <ResetPasswordPortal bootstrap={bootstrap} />;
        case 'setup':
            return <SetupPortal bootstrap={bootstrap} />;
        default:
            return <UnsupportedPortal bootstrap={bootstrap} />;
    }
}

function UnsupportedPortal({bootstrap}: {bootstrap: ReactPortalBootstrap}) {
    return (
        <PortalFrame
            eyebrow="Portal"
            title="This screen has not been wired yet."
            subtitle="The bootstrap payload did not match a known auth/setup screen, so the safest move is to stop here rather than render the wrong form."
            sideTitle="Unsupported screen"
            sideBody={<Notice tone="error">Unknown portal kind: {bootstrap.kind}</Notice>}
        >
            <div className="text-sm text-gray-600 dark:text-gray-300">Check the controller bootstrap payload for this route.</div>
        </PortalFrame>
    );
}
