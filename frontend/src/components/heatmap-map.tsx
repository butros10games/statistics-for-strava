import {useEffect, useMemo, useRef, useState} from 'react';
import type {HeatmapPreviewConfig, HeatmapPreviewRoute} from '../lib/heatmap-preview-api';

declare global {
    interface Window {
        L?: any;
    }
}

interface HeatmapMapProps {
    config: HeatmapPreviewConfig;
    routes: HeatmapPreviewRoute[];
    selectedRouteIds: string[];
    onSelectRoutes: (routeIds: string[]) => void;
}

interface RoutePolylineEntry {
    route: HeatmapPreviewRoute;
    polyline: any;
    coordinates: Array<[number, number]>;
}

const CLICK_DISTANCE_THRESHOLD_IN_PIXELS = 12;

function determineMostActiveState(routes: HeatmapPreviewRoute[]): string | null {
    const stateCounts = new Map<string, number>();

    for (const route of routes) {
        if (!route.startLocation.state) {
            continue;
        }

        stateCounts.set(route.startLocation.state, (stateCounts.get(route.startLocation.state) ?? 0) + 1);
    }

    let activeState: string | null = null;
    let highestCount = 0;

    for (const [state, count] of stateCounts.entries()) {
        if (count > highestCount) {
            activeState = state;
            highestCount = count;
        }
    }

    return activeState;
}

function findNearbyRoutes(map: any, routes: RoutePolylineEntry[], latlng: {lat: number; lng: number}): HeatmapPreviewRoute[] {
    const L = window.L;

    if (!L) {
        return [];
    }

    const clickPoint = map.latLngToLayerPoint(latlng);

    return routes
        .map((entry) => {
            let minimumDistance = Number.POSITIVE_INFINITY;

            for (let index = 1; index < entry.coordinates.length; index++) {
                const start = map.latLngToLayerPoint(L.latLng(entry.coordinates[index - 1][0], entry.coordinates[index - 1][1]));
                const end = map.latLngToLayerPoint(L.latLng(entry.coordinates[index][0], entry.coordinates[index][1]));

                minimumDistance = Math.min(minimumDistance, L.LineUtil.pointToSegmentDistance(clickPoint, start, end));

                if (minimumDistance <= CLICK_DISTANCE_THRESHOLD_IN_PIXELS) {
                    break;
                }
            }

            return {
                route: entry.route,
                minimumDistance,
            };
        })
        .filter((entry) => entry.minimumDistance <= CLICK_DISTANCE_THRESHOLD_IN_PIXELS)
        .sort((left, right) => left.minimumDistance - right.minimumDistance)
        .map((entry) => entry.route);
}

export function HeatmapMap({config, routes, selectedRouteIds, onSelectRoutes}: HeatmapMapProps) {
    const containerRef = useRef<HTMLDivElement | null>(null);
    const mapRef = useRef<any | null>(null);
    const featureGroupRef = useRef<any | null>(null);
    const routeEntriesRef = useRef<RoutePolylineEntry[]>([]);
    const onSelectRoutesRef = useRef(onSelectRoutes);
    const [mapError, setMapError] = useState<string | null>(null);

    const defaultPolylineStyle = useMemo(
        () => ({
            color: config.polylineColor,
            weight: 1.5,
            opacity: 0.5,
            smoothFactor: 1,
        }),
        [config.polylineColor],
    );

    const inactivePolylineStyle = useMemo(
        () => ({
            weight: 0,
            opacity: 0,
        }),
        [],
    );

    useEffect(() => {
        onSelectRoutesRef.current = onSelectRoutes;
    }, [onSelectRoutes]);

    useEffect(() => {
        const container = containerRef.current;
        const L = window.L;

        if (!container || mapRef.current) {
            return;
        }

        if (!L) {
            setMapError('Leaflet preview assets are unavailable for this route.');

            return;
        }

        const map = L.map(container, {
            scrollWheelZoom: true,
            minZoom: 1,
            maxZoom: 21,
        });

        config.tileLayerUrls.forEach((tileLayerUrl) => {
            L.tileLayer(tileLayerUrl).addTo(map);
        });

        const featureGroup = L.featureGroup().addTo(map);
        const resizeObserver = new ResizeObserver(() => {
            map.invalidateSize();
        });

        resizeObserver.observe(container);

        map.on('click', (event: {latlng: {lat: number; lng: number}}) => {
            const nearbyRoutes = findNearbyRoutes(map, routeEntriesRef.current, event.latlng);
            onSelectRoutesRef.current(nearbyRoutes.map((route) => route.id));
        });

        mapRef.current = map;
        featureGroupRef.current = featureGroup;

        return () => {
            resizeObserver.disconnect();
            routeEntriesRef.current = [];
            featureGroup.clearLayers();
            map.remove();
            mapRef.current = null;
            featureGroupRef.current = null;
        };
    }, [config.tileLayerUrls]);

    useEffect(() => {
        const L = window.L;
        const map = mapRef.current;
        const featureGroup = featureGroupRef.current;

        if (!L || !map || !featureGroup) {
            return;
        }

        routeEntriesRef.current = [];
        featureGroup.clearLayers();

        if (0 === routes.length) {
            onSelectRoutesRef.current([]);

            return;
        }

        const boundsFeatureGroup = L.featureGroup();
        const preferredState = determineMostActiveState(routes);

        for (const route of routes) {
            const polyline = L.polyline(route.coordinates, defaultPolylineStyle).addTo(featureGroup);

            routeEntriesRef.current.push({
                route,
                polyline,
                coordinates: route.coordinates,
            });

            if (!preferredState || preferredState === route.startLocation.state) {
                L.polyline(route.coordinates).addTo(boundsFeatureGroup);
            }
        }

        const bounds = boundsFeatureGroup.getBounds().isValid()
            ? boundsFeatureGroup.getBounds()
            : featureGroup.getBounds();

        if (bounds.isValid()) {
            map.fitBounds(bounds, {
                padding: [24, 24],
            });
        }
    }, [defaultPolylineStyle, routes]);

    useEffect(() => {
        const selectedIds = new Set(selectedRouteIds);

        for (const entry of routeEntriesRef.current) {
            entry.polyline.setStyle(
                0 === selectedIds.size || selectedIds.has(entry.route.id)
                    ? defaultPolylineStyle
                    : inactivePolylineStyle,
            );
        }
    }, [defaultPolylineStyle, inactivePolylineStyle, selectedRouteIds]);

    return (
        <div className="relative">
            <div
                ref={containerRef}
                className={`h-[620px] overflow-hidden rounded-[28px] border border-white/70 bg-white/85 shadow-sm dark:border-gray-800 dark:bg-gray-950/40 ${config.enableGreyScale ? 'grayscale' : ''}`}
            />
            {mapError ? (
                <div className="absolute inset-4 flex items-center justify-center rounded-[24px] border border-rose-200 bg-rose-50/90 p-4 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                    {mapError}
                </div>
            ) : null}
        </div>
    );
}