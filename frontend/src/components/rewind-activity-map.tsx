import {useEffect, useRef, useState} from 'react';
import {buildAppPath} from '../lib/bootstrap';
import {fetchJson} from '../lib/http';
import type {RewindPreviewMap} from '../lib/rewind-preview-api';

declare global {
    interface Window {
        L?: any;
    }
}

function resolveUrl(basePath: string, url: string | null): string | null {
    if (!url) {
        return null;
    }

    return url.startsWith('http://') || url.startsWith('https://')
        ? url
        : buildAppPath(basePath, url);
}

export function RewindActivityMap({
    basePath,
    map,
}: {
    basePath: string;
    map: RewindPreviewMap;
}) {
    const mapNodeRef = useRef<HTMLDivElement | null>(null);
    const [mapError, setMapError] = useState<string | null>(null);

    useEffect(() => {
        const L = window.L;
        const mapNode = mapNodeRef.current;

        if (!mapNode || !L) {
            setMapError('Leaflet preview assets are unavailable for this rewind card.');

            return;
        }

        setMapError(null);

        const leafletMap = L.map(mapNode, {
            attributionControl: false,
            zoomControl: false,
            scrollWheelZoom: false,
            dragging: false,
            doubleClickZoom: false,
            boxZoom: false,
            keyboard: false,
        });

        const bounds = map.bounds.map((point) => [point.lat, point.lng] as [number, number]);

        if (map.tileLayer) {
            L.tileLayer(map.tileLayer, {
                minZoom: map.minZoom,
                maxZoom: map.maxZoom,
            }).addTo(leafletMap);
        }

        const overlayImageUrl = resolveUrl(basePath, map.overlayImageUrl);
        if (overlayImageUrl && 2 === bounds.length) {
            L.imageOverlay(overlayImageUrl, bounds).addTo(leafletMap);
        }

        let disposed = false;

        fetchJson<[number, number][][]>(new URL(buildAppPath(basePath, map.polylineUrl), window.location.origin), {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
        })
            .then((polylines) => {
                if (disposed) {
                    return;
                }

                const firstPolyline = Array.isArray(polylines[0]) ? polylines[0] : [];

                if (firstPolyline.length > 0) {
                    const routeLine = L.polyline(firstPolyline, {
                        color: '#f26722',
                        weight: 4,
                        opacity: 0.94,
                    }).addTo(leafletMap);

                    leafletMap.fitBounds(routeLine.getBounds(), {
                        padding: [18, 18],
                    });
                } else if (2 === bounds.length) {
                    leafletMap.fitBounds(bounds, {
                        padding: [18, 18],
                    });
                } else {
                    leafletMap.setView([0, 0], map.minZoom);
                }
            })
            .catch(() => {
                if (!disposed) {
                    setMapError('Unable to load the route polyline for this activity.');
                }
            });

        return () => {
            disposed = true;
            leafletMap.remove();
        };
    }, [basePath, map]);

    if (mapError) {
        return (
            <div className="flex h-64 items-center justify-center rounded-[24px] border border-dashed border-gray-300 bg-gray-100/70 px-6 text-center text-sm leading-7 text-gray-500 dark:border-gray-700 dark:bg-gray-900/50 dark:text-gray-300">
                {mapError}
            </div>
        );
    }

    return <div ref={mapNodeRef} className="h-64 w-full rounded-[24px]" style={{backgroundColor: map.backgroundColor}} />;
}