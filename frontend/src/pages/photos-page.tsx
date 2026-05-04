import {useCallback, useEffect, useMemo, useState} from 'react';
import {type ReactPreviewBootstrap, buildAppPath} from '../lib/bootstrap';
import {type PhotoPreviewImage, type PhotosPreviewResponse, fetchPhotosPreview} from '../lib/photos-preview-api';
import {useAsyncResource} from '../lib/use-async-resource';

interface PhotosPageProps {
    bootstrap: ReactPreviewBootstrap;
}

type OrientationFilter = 'all' | 'LANDSCAPE' | 'PORTRAIT';

interface PhotoFilterState {
    search: string;
    sportTypes: string[];
    countryCode: string;
    orientation: OrientationFilter;
}

const emptyFilters: PhotoFilterState = {
    search: '',
    sportTypes: [],
    countryCode: '',
    orientation: 'all',
};

function buildDefaultFilters(data: PhotosPreviewResponse | null): PhotoFilterState {
    if (!data) {
        return emptyFilters;
    }

    return {
        search: '',
        sportTypes: data.defaultEnabledFilters.sportTypes,
        countryCode: data.defaultEnabledFilters.countryCode ?? '',
        orientation: 'all',
    };
}

function formatDate(value: string | null): string {
    if (!value) {
        return 'Unknown date';
    }

    return new Intl.DateTimeFormat('en', {
        dateStyle: 'medium',
    }).format(new Date(value));
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

function matchesSearch(image: PhotoPreviewImage, search: string): boolean {
    if ('' === search) {
        return true;
    }

    const haystack = [
        image.activityName,
        image.sportType.label,
        ...image.countries.map((country) => country.label),
    ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();

    return haystack.includes(search);
}

function PhotoCard({
    bootstrap,
    image,
    isActive,
    onSelect,
}: {
    bootstrap: ReactPreviewBootstrap;
    image: PhotoPreviewImage;
    isActive: boolean;
    onSelect: (imageId: string) => void;
}) {
    const previewUrl = buildAppPath(bootstrap.basePath, image.imageUrl);

    return (
        <button
            type="button"
            onClick={() => onSelect(image.id)}
            className={`group relative overflow-hidden rounded-lg border text-left transition ${image.orientation === 'PORTRAIT' ? 'row-span-2 min-h-[420px]' : 'min-h-[210px]'} ${isActive
                ? 'border-orange-300 ring-2 ring-orange-300/60 dark:border-orange-400 dark:ring-orange-500/30'
                : 'border-gray-200 hover:border-orange-200 dark:border-gray-800 dark:hover:border-gray-700'}`}
        >
            <img
                src={previewUrl}
                alt={image.activityName}
                className="absolute inset-0 h-full w-full object-cover transition duration-500 group-hover:scale-[1.03]"
            />
            <div className="absolute inset-0 bg-[linear-gradient(180deg,rgba(15,23,42,0.02),rgba(15,23,42,0.6))]" />
            <div className="absolute inset-x-0 bottom-0 p-4 text-white">
                <div className="translate-y-6 border-t border-white/20 bg-gradient-to-t from-black/55 to-transparent px-0 pt-5 transition duration-300 group-hover:translate-y-0">
                    <div className="text-sm font-semibold leading-6">{image.activityName}</div>
                    <div className="mt-1 text-xs uppercase tracking-wide text-white/75">{formatDate(image.activityDate)}</div>
                    {image.countries.length > 0 ? (
                        <div className="mt-2 flex flex-wrap gap-2">
                            {image.countries.slice(0, 3).map((country) => (
                                <span key={country.value} className="rounded-full bg-white/15 px-2.5 py-1 text-[11px] font-medium text-white/90 backdrop-blur-sm">
                                    {country.label}
                                </span>
                            ))}
                        </div>
                    ) : null}
                </div>
            </div>
        </button>
    );
}

function SelectedPhotoPanel({
    bootstrap,
    image,
}: {
    bootstrap: ReactPreviewBootstrap;
    image: PhotoPreviewImage | null;
}) {
    if (!image) {
        return (
            <div className="ui-section text-sm leading-7 text-gray-600 dark:text-gray-300">
                Pick a photo to inspect its activity context, orientation, and geography without disappearing into a modal rabbit hole.
            </div>
        );
    }

    const imageUrl = buildAppPath(bootstrap.basePath, image.imageUrl);

    return (
        <div className="ui-section xl:sticky xl:top-28">
            <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Selected frame</h2>
            <div className="mt-1 text-base font-semibold text-gray-900 dark:text-white">{image.activityName}</div>
            <p className="mt-2 text-sm leading-7 text-gray-500 dark:text-gray-400">
                {image.sportType.label}
                {' · '}
                {formatDate(image.activityDate)}
                {' · '}
                {image.orientation === 'PORTRAIT' ? 'Portrait capture' : 'Landscape capture'}
            </p>

            <div className="mt-4 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-950/50">
                <img
                    src={imageUrl}
                    alt={image.activityName}
                    className={`w-full object-cover ${image.orientation === 'PORTRAIT' ? 'max-h-[720px]' : 'max-h-[520px]'}`}
                />
            </div>

            <div className="mt-5 flex flex-wrap gap-3">
                {image.activityUrl ? (
                    <a
                        href={image.activityUrl}
                        className="ui-button"
                    >
                        Open activity
                    </a>
                ) : null}
                <a
                    href={imageUrl}
                    target="_blank"
                    rel="noreferrer"
                    className="ui-button"
                >
                    Open original image
                </a>
            </div>

            <div className="mt-6 grid gap-4 md:grid-cols-2">
                <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-gray-900/45">
                    <div className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Countries</div>
                    {image.countries.length > 0 ? (
                        <div className="mt-3 flex flex-wrap gap-2">
                            {image.countries.map((country) => (
                                <span key={country.value} className="rounded-full border border-orange-200 bg-orange-50 px-3 py-1 text-xs font-medium text-orange-700 dark:border-orange-900/50 dark:bg-orange-950/30 dark:text-orange-200">
                                    {country.label}
                                </span>
                            ))}
                        </div>
                    ) : (
                        <p className="mt-3 text-sm text-gray-500 dark:text-gray-400">No route geography tags on this image yet.</p>
                    )}
                </div>
                <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-gray-900/45">
                    <div className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Image details</div>
                    <p className="mt-3 text-sm leading-7 text-gray-600 dark:text-gray-300">
                        {image.orientation === 'PORTRAIT' ? 'Portrait' : 'Landscape'} capture with activity-linked context preserved.
                    </p>
                </div>
            </div>
        </div>
    );
}

export function PhotosPage({bootstrap}: PhotosPageProps) {
    const [filters, setFilters] = useState<PhotoFilterState>(emptyFilters);
    const [selectedImageId, setSelectedImageId] = useState<string | null>(null);
    const [defaultsApplied, setDefaultsApplied] = useState(false);

    const loadPhotos = useCallback((signal: AbortSignal) => fetchPhotosPreview(bootstrap.basePath, signal), [bootstrap.basePath]);
    const {data, loading, error, reload} = useAsyncResource(loadPhotos);

    useEffect(() => {
        if (!data || defaultsApplied) {
            return;
        }

        setFilters(buildDefaultFilters(data));
        setDefaultsApplied(true);
    }, [data, defaultsApplied]);

    const sportTypeCounts = useMemo(() => {
        const counts = new Map<string, number>();

        for (const image of data?.images ?? []) {
            counts.set(image.sportType.value, (counts.get(image.sportType.value) ?? 0) + 1);
        }

        return counts;
    }, [data?.images]);

    const filteredImages = useMemo(() => {
        const search = filters.search.trim().toLowerCase();
        const selectedSportTypes = new Set(filters.sportTypes);

        return [...(data?.images ?? [])]
            .filter((image) => {
                if (!matchesSearch(image, search)) {
                    return false;
                }

                if (selectedSportTypes.size > 0 && !selectedSportTypes.has(image.sportType.value)) {
                    return false;
                }

                if (filters.countryCode && !image.countries.some((country) => country.value === filters.countryCode)) {
                    return false;
                }

                if ('all' !== filters.orientation && image.orientation !== filters.orientation) {
                    return false;
                }

                return true;
            })
            .sort((left, right) => {
                const rightTime = right.activityDate ? new Date(right.activityDate).getTime() : 0;
                const leftTime = left.activityDate ? new Date(left.activityDate).getTime() : 0;

                return rightTime - leftTime;
            });
    }, [data?.images, filters]);

    useEffect(() => {
        if (0 === filteredImages.length) {
            setSelectedImageId(null);

            return;
        }

        if (!selectedImageId || !filteredImages.some((image) => image.id === selectedImageId)) {
            setSelectedImageId(filteredImages[0].id);
        }
    }, [filteredImages, selectedImageId]);

    const selectedImage = useMemo(
        () => filteredImages.find((image) => image.id === selectedImageId) ?? null,
        [filteredImages, selectedImageId],
    );

    const activeFilterCount = useMemo(() => {
        return [
            filters.search,
            filters.countryCode,
            'all' !== filters.orientation ? filters.orientation : '',
            ...filters.sportTypes,
        ].filter(Boolean).length;
    }, [filters]);

    function toggleSportType(value: string) {
        setFilters((current) => ({
            ...current,
            sportTypes: current.sportTypes.includes(value)
                ? current.sportTypes.filter((entry) => entry !== value)
                : [...current.sportTypes, value],
        }));
    }

    function resetFilters() {
        setFilters(buildDefaultFilters(data ?? null));
    }

    return (
        <div className="space-y-6 pb-6">
            <section className="ui-section">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div className="min-w-0">
                        <h1 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white md:text-2xl">Photos</h1>
                        <p className="mt-1 max-w-3xl text-sm leading-7 text-gray-500 dark:text-gray-400">
                            Filterable photo wall with activity-linked context and country tags.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <a href={buildAppPath(bootstrap.basePath, 'photos')} className="ui-button">
                            Open classic photos page
                        </a>
                        <button type="button" onClick={reload} className="ui-button">
                            Refresh data
                        </button>
                    </div>
                </div>
            </section>

            <section className="ui-section">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div className="flex-1">
                        <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Filters</h2>
                        <p className="mt-1 max-w-3xl text-sm leading-7 text-gray-500 dark:text-gray-400">
                            Start from the default photo-wall filters, then refine by search, country, orientation, or sport type.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-3 text-sm text-gray-500 dark:text-gray-400">
                        <button
                            type="button"
                            onClick={resetFilters}
                            className="ui-button"
                        >
                            Reset filters
                            <span className="ui-pill">{activeFilterCount}</span>
                        </button>
                        <div className="ui-pill">{formatNumber(filteredImages.length)} photos</div>
                        {data ? <span>Refreshed {formatRequestedAt(data.requestedAt)}.</span> : null}
                    </div>
                </div>

                <div className="mt-6 grid gap-4 lg:grid-cols-[1.3fr_0.8fr_auto]">
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-600 dark:text-gray-300">
                        <span>Search activity, sport type, or country</span>
                        <input
                            type="search"
                            value={filters.search}
                            onChange={(event) => setFilters((current) => ({...current, search: event.target.value}))}
                            placeholder="Search the wall"
                            className="ui-input"
                        />
                    </label>
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-600 dark:text-gray-300">
                        <span>Country</span>
                        <select
                            value={filters.countryCode}
                            onChange={(event) => setFilters((current) => ({...current, countryCode: event.target.value}))}
                            className="ui-input"
                        >
                            <option value="">All countries</option>
                            {data?.filters.countries.map((option) => (
                                <option key={option.value} value={option.value}>{option.label}</option>
                            ))}
                        </select>
                    </label>
                    <div>
                        <div className="text-sm font-medium text-gray-600 dark:text-gray-300">Orientation</div>
                        <div className="mt-2 inline-flex rounded-lg border border-gray-200 bg-gray-100 p-1 dark:border-gray-700 dark:bg-gray-900">
                            {[
                                {value: 'all', label: 'All'},
                                {value: 'LANDSCAPE', label: 'Landscape'},
                                {value: 'PORTRAIT', label: 'Portrait'},
                            ].map((option) => (
                                <button
                                    key={option.value}
                                    type="button"
                                    onClick={() => setFilters((current) => ({...current, orientation: option.value as OrientationFilter}))}
                                    className={`rounded-md px-4 py-2 text-sm font-medium transition ${filters.orientation === option.value
                                        ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-white'
                                        : 'text-gray-500 dark:text-gray-400'}`}
                                >
                                    {option.label}
                                </button>
                            ))}
                        </div>
                    </div>
                </div>

                {data?.filters.sportTypes.length ? (
                    <div className="mt-6">
                        <div className="text-sm font-medium text-gray-600 dark:text-gray-300">Sport types</div>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {data.filters.sportTypes.map((option) => {
                                const active = filters.sportTypes.includes(option.value);

                                return (
                                    <button
                                        key={option.value}
                                        type="button"
                                        onClick={() => toggleSportType(option.value)}
                                        className={`rounded-lg border px-4 py-2 text-sm font-medium transition ${active
                                            ? 'border-orange-300 bg-orange-50 text-orange-700 dark:border-orange-400 dark:bg-orange-950/40 dark:text-orange-200'
                                            : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:text-white'}`}
                                    >
                                        {option.label}
                                        <span className={`ml-2 rounded-full px-2 py-0.5 text-[11px] ${active
                                            ? 'bg-orange-100 text-orange-700 dark:bg-orange-900/50 dark:text-orange-100'
                                            : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-300'}`}
                                        >
                                            {formatNumber(sportTypeCounts.get(option.value) ?? 0)}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                ) : null}
            </section>

            {loading && !data ? (
                <section className="ui-section text-sm text-gray-600 dark:text-gray-300">
                    Loading photos.
                </section>
            ) : null}

            {error ? (
                <section className="rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                    {error}
                </section>
            ) : null}

            <section className="grid gap-4 xl:grid-cols-[minmax(0,0.86fr)_minmax(0,1.14fr)]">
                <SelectedPhotoPanel bootstrap={bootstrap} image={selectedImage} />

                <div className="ui-section">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Photo wall</h2>
                            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Filtered gallery ordered by activity date.</p>
                        </div>
                    </div>

                    {0 === filteredImages.length ? (
                        <div className="mt-4 text-sm leading-7 text-gray-600 dark:text-gray-300">
                            No photos match the current filters.
                        </div>
                    ) : (
                        <div className="mt-4 grid auto-rows-[210px] gap-3 md:grid-cols-2 2xl:grid-cols-3">
                            {filteredImages.map((image) => (
                                <PhotoCard
                                    key={image.id}
                                    bootstrap={bootstrap}
                                    image={image}
                                    isActive={image.id === selectedImageId}
                                    onSelect={setSelectedImageId}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </section>
        </div>
    );
}