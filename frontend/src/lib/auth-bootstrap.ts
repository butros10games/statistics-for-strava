import {buildAppPath} from './bootstrap';

interface PortalBaseBootstrap {
    kind: 'login' | 'register' | 'forgot-password' | 'reset-password' | 'setup';
    appName: string;
    basePath: string;
}

export interface LoginPortalBootstrap extends PortalBaseBootstrap {
    kind: 'login';
    notices: {
        registered: boolean;
        passwordReset: boolean;
    };
    error: string | null;
    lastUsername: string;
    csrfToken: string;
    actions: {
        loginPath: string;
        registerPath: string;
        forgotPasswordPath: string;
    };
}

export interface RegisterPortalBootstrap extends PortalBaseBootstrap {
    kind: 'register';
    error: string | null;
    email: string;
    actions: {
        submitPath: string;
        loginPath: string;
    };
}

export interface ForgotPasswordPortalBootstrap extends PortalBaseBootstrap {
    kind: 'forgot-password';
    submitted: boolean;
    resetLink: string | null;
    actions: {
        submitPath: string;
        loginPath: string;
    };
}

export interface ResetPasswordPortalBootstrap extends PortalBaseBootstrap {
    kind: 'reset-password';
    error: string | null;
    token: string | null;
    actions: {
        submitPath: string | null;
        loginPath: string;
    };
}

export interface SetupPortalBootstrap extends PortalBaseBootstrap {
    kind: 'setup';
    user: {
        email: string;
    };
    strava: {
        connected: boolean;
    };
    actions: {
        accountSettingsPath: string;
        logoutPath: string;
        connectStravaPath: string;
    };
}

export type ReactPortalBootstrap =
    | LoginPortalBootstrap
    | RegisterPortalBootstrap
    | ForgotPasswordPortalBootstrap
    | ResetPasswordPortalBootstrap
    | SetupPortalBootstrap;

declare global {
    interface Window {
        statisticsForStravaAuth: ReactPortalBootstrap;
    }
}

export function getReactPortalBootstrap(): ReactPortalBootstrap {
    return window.statisticsForStravaAuth;
}

export function buildPortalPath(basePath: string, path: string | null): string {
    if (null === path) {
        return '#';
    }

    return buildAppPath(basePath, path);
}
