export interface ReactPreviewBootstrap {
    appName: string;
    subtitle: string | null;
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

export function buildPreviewBasename(basePath: string): string {
    return buildAppPath(basePath, 'react-preview');
}
