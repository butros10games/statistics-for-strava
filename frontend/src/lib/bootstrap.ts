export interface ReactPreviewBootstrap {
    appName: string;
    subtitle: string | null;
    experience: 'preview' | 'live';
    routerBasePath: string;
    athlete: {
        name: string;
        initial: string;
    };
    profilePictureUrl: string | null;
    counts: {
        activities: number;
        challenges: number;
        photos: number;
        hasGear: boolean;
        hasBestEfforts: boolean;
    };
    basePath: string;
}

declare global {
    interface Window {
        statisticsForStrava: {
            appUrl: {
                basePath: string;
            };
            unitSystem: {
                name: string;
                paceSymbol: string;
                distanceSymbol: string;
                elevationSymbol: string;
            };
        };
        statisticsForStravaReact: ReactPreviewBootstrap;
    }
}

export function getReactBootstrap(): ReactPreviewBootstrap {
    return window.statisticsForStravaReact;
}

export function normaliseBasePath(basePath: string): string {
    const trimmed = basePath.replace(/^\/+|\/+$/g, '');

    return trimmed ? `/${trimmed}` : '';
}

export function buildAppPath(basePath: string, path = ''): string {
    const prefix = normaliseBasePath(basePath);
    const trimmedPath = path.replace(/^\/+/, '');

    if (!trimmedPath) {
        return prefix || '/';
    }

    return `${prefix}/${trimmedPath}`;
}

export function buildRouterBasename(routerBasePath: string): string | undefined {
    const trimmed = routerBasePath.trim();

    return '' === trimmed ? undefined : normaliseBasePath(trimmed);
}

export function buildRouterPath(routerBasePath: string, path = ''): string {
    const prefix = normaliseBasePath(routerBasePath);
    const trimmedPath = path.replace(/^\/+/, '');

    if (!trimmedPath) {
        return prefix || '/';
    }

    return `${prefix}/${trimmedPath}`;
}
