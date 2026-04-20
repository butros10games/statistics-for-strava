<?php

declare(strict_types=1);

namespace App\Infrastructure\Localisation;

use Symfony\Component\Intl\Countries;

final class IntlCompatibility
{
    /**
     * @return array<string, string>
     */
    public static function getCountryNames(string $displayLocale): array
    {
        $countries = [];
        foreach (Countries::getCountryCodes() as $countryCode) {
            $countries[$countryCode] = Countries::getName($countryCode, $displayLocale);
        }

        natcasesort($countries);

        return $countries;
    }

    public static function getNumberFormattingLocale(string $locale): string
    {
        if (extension_loaded('intl')) {
            return $locale;
        }

        return 'en';
    }
}