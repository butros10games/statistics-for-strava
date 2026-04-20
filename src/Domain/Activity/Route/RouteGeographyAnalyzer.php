<?php

declare(strict_types=1);

namespace App\Domain\Activity\Route;

use App\Infrastructure\Serialization\Json;
use App\Infrastructure\ValueObject\Geography\EncodedPolyline;
use Brick\Geo\Engine\GeosOpEngine;
use Brick\Geo\Exception\GeometryEngineException;
use Brick\Geo\Exception\InvalidGeometryException;
use Brick\Geo\Geometry;
use Brick\Geo\Io\GeoJson\Feature;
use Brick\Geo\Io\GeoJson\FeatureCollection;
use Brick\Geo\Io\GeoJsonReader;

final readonly class RouteGeographyAnalyzer
{
    private ?GeosOpEngine $engine;
    private GeoJsonReader $reader;
    /** @var array<string, Geometry|Feature|FeatureCollection> */
    private array $countriesGeometry;

    public function __construct()
    {
        $geosOpPath = $this->resolveGeosOpPath();
        $this->engine = null === $geosOpPath ? null : new GeosOpEngine($geosOpPath);
        $this->reader = new GeoJsonReader();
        $this->countriesGeometry = null === $this->engine ? [] : $this->buildCountriesGeometry();
    }

    public function isAvailable(): bool
    {
        return null !== $this->engine;
    }

    /**
     * @return array<string, Geometry|Feature|FeatureCollection>
     */
    private function buildCountriesGeometry(): array
    {
        $countriesGeometry = [];
        $rawCountriesGeoJson = Json::decode(file_get_contents(__DIR__.'/assets/countries-geography.json') ?: '{}');

        foreach ($rawCountriesGeoJson['features'] ?? [] as $feature) {
            if (!isset($feature['properties']['ISO_A2_EH'])) {
                continue; // @codeCoverageIgnore
            }
            $countryCode = $feature['properties']['ISO_A2_EH'];

            $countriesGeometry[$countryCode] = $this->reader->read(Json::encode([
                'type' => $feature['geometry']['type'],
                'coordinates' => $feature['geometry']['coordinates'],
            ]));
        }

        return $countriesGeometry;
    }

    /**
     * @return string[]
     */
    public function analyzeForPolyline(EncodedPolyline $polyline): array
    {
        $passedCountries = [];
        if (null === $this->engine) {
            return $passedCountries;
        }

        try {
            $routeLineString = $this->reader->read(Json::encode([
                'type' => 'LineString',
                'coordinates' => $polyline->decodeAndPairLngLat(),
            ]));
        } catch (InvalidGeometryException|GeometryEngineException) {
            // Given polyline is somehow not a valid LineString.
            return $passedCountries;
        }

        foreach ($this->countriesGeometry as $countryCode => $countryGeometry) {
            if (!$countryGeometry instanceof Geometry) {
                continue; // @codeCoverageIgnore
            }
            if (!$routeLineString instanceof Geometry) {
                continue; // @codeCoverageIgnore
            }
            try {
                $intersects = $this->engine->intersects($countryGeometry, $routeLineString);
            } catch (GeometryEngineException) {
                return [];
            }

            if (!$intersects) {
                continue;
            }
            $passedCountries[$countryCode] = $countryCode;
        }

        return array_values($passedCountries);
    }

    private function resolveGeosOpPath(): ?string
    {
        $candidates = [
            '/usr/bin/geosop',
            '/usr/local/bin/geosop',
            '/opt/homebrew/bin/geosop',
        ];

        $path = getenv('PATH');
        if (is_string($path) && '' !== $path) {
            foreach (explode(PATH_SEPARATOR, $path) as $directory) {
                if ('' === $directory) {
                    continue;
                }

                $candidates[] = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'geosop';
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
