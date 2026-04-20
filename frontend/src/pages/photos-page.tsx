import {useCallback, useEffect, useMemo, useState} from 'react';
import {Link} from 'react-router-dom';
import {StatCard} from '../components/stat-card';
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
            className={`group relative overflow-hidden rounded-[28px] border text-left shadow-[0_30px_80px_-55px_rgba(15,23,42,0.65)] transition ${image.orientation === 'PORTRAIT' ? 'row-span-2 min-h-[420px]' : 'min-h-[210px]'} ${isActive
                ? 'border-orange-500 ring-2 ring-orange-300/70 dark:border-orange-400 dark:ring-orange-500/30'
                : 'border-white/70 hover:border-orange-200 dark:border-gray-800 dark:hover:border-gray-700'}`}
        >
            <img
                src={previewUrl}
                alt={image.activityName}
                className="absolute inset-0 h-full w-full object-cover transition duration-500 group-hover:scale-[1.03]"
            />
            <div className="absolute inset-0 bg-[linear-gradient(180deg,rgba(15,23,42,0.08),rgba(15,23,42,0.72))]" />
            <div className="absolute inset-x-0 top-0 flex items-start justify-between gap-2 p-3">
                <span className="rounded-full border border-white/40 bg-black/30 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.22em] text-white backdrop-blur-sm">
                    {image.sportType.label}
                </span>
                <span className="rounded-full border border-white/30 bg-white/15 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-white backdrop-blur-sm">
                    {image.orientation === 'PORTRAIT' ? 'Portrait' : 'Landscape'}
                </span>
            </div>
            <div className="absolute inset-x-0 bottom-0 p-4 text-white">
                <div className="rounded-[24px] border border-white/20 bg-black/20 p-4 backdrop-blur-sm">
                    <div className="text-sm font-semibold leading-6">{image.activityName}</div>
                    <div className="mt-1 text-xs uppercase tracking-[0.2em] text-white/75">{formatDate(image.activityDate)}</div>
                    {image.countries.length > 0 ? (
                        <div className="mt-3 flex flex-wrap gap-2">
                            {image.countries.slice(0, 3).map((country) => (
                                <span key={country.value} className="rounded-full bg-white/15 px-2.5 py-1 text-[11px] font-medium text-white/90">
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
            <div className="glass-panel rounded-[32px] p-6 text-sm leading-7 text-gray-600 dark:text-gray-300">
                Pick a photo to inspect its activity context, orientation, and geography without disappearing into a modal rabbit hole.
            </div>
        );
    }

    const imageUrl = buildAppPath(bootstrap.basePath, image.imageUrl);

    return (
        <div className="glass-panel rounded-[32px] p-5 xl:sticky xl:top-28">
            <div className="section-kicker">Selected frame</div>
            <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">{image.activityName}</h2>
            <p className="mt-3 text-sm leading-7 text-gray-600 dark:text-gray-300">
                {image.sportType.label}
                {' · '}
                {formatDate(image.activityDate)}
                {' · '}
                {image.orientation === 'PORTRAIT' ? 'Portrait capture' : 'Landscape capture'}
            </p>

            <div className="mt-5 overflow-hidden rounded-[28px] border border-white/70 bg-white/80 shadow-sm dark:border-gray-800 dark:bg-gray-950/50">
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
                        className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                    >
                        Open activity
                        <span aria-hidden="true">↗</span>
                    </a>
                ) : null}
                <a
                    href={imageUrl}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                >
                    Open original image
                    <span aria-hidden="true">↗</span>
                </a>
            </div>

            <div className="mt-6 grid gap-4 md:grid-cols-2">
                <div className="rounded-[24px] border border-white/70 bg-white/85 p-4 dark:border-gray-800 dark:bg-gray-950/45">
                    <div className="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">Countries</div>
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
                <div className="rounded-[24px] border border-white/70 bg-white/85 p-4 dark:border-gray-800 dark:bg-gray-950/45">
                    <div className="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">Preview note</div>
                    <p className="mt-3 text-sm leading-7 text-gray-600 dark:text-gray-300">
                        This React slice keeps the gallery read-only, preserves existing activity links, and turns the old lightbox-first experience into a calmer browse-and-compare layout.
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

    const uniqueSportTypes = useMemo(
        () => new Set(filteredImages.map((image) => image.sportType.value)).size,
        [filteredImages],
    );

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
        <div className="space-y-8 pb-8">
            <section className="glass-panel overflow-hidden rounded-[36px] p-6 md:p-8">
                <div className="grid gap-8 xl:grid-cols-[1.12fr_0.88fr]">
                    <div>
                        <div className="section-kicker">Photos preview</div>
                        <h1 className="mt-5 max-w-3xl text-4xl font-semibold tracking-tight text-gray-900 dark:text-white md:text-5xl">
                            A calmer photo wall that keeps the memories front and center while React handles the filter logic.
                        </h1>
                        <p className="mt-5 max-w-2xl text-base leading-8 text-gray-600 dark:text-gray-300 md:text-lg">
                            The legacy gallery already has rich imagery and useful geography filters. This preview keeps it read-only, swaps modal-first browsing for a two-pane exploration flow, and makes filter state feel instant.
                        </p>
                        <div className="mt-6 flex flex-wrap gap-3">
                            <a
                                href={buildAppPath(bootstrap.basePath, 'photos')}
                                className="inline-flex items-center gap-2 rounded-2xl bg-gray-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200"
                            >
                                Open the current photos page
                                <span aria-hidden="true">↗</span>
                            </a>
                            <button
                                type="button"
                                onClick={reload}
                                className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                            >
                                Refresh preview data
                                <span aria-hidden="true">↻</span>
                            </button>
                        </div>
                    </div>
                    <div className="rounded-[32px] border border-amber-200 bg-[linear-gradient(135deg,rgba(255,251,235,0.98),rgba(255,255,255,0.92))] p-5 shadow-[0_45px_120px_-45px_rgba(15,23,42,0.65)] dark:border-amber-900/50 dark:bg-[linear-gradient(135deg,rgba(68,38,13,0.28),rgba(17,24,39,0.92))]">
                        <div className="text-xs font-semibold uppercase tracking-[0.24em] text-amber-700 dark:text-amber-200">Why this seam works</div>
                        <div className="mt-4 space-y-3 text-sm leading-7 text-gray-700 dark:text-gray-200">
                            {[
                                'The route is read-only and already structured around filter metadata plus a flat image collection.',
                                'It adds a visual browse pattern to the preview app without introducing write flows or server-side mutations.',
                                'It reuses the same navigation shell while proving the migration can handle gallery-style layouts, not just tables and charts.',
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
                <StatCard label="Visible photos" value={`${formatNumber(filteredImages.length)} / ${formatNumber(data?.summary.totalImages ?? 0)}`} hint="Filtered images update instantly in the preview wall." tone="orange" />
                <StatCard label="Countries" value={formatNumber(new Set(filteredImages.flatMap((image) => image.countries.map((country) => country.value))).size)} hint="Distinct country tags represented in the current result set." tone="emerald" />
                <StatCard label="Portrait frames" value={formatNumber(filteredImages.filter((image) => image.orientation === 'PORTRAIT').length)} hint="Useful when you want the tall-story version of the ride day." tone="blue" />
                <StatCard label="Sport types" value={formatNumber(uniqueSportTypes)} hint="Unique sport categories currently visible in the wall." tone="slate" />
            </section>

            <section className="glass-panel rounded-[32px] p-6">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div className="flex-1">
                        <div className="section-kicker">Filters and browse state</div>
                        <p className="mt-4 max-w-3xl text-sm leading-7 text-gray-600 dark:text-gray-300">
                            Defaults from the legacy photo wall still apply here, but now they are transparent, resettable, and easier to combine with orientation and free-text search.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-3 text-sm text-gray-500 dark:text-gray-400">
                        <button
                            type="button"
                            onClick={resetFilters}
                            className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 font-semibold text-gray-700 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-gray-600"
                        >
                            Reset filters
                            <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500 dark:bg-gray-800 dark:text-gray-300">{activeFilterCount}</span>
                        </button>
                        {data ? <span>Preview data refreshed {formatRequestedAt(data.requestedAt)}.</span> : null}
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
                            className="rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition focus:border-orange-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                        />
                    </label>
                    <label className="flex flex-col gap-2 text-sm font-medium text-gray-600 dark:text-gray-300">
                        <span>Country</span>
                        <select
                            value={filters.countryCode}
                            onChange={(event) => setFilters((current) => ({...current, countryCode: event.target.value}))}
                            className="rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition focus:border-orange-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                        >
                            <option value="">All countries</option>
                            {data?.filters.countries.map((option) => (
                                <option key={option.value} value={option.value}>{option.label}</option>
                            ))}
                        </select>
                    </label>
                    <div>
                        <div className="text-sm font-medium text-gray-600 dark:text-gray-300">Orientation</div>
                        <div className="mt-2 inline-flex rounded-2xl border border-gray-200 bg-gray-100 p-1 dark:border-gray-700 dark:bg-gray-900">
                            {[
                                {value: 'all', label: 'All'},
                                {value: 'LANDSCAPE', label: 'Landscape'},
                                {value: 'PORTRAIT', label: 'Portrait'},
                            ].map((option) => (
                                <button
                                    key={option.value}
                                    type="button"
                                    onClick={() => setFilters((current) => ({...current, orientation: option.value as OrientationFilter}))}
                                    className={`rounded-[18px] px-4 py-2 text-sm font-medium transition ${filters.orientation === option.value
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
                                        className={`rounded-full border px-4 py-2 text-sm font-medium transition ${active
                                            ? 'border-orange-500 bg-orange-50 text-orange-700 dark:border-orange-400 dark:bg-orange-950/40 dark:text-orange-200'
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
                <section className="glass-panel rounded-[32px] p-6 text-sm text-gray-600 dark:text-gray-300">
                    Loading photos preview… shaking the digital shoebox until the good memories fall out.
                </section>
            ) : null}

            {error ? (
                <section className="rounded-[32px] border border-rose-200 bg-rose-50/90 p-6 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-100">
                    {error}
                </section>
            ) : null}

            <section className="grid gap-6 xl:grid-cols-[minmax(0,0.92fr)_minmax(0,1.08fr)]">
                <SelectedPhotoPanel bootstrap={bootstrap} image={selectedImage} />

                <div className="space-y-4">
                    <div className="flex items-center justify-between">
                        <div>
                            <div className="section-kicker">Preview wall</div>
                            <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">Filtered gallery</h2>
                        </div>
                        <Link to="/roadmap" className="text-sm font-semibold text-strava-orange">
                            See migration roadmap →
                        </Link>
                    </div>

                    {0 === filteredImages.length ? (
                        <div className="glass-panel rounded-[32px] p-6 text-sm leading-7 text-gray-600 dark:text-gray-300">
                            No photos match the current filters. Try widening the lens a little.
                        </div>
                    ) : (
                        <div className="grid auto-rows-[210px] gap-4 md:grid-cols-2 2xl:grid-cols-3">
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